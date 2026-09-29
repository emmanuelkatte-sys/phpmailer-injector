<?php

declare(strict_types=1);

namespace Warship\Injector\Util;

final class SharedRateLimiter
{
    public function __construct(
        private readonly string $path,
        private readonly int $ratePerSecond,
    ) {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function acquire(): void
    {
        if ($this->ratePerSecond <= 0) {
            return;
        }
        $interval = 1.0 / $this->ratePerSecond;
        $fp = fopen($this->path, 'c+');
        if ($fp === false) {
            return;
        }
        try {
            while (true) {
                if (!flock($fp, LOCK_EX)) {
                    return;
                }
                rewind($fp);
                $raw = trim((string) stream_get_contents($fp));
                $next = is_numeric($raw) ? (float) $raw : 0.0;
                $now = microtime(true);
                if ($now >= $next) {
                    rewind($fp);
                    ftruncate($fp, 0);
                    fwrite($fp, sprintf('%.6F', $now + $interval));
                    fflush($fp);
                    flock($fp, LOCK_UN);
                    return;
                }
                $waitUs = (int) max(1000, ($next - $now) * 1_000_000);
                flock($fp, LOCK_UN);
                usleep($waitUs);
            }
        } finally {
            fclose($fp);
        }
    }
}
