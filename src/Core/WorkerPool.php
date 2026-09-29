<?php

declare(strict_types=1);

namespace Warship\Injector\Core;

use Warship\Injector\Config\Config;
use Warship\Injector\Recipient\CsvReader;
use Warship\Injector\Recipient\EmailListReader;
use Warship\Injector\Report\Reporter;
use Warship\Injector\Util\StopWatcher;

final class WorkerPool
{
    public const MAX_WORKERS = 200;

    public function __construct(private readonly Config $cfg)
    {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $start = microtime(true);
        $startIso = date('c');
        $reader = new CsvReader(
            $this->cfg->recipientsFile,
            $this->cfg->recipientsDelimiter,
            $this->cfg->recipientsHasHeader,
            $this->cfg->recipientsSkipLines,
        );
        $mainCount = $reader->countAll();
        $bccPool = [];
        if ($this->cfg->bccEnabled && $this->cfg->bccFilePath !== '') {
            $bccPool = EmailListReader::read($this->cfg->bccFilePath);
        }
        $maxBcc = $bccPool === [] ? 0 : min(count($bccPool), $mainCount * $this->cfg->bccPerEmail);
        $total = $mainCount + $maxBcc + $this->cfg->expectedBacktestCount($mainCount);
        $workers = min(self::MAX_WORKERS, max(1, $this->cfg->workers), max(1, $mainCount));

        $reporter = new Reporter($this->cfg->progressFile, $this->cfg->resultFile, $this->cfg->errorFile);
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
            'workers' => $workers,
        ]);

        if ($workers <= 1) {
            return (new Dispatcher($this->cfg))->runSingle();
        }

        $rateFile = $this->rateFilePath();
        @unlink($rateFile);
        putenv('WARSHIP_RATE_FILE=' . $rateFile);
        $_ENV['WARSHIP_RATE_FILE'] = $rateFile;

        $procs = [];
        try {
            for ($i = 0; $i < $workers; $i++) {
                $procs[] = $this->spawn($i, $workers);
            }
        } catch (\Throwable $e) {
            $this->stopChildren($procs);
            throw $e;
        }

        $stopWatcher = new StopWatcher($this->cfg->progressFile);
        $stopped = false;
        while ($this->anyRunning($procs)) {
            if (!$stopped && $stopWatcher->shouldStop()) {
                $stopped = true;
                $this->touchStop();
            }
            $this->publishMerged($reporter, $procs, $total, $start, $startIso, 'running', $workers);
            usleep(200_000);
        }
        foreach ($procs as $p) {
            if (is_resource($p['proc'])) {
                proc_close($p['proc']);
            }
        }

        $merged = $this->mergeChildren($procs);
        $duration = max(0.001, microtime(true) - $start);
        $status = $stopped || ($merged['status'] ?? '') === 'stopped' ? 'stopped' : 'completed';
        $processed = (int) $merged['processed'];
        $result = [
            'job_id' => $this->cfg->jobId,
            'status' => $status,
            'total' => $total,
            'success' => (int) $merged['success'],
            'failed' => (int) $merged['failed'],
            'start_time' => $startIso,
            'end_time' => date('c'),
            'duration_seconds' => round($duration, 3),
            'average_rate' => round($processed / $duration, 2),
            'workers' => $workers,
        ];
        $this->writeProgress($reporter, [
            'job_id' => $this->cfg->jobId,
            'status' => $status,
            'total' => $total,
            'processed' => $processed,
            'success' => $result['success'],
            'failed' => $result['failed'],
            'rate' => $result['average_rate'],
            'eta_seconds' => 0,
            'start_time' => $startIso,
            'update_time' => date('c'),
            'workers' => $workers,
        ]);
        $reporter->saveResult($result, $merged['errors']);
        @unlink($rateFile);
        return $result;
    }

    /** @param list<array{proc:resource|false,progress:string,result:string,error:string}> $procs */
    private function publishMerged(
        Reporter $reporter,
        array $procs,
        int $total,
        float $start,
        string $startIso,
        string $status,
        int $workers,
    ): void {
        $merged = $this->mergeChildren($procs);
        $processed = (int) $merged['processed'];
        $elapsed = max(0.001, microtime(true) - $start);
        $rate = $processed / $elapsed;
        $this->writeProgress($reporter, [
            'job_id' => $this->cfg->jobId,
            'status' => $status,
            'total' => $total,
            'processed' => $processed,
            'success' => (int) $merged['success'],
            'failed' => (int) $merged['failed'],
            'rate' => round($rate, 2),
            'eta_seconds' => $processed > 0 ? (int) round(($total - $processed) / max(0.01, $rate)) : 0,
            'start_time' => $startIso,
            'update_time' => date('c'),
            'workers' => $workers,
        ]);
    }

    /**
     * @param list<array{proc:resource|false,progress:string,result:string,error:string}> $procs
     * @return array{processed:int,success:int,failed:int,status:string,errors:list<array{email:string,error:string,line:int}>}
     */
    private function mergeChildren(array $procs): array
    {
        $processed = 0;
        $success = 0;
        $failed = 0;
        $status = 'completed';
        $errors = [];
        foreach ($procs as $p) {
            $prog = $this->readJson($p['progress']);
            $res = $this->readJson($p['result']);
            $src = $res !== [] ? $res : $prog;
            $processed += (int) ($src['processed'] ?? (($src['success'] ?? 0) + ($src['failed'] ?? 0)));
            $success += (int) ($src['success'] ?? 0);
            $failed += (int) ($src['failed'] ?? 0);
            if (($src['status'] ?? '') === 'stopped') {
                $status = 'stopped';
            }
            if (is_file($p['error'])) {
                foreach (file($p['error'], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (preg_match('/^Line (\d+): (.+?) - (.+)$/', $line, $m)) {
                        $errors[] = ['line' => (int) $m[1], 'email' => $m[2], 'error' => $m[3]];
                    }
                }
            }
        }
        return compact('processed', 'success', 'failed', 'status', 'errors');
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** @return array{proc:resource,progress:string,result:string,error:string} */
    private function spawn(int $index, int $shards): array
    {
        $cmd = $this->childCommand($index, $shards);
        $progress = $this->shardPath($this->cfg->progressFile, $index);
        $result = $this->shardPath($this->cfg->resultFile, $index);
        $error = $this->shardPath($this->cfg->errorFile, $index);
        foreach ([$progress, $result, $error] as $f) {
            if ($f !== '' && is_file($f)) {
                @unlink($f);
            }
        }
        $log = $this->shardPath($this->taskLogPath(), $index);
        $this->ensureDir($log);
        $spec = [
            0 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $log !== '' ? $log : (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), 'a'],
            2 => ['file', $log !== '' ? $log : (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), 'a'],
        ];
        $proc = proc_open($cmd, $spec, $pipes, null, null);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start worker ' . $index);
        }
        return compact('proc', 'progress', 'result', 'error');
    }

    private function childCommand(int $index, int $shards): string
    {
        if ($this->cfg->configPath === '' || !is_file($this->cfg->configPath)) {
            throw new \RuntimeException('Missing config path for worker spawn');
        }
        $bin = $this->launcher();
        return sprintf(
            '%s send --config %s --shard %d --shards %d',
            $bin,
            escapeshellarg($this->cfg->configPath),
            $index,
            $shards
        );
    }

    private function launcher(): string
    {
        $argv0 = (string) ($_SERVER['argv'][0] ?? '');
        if ($argv0 !== '') {
            $resolved = realpath($argv0) ?: $argv0;
            if (is_file($resolved) && !str_ends_with(strtolower($resolved), '.php')) {
                return escapeshellarg($resolved);
            }
        }
        $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        $script = $script !== '' ? (realpath($script) ?: $script) : '';
        if ($script === '' || !is_file($script)) {
            throw new \RuntimeException('Cannot locate injector entry');
        }
        return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    }

    /** @param list<array{proc:resource|false}> $procs */
    private function anyRunning(array $procs): bool
    {
        foreach ($procs as $p) {
            if (!is_resource($p['proc'])) {
                continue;
            }
            $st = proc_get_status($p['proc']);
            if (!empty($st['running'])) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{proc:resource|false}> $procs */
    private function stopChildren(array $procs): void
    {
        $this->touchStop();
        foreach ($procs as $p) {
            if (is_resource($p['proc'])) {
                proc_terminate($p['proc']);
                proc_close($p['proc']);
            }
        }
    }

    private function touchStop(): void
    {
        $progress = $this->cfg->progressFile;
        if ($progress === '') {
            return;
        }
        file_put_contents(dirname($progress) . DIRECTORY_SEPARATOR . 'STOP', '1');
    }

    private function rateFilePath(): string
    {
        $base = $this->cfg->progressFile !== '' ? dirname($this->cfg->progressFile) : sys_get_temp_dir();
        return $base . DIRECTORY_SEPARATOR . 'rate.' . preg_replace('/[^A-Za-z0-9._-]/', '_', $this->cfg->jobId) . '.lock';
    }

    private function taskLogPath(): string
    {
        if ($this->cfg->progressFile !== '') {
            return dirname($this->cfg->progressFile) . DIRECTORY_SEPARATOR . 'worker.log';
        }
        return '';
    }

    private function shardPath(string $path, int $index): string
    {
        if ($path === '') {
            return '';
        }
        $dir = dirname($path);
        $name = basename($path);
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return $dir . DIRECTORY_SEPARATOR . $name . '.w' . $index;
        }
        return $dir . DIRECTORY_SEPARATOR . substr($name, 0, $dot) . '.w' . $index . substr($name, $dot);
    }

    private function ensureDir(string $file): void
    {
        if ($file === '') {
            return;
        }
        $dir = dirname($file);
        if ($dir !== '' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /** @param array<string, mixed> $progress */
    private function writeProgress(Reporter $reporter, array $progress): void
    {
        $reporter->updateProgress($progress);
    }
}
