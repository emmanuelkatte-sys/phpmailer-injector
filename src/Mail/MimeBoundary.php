<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

/**
 * PHPMailer 默认 MIME boundary：一次 uniqueid，b1=_ / b2=_ / b3=_ 拼同一串。
 */
final class MimeBoundary
{
    public static function generate(int $part, string $uniqueid): string
    {
        $part = max(1, min(3, $part));
        return sprintf('b%d=_%s', $part, $uniqueid);
    }
}
