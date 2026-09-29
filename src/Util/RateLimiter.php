<?php

declare(strict_types=1);

namespace Warship\Injector\Util;

final class RateLimiter
{
    private float $nextAllowed = 0.0;

    public function __construct(private readonly int $ratePerSecond)
    {
    }

    public function acquire(): void
    {
        if ($this->ratePerSecond <= 0) {
            return;
        }

        $interval = 1.0 / $this->ratePerSecond;
        $now = microtime(true);
        if ($now < $this->nextAllowed) {
            usleep((int) (($this->nextAllowed - $now) * 1_000_000));
            $now = microtime(true);
        }
        $this->nextAllowed = $now + $interval;
    }
}
