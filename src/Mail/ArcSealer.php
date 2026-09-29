<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

use OpenSSLAsymmetricKey;
use Warship\Injector\Config\Config;

/**
 * RFC 8617 first-hop ARC set (cv=none), matching pmta/goMail injectors.
 * MIME body is not rewritten; only ARC-Seal / AMS / AAR are prepended.
 */
final class ArcSealer
{
    private const SOFT_LINE = 78;
    private const HARD_LINE = 998;
    private const EXCLUDED = [
        'arc-authentication-results' => true,
        'arc-message-signature' => true,
        'arc-seal' => true,
        'authentication-results' => true,
    ];
    /** @var list<string> */
    private const DEFAULT_HEADERS = ['from', 'to', 'subject', 'date', 'message-id'];

    /** @param list<string> $signHeaders */
    private function __construct(
        private readonly string $domain,
        private readonly string $selector,
        private readonly OpenSSLAsymmetricKey $key,
        private readonly array $signHeaders,
    ) {
    }

    public static function fromConfig(Config $cfg): ?self
    {
        if (!$cfg->arcEnabled) {
            return null;
        }
        $domain = ltrim(trim($cfg->arcDomain), '@');
        if ($domain === '') {
            $from = $cfg->senderFromAddress;
            $at = strrpos($from, '@');
            if ($at !== false) {
                $domain = trim(substr($from, $at + 1));
            }
        }
        $selector = trim($cfg->arcSelector);
        if ($selector === '') {
            $selector = 'dkim';
        }
        if ($domain === '' || $selector === '') {
            return null;
        }
        $pem = trim($cfg->arcPrivateKeyPem);
        if ($pem === '') {
            $path = trim($cfg->arcPrivateKeyPath);
            if ($path === '') {
                $path = self::defaultKeyPath($cfg->mtaType, $selector, $domain);
            }
            if ($path === '' || !is_readable($path)) {
                return null;
            }
            $raw = file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $pem = $raw;
        }
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            return null;
        }
        $headers = $cfg->dkimSignHeaders;
        $hasFrom = false;
        foreach ($headers as $h) {
            if (strcasecmp(trim($h), 'from') === 0) {
                $hasFrom = true;
                break;
            }
        }
        if ($headers !== [] && !$hasFrom) {
            array_unshift($headers, 'from');
        }
        return new self($domain, $selector, $key, $headers);
    }

    /**
     * @return string ARC header block (CRLF-terminated fields) to prepend, or '' to skip
     */
    public function prependHeaders(string $raw, string $envelopeFrom): string
    {
        $raw = self::normalizeCrlf($raw);
        [$headers, $body] = self::splitMessage($raw);
        foreach ($headers as $h) {
            $n = strtolower($h['name']);
            if ($n === 'arc-seal' || $n === 'arc-message-signature' || $n === 'arc-authentication-results') {
                return '';
            }
        }
        $mailfrom = preg_replace('/[\r\n]+/', '', trim($envelopeFrom)) ?? '';
        $auth = sprintf(
            '%s; dkim=pass header.d=%s; spf=pass smtp.mailfrom=%s',
            $this->domain,
            $this->domain,
            $mailfrom
        );
        if (strpbrk($this->domain . $this->selector . $auth, "\r\n") !== false) {
            return '';
        }
        $hTag = self::amsHeaderTag($headers, $this->signHeaders);
        if ($hTag === '' || !self::hTagHasFrom($hTag)) {
            return '';
        }

        $t = time();
        $bh = base64_encode(hash('sha256', self::canonicalizeBodyRelaxed($body), true));
        $amsNoB = sprintf(
            'i=1; a=rsa-sha256; c=relaxed/relaxed; d=%s; s=%s; t=%d; h=%s; bh=%s; b=',
            $this->domain,
            $this->selector,
            $t,
            $hTag,
            $bh
        );
        $amsEmpty = self::field('ARC-Message-Signature', $amsNoB);
        $amsSig = $this->signRsa(self::buildSignedHeaders($hTag, $headers, $amsEmpty));
        if ($amsSig === null) {
            return '';
        }
        $ams = self::field('ARC-Message-Signature', $amsNoB . $amsSig);
        $aar = self::field('ARC-Authentication-Results', 'i=1; ' . $auth);
        $asNoB = sprintf(
            'i=1; a=rsa-sha256; d=%s; s=%s; t=%d; cv=none; b=',
            $this->domain,
            $this->selector,
            $t
        );
        $asEmpty = self::field('ARC-Seal', $asNoB);
        $sealBase = self::canonicalizeHeaderRelaxed($aar)
            . "\r\n"
            . self::canonicalizeHeaderRelaxed($ams)
            . "\r\n"
            . self::canonicalizeHeaderRelaxed($asEmpty);
        $asSig = $this->signRsa($sealBase);
        if ($asSig === null) {
            return '';
        }
        $as = self::field('ARC-Seal', $asNoB . $asSig);

        return self::foldField($as['raw']) . self::foldField($ams['raw']) . self::foldField($aar['raw']);
    }

    private static function defaultKeyPath(string $mtaType, string $selector, string $domain): string
    {
        return match (strtolower(trim($mtaType))) {
            'zonemta', 'zonepmta', 'zone-mta' => '/opt/zone-mta/keys/dkim-private.pem',
            'haraka' => '/root/haraka/config/dkim/dkim-private-' . $domain . '.pem',
            default => '/etc/pmta/private/' . $selector . '.' . $domain . '.private.pem',
        };
    }

    private function signRsa(string $data): ?string
    {
        $sig = '';
        if (!openssl_sign($data, $sig, $this->key, OPENSSL_ALGO_SHA256) || $sig === '') {
            return null;
        }
        return base64_encode($sig);
    }

    /**
     * @return array{name:string,value:string,raw:string}
     */
    private static function field(string $name, string $value): array
    {
        return [
            'name' => $name,
            'value' => ' ' . $value,
            'raw' => $name . ': ' . $value,
        ];
    }

    /** @param list<array{name:string,value:string,raw:string}> $headers */
    private static function amsHeaderTag(array $headers, array $want): string
    {
        if ($want === []) {
            $want = self::DEFAULT_HEADERS;
        }
        $present = [];
        foreach ($headers as $h) {
            $present[strtolower($h['name'])] = true;
        }
        $signed = [];
        foreach ($want as $name) {
            $n = strtolower(trim((string) $name));
            if ($n === '' || !isset($present[$n]) || isset(self::EXCLUDED[$n])) {
                continue;
            }
            $signed[] = $n;
        }
        return implode(':', $signed);
    }

    private static function hTagHasFrom(string $hTag): bool
    {
        foreach (explode(':', $hTag) as $name) {
            if (strcasecmp(trim($name), 'from') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{name:string,value:string,raw:string}> $all
     * @param array{name:string,value:string,raw:string} $sig
     */
    private static function buildSignedHeaders(string $hTag, array $all, array $sig): string
    {
        $consumed = [];
        $out = '';
        foreach (explode(':', $hTag) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $lname = strtolower($name);
            $nth = $consumed[$lname] ?? 0;
            $consumed[$lname] = $nth + 1;
            $h = self::nthFromBottom($all, $lname, $nth);
            if ($h === null) {
                continue;
            }
            $out .= self::canonicalizeHeaderRelaxed($h) . "\r\n";
        }
        $stripped = $sig;
        $stripped['value'] = self::removeBValue($sig['value']);
        $stripped['raw'] = self::removeBValue($sig['raw']);
        return $out . self::canonicalizeHeaderRelaxed($stripped);
    }

    /**
     * @param list<array{name:string,value:string,raw:string}> $all
     * @return array{name:string,value:string,raw:string}|null
     */
    private static function nthFromBottom(array $all, string $lname, int $skip): ?array
    {
        for ($i = count($all) - 1; $i >= 0; $i--) {
            if (strtolower($all[$i]['name']) !== $lname) {
                continue;
            }
            if ($skip === 0) {
                return $all[$i];
            }
            $skip--;
        }
        return null;
    }

    /** @param array{name:string,value:string,raw:string} $h */
    private static function canonicalizeHeaderRelaxed(array $h): string
    {
        return strtolower(trim($h['name'])) . ':' . self::relaxHeaderValue($h['value']);
    }

    private static function relaxHeaderValue(string $v): string
    {
        $v = str_replace("\r\n", '', $v);
        $out = '';
        $inWsp = false;
        $len = strlen($v);
        for ($i = 0; $i < $len; $i++) {
            $c = $v[$i];
            if ($c === ' ' || $c === "\t") {
                $inWsp = true;
                continue;
            }
            if ($inWsp) {
                if ($out !== '') {
                    $out .= ' ';
                }
                $inWsp = false;
            }
            $out .= $c;
        }
        return $out;
    }

    private static function canonicalizeBodyRelaxed(string $body): string
    {
        if ($body === '') {
            return '';
        }
        $lines = explode("\r\n", $body);
        foreach ($lines as $i => $ln) {
            $lines[$i] = self::relaxBodyLine($ln);
        }
        while ($lines !== [] && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            return '';
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    private static function relaxBodyLine(string $line): string
    {
        $out = '';
        $inWsp = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($c === ' ' || $c === "\t") {
                $inWsp = true;
                continue;
            }
            if ($inWsp) {
                $out .= ' ';
                $inWsp = false;
            }
            $out .= $c;
        }
        return $out;
    }

    private static function removeBValue(string $field): string
    {
        $out = '';
        $n = strlen($field);
        $i = 0;
        while ($i < $n) {
            $j = strpos($field, ';', $i);
            $hasDelim = $j !== false;
            $seg = $hasDelim ? substr($field, $i, $j - $i) : substr($field, $i);
            $i = $hasDelim ? $j + 1 : $n;
            $eq = strpos($seg, '=');
            if ($eq !== false && strcasecmp(trim(substr($seg, 0, $eq)), 'b') === 0) {
                $out .= substr($seg, 0, $eq + 1);
            } else {
                $out .= $seg;
            }
            if ($hasDelim) {
                $out .= ';';
            }
        }
        return $out;
    }

    private static function normalizeCrlf(string $s): string
    {
        $s = str_replace("\r\n", "\n", $s);
        $s = str_replace("\r", "\n", $s);
        return str_replace("\n", "\r\n", $s);
    }

    /**
     * @return array{0:list<array{name:string,value:string,raw:string}>,1:string}
     */
    private static function splitMessage(string $raw): array
    {
        $idx = strpos($raw, "\r\n\r\n");
        if ($idx === false) {
            $headerBlock = rtrim($raw, "\r\n");
            $body = '';
        } else {
            $headerBlock = substr($raw, 0, $idx);
            $body = substr($raw, $idx + 4);
        }
        return [self::parseHeaders($headerBlock), $body];
    }

    /**
     * @return list<array{name:string,value:string,raw:string}>
     */
    private static function parseHeaders(string $block): array
    {
        if ($block === '') {
            return [];
        }
        $headers = [];
        foreach (explode("\r\n", $block) as $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] === ' ' || $line[0] === "\t") {
                if ($headers === []) {
                    continue;
                }
                $last = count($headers) - 1;
                $headers[$last]['raw'] .= "\r\n" . $line;
                $headers[$last]['value'] .= "\r\n" . $line;
                continue;
            }
            $eq = strpos($line, ':');
            if ($eq === false) {
                continue;
            }
            $headers[] = [
                'name' => trim(substr($line, 0, $eq)),
                'value' => substr($line, $eq + 1),
                'raw' => $line,
            ];
        }
        return $headers;
    }

    private static function foldField(string $field): string
    {
        $field = rtrim($field, "\r\n");
        if ($field === '') {
            return '';
        }
        $colon = strpos($field, ':');
        if ($colon === false || $colon === 0) {
            return $field . "\r\n";
        }
        $name = substr($field, 0, $colon);
        $value = ltrim(substr($field, $colon + 1), " \t");
        return $name . ': ' . self::foldArcHeaderValue($value, strlen($name) + 2) . "\r\n";
    }

    private static function foldArcHeaderValue(string $value, int $prefixLen): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        if ($prefixLen + strlen($value) <= self::SOFT_LINE) {
            return $value;
        }
        $out = '';
        $lineLen = $prefixLen;
        $i = 0;
        $len = strlen($value);
        while ($i < $len) {
            $remain = substr($value, $i);
            $budget = self::SOFT_LINE - $lineLen;
            if ($budget < 8) {
                $out .= "\r\n ";
                $lineLen = 1;
                continue;
            }
            if (strlen($remain) <= $budget) {
                $out .= $remain;
                break;
            }
            $br = self::rfc5322ArcBreak($remain, $budget);
            if ($br > 0) {
                $out .= substr($remain, 0, $br) . "\r\n ";
                $lineLen = 1;
                $i += $br;
                while ($i < $len && ($value[$i] === ' ' || $value[$i] === "\t")) {
                    $i++;
                }
                continue;
            }
            if (self::arcInBValue($value, $i)) {
                $n = max(1, min($budget, strlen($remain)));
                $out .= substr($remain, 0, $n);
                $i += $n;
                if ($i < $len) {
                    $out .= "\r\n ";
                    $lineLen = 1;
                }
                continue;
            }
            $sp = strcspn($remain, " \t");
            $n = $sp === strlen($remain) ? strlen($remain) : $sp;
            if ($lineLen + $n > self::HARD_LINE) {
                $n = max(1, self::HARD_LINE - $lineLen);
            }
            $out .= substr($remain, 0, $n);
            $lineLen += $n;
            $i += $n;
        }
        return $out;
    }

    private static function rfc5322ArcBreak(string $s, int $budget): int
    {
        if ($budget > strlen($s)) {
            $budget = strlen($s);
        }
        if ($budget < 2) {
            return -1;
        }
        $search = substr($s, 0, $budget);
        $idx = strrpos($search, '; ');
        if ($idx !== false && $idx > 0) {
            return $idx + 2;
        }
        $sp = strrpos($search, ' ');
        $tab = strrpos($search, "\t");
        $idx = max($sp === false ? -1 : $sp, $tab === false ? -1 : $tab);
        if ($idx > 0) {
            return $idx + 1;
        }
        return -1;
    }

    private static function arcInBValue(string $value, int $pos): bool
    {
        if ($pos < 0) {
            $pos = 0;
        }
        if ($pos > strlen($value)) {
            $pos = strlen($value);
        }
        if (str_starts_with(strtolower(substr($value, $pos)), 'b=')) {
            return true;
        }
        $tag = substr($value, 0, $pos);
        $i = strrpos($tag, ';');
        if ($i !== false) {
            $tag = substr($tag, $i + 1);
        }
        $tag = ltrim($tag, " \t");
        return strlen($tag) >= 2 && ($tag[0] === 'b' || $tag[0] === 'B') && $tag[1] === '=';
    }
}
