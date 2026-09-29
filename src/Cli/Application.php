<?php

declare(strict_types=1);

namespace Warship\Injector\Cli;

use Warship\Injector\Config\Config;
use Warship\Injector\Config\Loader;
use Warship\Injector\Core\Dispatcher;
use Warship\Injector\Core\WorkerPool;

final class Application
{
    private const VERSION = '1.0.0';

    /** @param list<string> $argv */
    public static function run(array $argv): int
    {
        $args = array_slice($argv, 1);
        if ($args === [] || in_array($args[0], ['-h', '--help', 'help'], true)) {
            self::printHelp();
            return 0;
        }

        if ($args[0] === 'version') {
            echo 'phpmailer-injector version ' . self::VERSION . PHP_EOL;
            return 0;
        }

        if ($args[0] !== 'send') {
            fwrite(STDERR, "Unknown command: {$args[0]}\n");
            self::printHelp();
            return 1;
        }

        $opts = self::parseSendOptions($args);
        if ($opts['config'] === null) {
            fwrite(STDERR, "Missing required flag: --config\n");
            return 1;
        }

        try {
            $cfg = Loader::load($opts['config']);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Failed to load config: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }

        $cfg->configPath = $opts['config'];
        $cfg->shardIndex = $opts['shard'];
        $cfg->shardCount = $opts['shards'];
        if ($cfg->shardCount > 1) {
            $cfg->workers = 1;
            self::applyShardOutputs($cfg);
        } else {
            $cfg->workers = min(WorkerPool::MAX_WORKERS, max(1, $cfg->workers));
        }

        $validation = self::validate($cfg);
        if ($validation !== null) {
            fwrite(STDERR, $validation . PHP_EOL);
            return 1;
        }

        if ($cfg->shardCount <= 1) {
            self::printStartInfo($cfg);
        }

        $dispatcher = new Dispatcher($cfg);
        $result = $dispatcher->run();

        if ($cfg->shardCount <= 1) {
            self::printResult($result);
        }
        return ($result['failed'] ?? 0) > 0 ? 2 : 0;
    }

    /**
     * @param list<string> $args
     * @return array{config:?string,shard:int,shards:int}
     */
    private static function parseSendOptions(array $args): array
    {
        $out = ['config' => null, 'shard' => 0, 'shards' => 1];
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if ($arg === '--config' || $arg === '-c') {
                $out['config'] = $args[$i + 1] ?? null;
                $i++;
                continue;
            }
            if (str_starts_with($arg, '--config=')) {
                $out['config'] = substr($arg, 9) ?: null;
                continue;
            }
            if ($arg === '--shard') {
                $out['shard'] = max(0, (int) ($args[$i + 1] ?? 0));
                $i++;
                continue;
            }
            if (str_starts_with($arg, '--shard=')) {
                $out['shard'] = max(0, (int) substr($arg, 8));
                continue;
            }
            if ($arg === '--shards') {
                $out['shards'] = max(1, (int) ($args[$i + 1] ?? 1));
                $i++;
                continue;
            }
            if (str_starts_with($arg, '--shards=')) {
                $out['shards'] = max(1, (int) substr($arg, 9));
            }
        }
        if ($out['shard'] >= $out['shards']) {
            $out['shard'] = 0;
        }
        return $out;
    }

    private static function applyShardOutputs(Config $cfg): void
    {
        $n = $cfg->shardIndex;
        $cfg->progressFile = self::shardPath($cfg->progressFile, $n);
        $cfg->resultFile = self::shardPath($cfg->resultFile, $n);
        $cfg->errorFile = self::shardPath($cfg->errorFile, $n);
    }

    private static function shardPath(string $path, int $index): string
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

    private static function validate(Config $cfg): ?string
    {
        if ($cfg->recipientsFile === '') {
            return 'Recipients file is required';
        }
        if (!is_file($cfg->recipientsFile)) {
            return "Recipients file not found: {$cfg->recipientsFile}";
        }
        if ($cfg->emailTemplatePath === '') {
            return 'Template path is required';
        }
        if (!is_file($cfg->emailTemplatePath)) {
            return "Template file not found: {$cfg->emailTemplatePath}";
        }
        if ($cfg->senderFromAddress === '') {
            return 'Sender from_address is required';
        }
        if ($cfg->smtpEnabled && $cfg->smtpHost === '') {
            return 'SMTP host is required when smtp.enabled=true';
        }
        return null;
    }

    private static function printHelp(): void
    {
        echo <<<TXT
phpmailer-injector - Haraka SMTP mail injector (PHPMailer)

Usage:
  injector send --config /path/to/config.yaml
  injector version
  injector --help

Options:
  --config, -c   Path to YAML config file (required for send)
  --shard        Worker shard index (internal)
  --shards       Worker shard count (internal)

TXT;
    }

    private static function printStartInfo(Config $cfg): void
    {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "                 PHPMailer Injector 启动\n";
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "  任务ID:       {$cfg->jobId}\n";
        echo "  收件人文件:   {$cfg->recipientsFile}\n";
        echo "  模板文件:     {$cfg->emailTemplatePath}\n";
        echo "  发件人:       {$cfg->senderFromName} <{$cfg->senderFromAddress}>\n";
        echo "  主题:         {$cfg->emailSubject}\n";
        echo "  并发线程:     {$cfg->workers}\n";
        if ($cfg->rateLimit > 0) {
            echo "  速率限制:     {$cfg->rateLimit}/秒\n";
        } else {
            echo "  速率限制:     无限制\n";
        }
        echo "  SMTP:         {$cfg->smtpHost}:{$cfg->smtpPort}\n";
        if ($cfg->backtestActive()) {
            echo "  回测:         每 {$cfg->backtestFrequency} 封 → {$cfg->backtestEmail}\n";
        }
        echo "═══════════════════════════════════════════════════════════════\n\n";
    }

    /** @param array<string, mixed> $result */
    private static function printResult(array $result): void
    {
        echo "\n═══════════════════════════════════════════════════════════════\n";
        echo "                      发送完成\n";
        echo "═══════════════════════════════════════════════════════════════\n";
        echo '  总数:         ' . ($result['total'] ?? 0) . "\n";
        echo '  成功:         ' . ($result['success'] ?? 0) . "\n";
        echo '  失败:         ' . ($result['failed'] ?? 0) . "\n";
        echo '  耗时:         ' . number_format((float) ($result['duration_seconds'] ?? 0), 2) . " 秒\n";
        echo '  平均速率:     ' . number_format((float) ($result['average_rate'] ?? 0), 2) . " 封/秒\n";
        echo "═══════════════════════════════════════════════════════════════\n";
    }
}
