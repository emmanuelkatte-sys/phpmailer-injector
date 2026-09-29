<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

use Warship\Injector\Config\Config;

/**
 * 邮件头适配与生成工具：
 * 提供 List-Unsubscribe、Received、Content-Language、Accept-Language、Importance、X-Mailer 等标准化生成。
 */
final class HarakaCompat
{
    /** @var list<string> */
    private const MOBILE_HELOS = [
        'smtpclient.apple', 'iphone.local', 'iphone', 'iphone.lan',
        'android-mail', 'android.local', 'mail-client',
    ];

    /** @var list<string> */
    private const CARRIER_DOMAINS = [
        'docomo.ne.jp', 'ezweb.ne.jp', 'softbank.ne.jp', 'spmode.ne.jp', 'm-zone.jp',
    ];

    /** @var list<string> */
    private const MTA_LABELS = [
        'Postfix', 'Sendmail 8.15.2', 'Exim 4.96',
    ];

    public static function resolveXMailer(Config $cfg): string
    {
        if (!$cfg->headerXMailer) {
            return ' ';
        }

        $profile = strtolower(trim($cfg->headerClientProfile));
        return match ($profile) {
            'apple_mail' => 'Apple Mail (2.3654.120.0.1)',
            'thunderbird' => 'Thunderbird/115.8.0',
            'iphone' => 'iPhone Mail (20G75)',
            default => 'Microsoft Outlook 16.0',
        };
    }

    /** 仅供排查/手工调用。发信路径不再覆盖 Message-ID，以免拆开 uniqueid 与 b1=_。 */
    public static function messageId(Config $cfg, string $fromAddress): string
    {
        $domain = $cfg->headerMessageIdDomainMode === 'main'
            ? $cfg->headerMessageIdMainDomain
            : $cfg->headerMessageIdFullDomain;
        if ($domain === '') {
            $parts = explode('@', $fromAddress, 2);
            $domain = $parts[1] ?? 'localhost';
        }
        return '<' . MessageId::generate($cfg, $domain) . '>';
    }

    public static function receivedLine(Config $cfg, string $fromAddress): string
    {
        $by = $cfg->headerMessageIdFullDomain;
        if ($by === '') {
            $parts = explode('@', $fromAddress, 2);
            $by = $parts[1] ?? 'localhost';
        }
        $queue = strtoupper(bin2hex(random_bytes(3)));
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('D, d M Y H:i:s') . ' UTC';

        if ($cfg->headerMobileClient) {
            $helo = self::MOBILE_HELOS[random_int(0, count(self::MOBILE_HELOS) - 1)];
            if (random_int(0, 99) < 70) {
                $ip = sprintf('100.%d.%d.%d', random_int(64, 127), random_int(0, 255), random_int(1, 254));
            } else {
                $ip = sprintf('192.168.%d.%d', random_int(0, 255), random_int(1, 254));
            }
            return sprintf('from %s ([%s]) by %s with ESMTPSA id %s; %s', $helo, $ip, $by, $queue, $stamp);
        }

        $fromCarrier = self::CARRIER_DOMAINS[random_int(0, count(self::CARRIER_DOMAINS) - 1)];
        $mta = self::MTA_LABELS[random_int(0, count(self::MTA_LABELS) - 1)];
        return sprintf('from %s by %s (%s) with ESMTP id %s; %s', $fromCarrier, $by, $mta, $queue, $stamp);
    }

    public static function listUnsubscribeValue(Config $cfg, string $to, string $fromAddress): string
    {
        $url = self::unsubscribeUrl($cfg, $to, $fromAddress);
        if ($url === '') {
            return '';
        }
        $host = self::unsubscribeHost($cfg, $fromAddress);
        $local = 'unsubscribe';
        $at = strrpos($fromAddress, '@');
        if ($at !== false) {
            $local = explode('@', $fromAddress, 2)[0] ?: $local;
        }
        $mailto = 'mailto:' . $local . '@' . $host . '?subject=unsubscribe';
        $raw = '<' . $url . '>, <' . $mailto . '>';

        if ($cfg->unsubscribeUseDecimal) {
            return self::encodeMIMEQPWord($raw);
        }
        return $raw;
    }

    public static function encodeMIMEQPWord(string $raw): string
    {
        $out = '=?utf-8?Q?';
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            $ord = ord($c);
            if ($c === ' ') {
                $out .= '_';
            } elseif (($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122) || ($ord >= 48 && $ord <= 57)) {
                $out .= $c;
            } else {
                $out .= sprintf('=%02X', $ord);
            }
        }
        $out .= '?=';
        return $out;
    }

    public static function unsubscribeUrl(Config $cfg, string $to, string $fromAddress): string
    {
        $to = trim($to);
        if ($to === '') {
            return '';
        }
        $host = self::unsubscribeHost($cfg, $fromAddress);
        $path = trim($cfg->unsubscribePath);
        if ($path === '') {
            $path = '/unsubscribe';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/unsubscribe';
        }

        $key = trim($cfg->unsubscribeKey);
        if ($key !== '' && function_exists('openssl_encrypt')) {
            $derivedKey = hash('sha256', $key, true);
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($to, 'aes-256-gcm', $derivedKey, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher !== false) {
                $token = rtrim(strtr(base64_encode($iv . $cipher . $tag), '+/', '-_'), '=');
                return 'https://' . $host . $path . '/' . $token;
            }
        }

        $param = trim($cfg->unsubscribeQueryParam);
        if ($param === '') {
            $param = 'email';
        }
        return 'https://' . $host . $path . '?' . $param . '=' . rawurlencode($to);
    }

    public static function contentLanguage(Config $cfg): string
    {
        $v = trim($cfg->headerContentLangValue);
        if ($v === '' || strcasecmp($v, '随机选择') === 0 || strcasecmp($v, 'random') === 0) {
            $choices = ['ja', 'en', 'zh-CN', 'zh-TW', 'ko'];
            return $choices[array_rand($choices)];
        }
        return $v;
    }

    public static function acceptLanguage(Config $cfg): string
    {
        $v = trim($cfg->headerAcceptLanguageValue);
        if ($v === '' || strcasecmp($v, '随机选择') === 0 || strcasecmp($v, 'random') === 0) {
            $choices = ['ja', 'en', 'zh-CN', 'zh-TW', 'ko'];
            return $choices[array_rand($choices)];
        }
        return $v;
    }

    public static function importance(Config $cfg): string
    {
        $v = trim($cfg->headerImportanceValue);
        if ($v === '' || strcasecmp($v, '随机选择') === 0 || strcasecmp($v, 'random') === 0) {
            $choices = ['high', 'normal', 'low'];
            return $choices[array_rand($choices)];
        }
        return strtolower($v);
    }

    public static function xPriority(Config $cfg): string
    {
        $v = trim($cfg->headerXPriorityValue);
        if (preg_match('/^[1-5]/', $v, $m)) {
            return $m[0];
        }
        return (string) random_int(1, 5);
    }

    private static function unsubscribeHost(Config $cfg, string $fromAddress): string
    {
        $host = trim($cfg->unsubscribeHost);
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = strtolower(explode('/', $host, 2)[0]);
        if ($host !== '') {
            return $host;
        }
        $parts = explode('@', $fromAddress, 2);
        $domain = strtolower(trim($parts[1] ?? ''));
        $labels = $domain === '' ? [] : explode('.', $domain);
        if (count($labels) >= 2) {
            return 'unsub.' . $labels[count($labels) - 2] . '.' . $labels[count($labels) - 1];
        }
        return 'unsub.localhost';
    }
}
