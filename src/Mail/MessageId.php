<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

use Warship\Injector\Config\Config;

final class MessageId
{
    public static function generate(Config $cfg, string $domain, string $serverHostname = ''): string
    {
        $d = self::host($domain);
        if ($cfg->headerMessageIdDomainMode === 'main') {
            $d = self::rootDomain($d);
        }
        $profile = strtolower(trim($cfg->headerClientProfile));
        return match ($profile) {
            'outlook' => sprintf('%s@%s', self::uuidV4(true), $d),
            'apple_mail', 'iphone' => sprintf('%s@%s', self::uuidV4(false), $d),
            'thunderbird' => sprintf('%s@%s', self::uuidV4(false), $d),
            default => self::timestampDigits($d),
        };
    }

    /** {YYYYMMDDHHmmss}{20 digits}@{domain} */
    private static function timestampDigits(string $domain): string
    {
        $ts = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('YmdHis');
        $num = '';
        for ($i = 0; $i < 20; $i++) {
            $num .= (string) random_int(0, 9);
        }
        return $ts . $num . '@' . $domain;
    }

    public static function uuidV4(bool $upper = false): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        return $upper ? strtoupper($uuid) : strtolower($uuid);
    }

    public static function phpmailerUniqueId(): string
    {
        $bytes = random_bytes(32);
        return str_replace(['=', '+', '/'], '', base64_encode(hash('sha256', $bytes, true)));
    }

    private static function host(string $value): string
    {
        $at = strpos($value, '@');
        if ($at !== false) {
            $value = substr($value, $at + 1);
        }
        $value = strtolower(trim($value));
        return $value !== '' ? $value : 'localhost';
    }

    /** example.com ← sub.example.com */
    private static function rootDomain(string $host): string
    {
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
        }
        return $host;
    }
}
