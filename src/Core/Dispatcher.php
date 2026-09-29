<?php

declare(strict_types=1);

namespace Warship\Injector\Core;

use Warship\Injector\Config\Config;
use Warship\Injector\Mail\MailSender;
use Warship\Injector\Recipient\CsvReader;
use Warship\Injector\Recipient\EmailListReader;
use Warship\Injector\Recipient\Recipient;
use Warship\Injector\Report\Reporter;
use Warship\Injector\Util\RateLimiter;
use Warship\Injector\Util\SharedRateLimiter;
use Warship\Injector\Util\StopWatcher;
use Warship\Injector\Variable\Processor;

final class Dispatcher
{
    /** @var list<array{email:string,error:string,line:int}> */
    private array $errors = [];

    public function __construct(private readonly Config $cfg)
    {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        if ($this->cfg->shardCount <= 1 && $this->cfg->workers > 1) {
            return (new WorkerPool($this->cfg))->run();
        }
        return $this->runSingle();
    }

    /** @return array<string, mixed> */
    public function runSingle(): array
    {
        $start = microtime(true);
        $startIso = date('c');

        $reporter = new Reporter(
            $this->cfg->progressFile,
            $this->cfg->resultFile,
            $this->cfg->errorFile,
        );

        $reader = new CsvReader(
            $this->cfg->recipientsFile,
            $this->cfg->recipientsDelimiter,
            $this->cfg->recipientsHasHeader,
            $this->cfg->recipientsSkipLines,
            $this->cfg->shardIndex,
            $this->cfg->shardCount,
        );

        $mainCount = $reader->countAll();
        $bccPool = [];
        if ($this->cfg->bccEnabled && $this->cfg->bccFilePath !== '') {
            $bccPool = EmailListReader::read($this->cfg->bccFilePath);
        }

        $maxBcc = 0;
        if ($bccPool !== []) {
            $maxBcc = min(count($bccPool), $mainCount * $this->cfg->bccPerEmail);
        }
        $total = $mainCount + $maxBcc + $this->cfg->expectedBacktestCount(
            $mainCount,
            $this->cfg->shardIndex,
            $this->cfg->shardCount,
        );

        $processed = 0;
        $success = 0;
        $failed = 0;
        $stopped = false;

        $variables = new Processor($this->cfg);
        $sender = new MailSender($this->cfg, $variables);
        $limiter = $this->makeLimiter();
        $stopWatcher = new StopWatcher($this->cfg->progressFile);
        $lastProgress = 0.0;

        $this->writeProgress($reporter, [
            'job_id' => $this->cfg->jobId,
            'status' => 'running',
            'total' => $total,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'rate' => 0.0,
            'eta_seconds' => 0,
            'start_time' => $startIso,
            'update_time' => date('c'),
        ]);

        foreach ($reader as $recipient) {
            if ($stopWatcher->shouldStop()) {
                $stopped = true;
                break;
            }

            if (!$this->sendOne($sender, $limiter, $stopWatcher, $recipient, null, $success, $failed, $processed)) {
                $stopped = true;
                break;
            }

            if ($this->cfg->shouldInsertAfterIndex($recipient->index)) {
                $probe = new Recipient($this->cfg->backtestEmail, lineNumber: 0, index: $recipient->index, isBacktest: true);
                if (!$this->sendOne($sender, $limiter, $stopWatcher, $probe, $this->cfg->backtestEmail, $success, $failed, $processed)) {
                    $stopped = true;
                    break;
                }
            }

            if ($bccPool !== []) {
                $bccBase = $recipient->index * $this->cfg->bccPerEmail;
                for ($n = 0; $n < $this->cfg->bccPerEmail; $n++) {
                    $bccIdx = $bccBase + $n;
                    if ($bccIdx >= $maxBcc) {
                        break;
                    }
                    $bccEmail = $bccPool[$bccIdx];
                    $bccRecipient = new Recipient($bccEmail, lineNumber: 0, index: $bccIdx);
                    if (!$this->sendOne($sender, $limiter, $stopWatcher, $bccRecipient, $bccEmail, $success, $failed, $processed)) {
                        $stopped = true;
                        break 2;
                    }
                }
            }

            $now = microtime(true);
            if ($now - $lastProgress >= $this->cfg->progressIntervalSec) {
                $elapsed = max(0.001, $now - $start);
                $this->writeProgress($reporter, [
                    'job_id' => $this->cfg->jobId,
                    'status' => 'running',
                    'total' => $total,
                    'processed' => $processed,
                    'success' => $success,
                    'failed' => $failed,
                    'rate' => round($processed / $elapsed, 2),
                    'eta_seconds' => $processed > 0 ? (int) round(($total - $processed) / max(0.01, $processed / $elapsed)) : 0,
                    'start_time' => $startIso,
                    'update_time' => date('c'),
                ]);
                $lastProgress = $now;
            }
        }

        $end = microtime(true);
        $duration = $end - $start;
        $status = $stopped ? 'stopped' : 'completed';

        $result = [
            'job_id' => $this->cfg->jobId,
            'status' => $status,
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'start_time' => $startIso,
            'end_time' => date('c'),
            'duration_seconds' => round($duration, 3),
            'average_rate' => $duration > 0 ? round($processed / $duration, 2) : 0.0,
        ];

        $this->writeProgress($reporter, [
            'job_id' => $this->cfg->jobId,
            'status' => $status,
            'total' => $total,
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'rate' => $result['average_rate'],
            'eta_seconds' => 0,
            'start_time' => $startIso,
            'update_time' => date('c'),
        ]);

        $reporter->saveResult($result, $this->errors);
        return $result;
    }

