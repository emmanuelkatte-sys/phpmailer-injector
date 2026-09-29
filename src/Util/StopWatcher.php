<?php

declare(strict_types=1);

namespace Warship\Injector\Util;

final class StopWatcher
{
    public function __construct(private readonly string $progressFile)
    {
    }

    public function shouldStop(): bool
    {
        if ($this->progressFile === '') {
            return false;
        }
        $stopFile = dirname($this->progressFile) . DIRECTORY_SEPARATOR . 'STOP';
        return is_file($stopFile);
    }
}