    private function sendOne(
        MailSender $sender,
        RateLimiter|SharedRateLimiter $limiter,
        StopWatcher $stopWatcher,
        Recipient $recipient,
        ?string $overrideTo,
        int &$success,
        int &$failed,
        int &$processed,
    ): bool {
        if ($stopWatcher->shouldStop()) {
            return false;
        }
        $limiter->acquire();
        $this->maybeSleepInterval();
        $to = $overrideTo ?? $recipient->email;
        try {
            $sender->send($recipient, $overrideTo);
            $success++;
            $this->logSendAttempt($to, true, '');
        } catch (\Throwable $e) {
            $failed++;
            $msg = $e->getMessage();
            $this->errors[] = [
                'email' => $to,
                'error' => $msg,
                'line' => $recipient->lineNumber,
            ];
            $this->logSendAttempt($to, false, $msg);
            $this->logFailedEmail($to);
        }
        $processed++;
        return true;
    }

    private function logSendAttempt(string $email, bool $ok, string $error): void
    {
        if (!$this->cfg->loggingSaveLog) {
            return;
        }
        $dir = $this->cfg->loggingDirectory ?: 'logs';
        if (!str_starts_with($dir, '/') && $this->cfg->configPath !== '') {
            $dir = dirname($this->cfg->configPath) . '/' . $dir;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            $ok ? 'OK' : 'FAIL',
            $email,
            $error !== '' ? " - {$error}" : ''
        );
        @file_put_contents($dir . '/send.log', $line, FILE_APPEND);
    }

    private function logFailedEmail(string $email): void
    {
        if (!$this->cfg->loggingSaveFailed) {
            return;
        }
        $dir = $this->cfg->loggingDirectory ?: 'logs';
        if (!str_starts_with($dir, '/') && $this->cfg->configPath !== '') {
            $dir = dirname($this->cfg->configPath) . '/' . $dir;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($dir . '/failed_emails.txt', $email . "\n", FILE_APPEND);
    }

    private function makeLimiter(): RateLimiter|SharedRateLimiter
    {
        $shared = (string) (getenv('WARSHIP_RATE_FILE') ?: ($_ENV['WARSHIP_RATE_FILE'] ?? ''));
        if ($shared !== '' && $this->cfg->rateLimit > 0) {
            return new SharedRateLimiter($shared, $this->cfg->rateLimit);
        }
        return new RateLimiter($this->cfg->rateLimit);
    }

    private function maybeSleepInterval(): void
    {
        $min = $this->cfg->minIntervalMs;
        $max = $this->cfg->maxIntervalMs;
        if ($max <= 0 && $min <= 0) {
            return;
        }
        if ($max < $min) {
            $max = $min;
        }
        $ms = $min === $max ? $min : random_int($min, $max);
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /** @param array<string, mixed> $progress */
    private function writeProgress(Reporter $reporter, array $progress): void
    {
        $reporter->updateProgress($progress);
    }
}
