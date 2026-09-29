<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

use Warship\Injector\Config\Config;

/**
 * Per-recipient HTML fingerprint mutation (aligned with Go email/html_mutator.go).
 * CSS jitter, random attributes on div/table/td, optional p→div swap.
 * Table structure is kept; table/td/tr get presentation attrs instead of being rewritten as div.
 */
final class HtmlMutator
{
    private const VOID = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true, 'embed' => true,
        'hr' => true, 'img' => true, 'input' => true, 'link' => true, 'meta' => true,
        'param' => true, 'source' => true, 'track' => true, 'wbr' => true,
    ];
    private const SKIP_INJECT = [
        'a' => true, 'img' => true, 'script' => true, 'style' => true, 'link' => true, 'meta' => true,
    ];
    private const TABLE = [
        'table' => true, 'thead' => true, 'tbody' => true, 'tfoot' => true,
        'tr' => true, 'td' => true, 'th' => true, 'col' => true, 'colgroup' => true, 'caption' => true,
    ];

    private int $rng;

    public static function apply(string $html, Config $cfg, string $seed): string
    {
        if (!$cfg->htmlMutatorEnabled || trim($html) === '' || !self::isHtml($html)) {
            return $html;
        }
        $m = new self($seed);
        $out = $html;
        if ($cfg->htmlMutatorCssJitter) {
            $out = $m->jitterInlineCss($out);
        }
        if ($cfg->htmlMutatorInjectAttrs || $cfg->htmlMutatorTagSwap) {
            $out = $m->mutateTags(
                $out,
                $cfg->htmlMutatorInjectAttrs,
                $cfg->htmlMutatorTagSwap,
                $cfg->htmlMutatorPreserveTables,
            );
        }
        return $out;
    }

    private function __construct(string $seed)
    {
        $raw = substr(hash('sha256', $seed, true), 0, 4);
        $this->rng = unpack('V', $raw)[1] & 0x7fffffff;
        if ($this->rng === 0) {
            $this->rng = 1;
        }
    }

    private function nextInt(int $n): int
    {
        $this->rng = (1103515245 * $this->rng + 12345) & 0x7fffffff;
        return $n <= 0 ? 0 : $this->rng % $n;
    }

    private function nextFloat(): float
    {
        return $this->nextInt(1000000) / 1000000.0;
    }

    private static function isHtml(string $content): bool
    {
        $c = strtolower(ltrim($content));
        if ($c === '') {
            return false;
        }
        if (str_starts_with($c, '<!doctype') || str_starts_with($c, '<html') || str_contains($c, '<body')) {
            return true;
        }
        foreach (['<div', '<table', '<p', '<span', '<center', '<td', '<tr', '<tbody'] as $p) {
            if (str_starts_with($c, $p) || str_contains($c, $p)) {
                return true;
            }
        }
        return false;
    }

    private function jitterInlineCss(string $html): string
    {
        return preg_replace_callback(
            '/\b(font-size|line-height|letter-spacing|margin-top|margin-bottom)\s*:\s*([0-9.]+)(px|em|rem|%)?/i',
            function (array $m): string {
                $prop = $m[1];
                $num = (float) $m[2];
                $unit = $m[3] ?? '';
                $jittered = $this->jitterNumber(strtolower($prop), $num, $unit);
                $fmt = self::formatFloat($jittered);
                return $unit === '' ? "{$prop}:{$fmt}" : "{$prop}:{$fmt}{$unit}";
            },
            $html
        ) ?? $html;
    }

    private function jitterNumber(string $prop, float $num, string $unit): float
    {
        $delta = 0.0;
        if ($prop === 'line-height' && $unit === '') {
            $delta = $this->nextFloat() * 0.04 - 0.02;
        } elseif ($prop === 'font-size') {
            $delta = $this->nextFloat() * 0.4 - 0.2;
        } elseif ($prop === 'letter-spacing') {
            $delta = $this->nextFloat() * 0.2 - 0.1;
        } elseif ($prop === 'margin-top' || $prop === 'margin-bottom') {
            $delta = $this->nextFloat() * 0.8 - 0.4;
        } else {
            return $num;
        }
        $out = max(0.0, $num + $delta);
        if ($unit === '' && $prop === 'line-height') {
            return round($out * 1000) / 1000;
        }
        return round($out * 10) / 10;
    }

    private static function formatFloat(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    private function mutateTags(string $html, bool $injectAttrs, bool $tagSwap, bool $preserveTables): string
    {
        $out = '';
        $pSwapStack = [];
        $len = strlen($html);
        $i = 0;
        while ($i < $len) {
            if ($html[$i] !== '<') {
                $j = strpos($html, '<', $i);
                if ($j === false) {
                    $out .= substr($html, $i);
                    break;
                }
                $out .= substr($html, $i, $j - $i);
                $i = $j;
                continue;
            }
            $j = strpos($html, '>', $i);
            if ($j === false) {
                $out .= substr($html, $i);
                break;
            }
            $token = substr($html, $i, $j - $i + 1);
            $i = $j + 1;
            if (str_starts_with($token, '<!--') || str_starts_with($token, '<!') || str_starts_with($token, '<?')) {
                $out .= $token;
                continue;
            }
            $closing = str_starts_with($token, '</');
            $tagLower = strtolower(self::parseTagName($token));
            $selfClosing = str_ends_with(rtrim($token), '/>') || isset(self::VOID[$tagLower]);

            if ($closing) {
                if ($tagSwap && $tagLower === 'p' && $pSwapStack !== []) {
                    $swapped = array_pop($pSwapStack);
                    $out .= $swapped ? '</div>' : $token;
                    continue;
                }
                $out .= $token;
                continue;
            }

            $mutated = $token;
            if ($tagSwap && $tagLower === 'p') {
                $swapped = $this->nextInt(2) === 0;
                if ($swapped) {
                    $mutated = self::replaceTagName($mutated, 'p', 'div');
                }
                $pSwapStack[] = $swapped;
            }
            if ($injectAttrs && !isset(self::SKIP_INJECT[$tagLower]) && !$selfClosing) {
                $mutated = $this->injectAttrs($mutated);
            }
            if (($injectAttrs || $tagSwap) && isset(self::TABLE[$tagLower]) && !$selfClosing) {
                $mutated = $this->injectTableLayoutAttrs($mutated, $tagLower);
            }
            $out .= $mutated;
        }
        unset($preserveTables);
        return $out;
    }

    private static function parseTagName(string $token): string
    {
        $token = ltrim($token, '<');
        $token = ltrim($token, '/');
        $token = strstr($token, '>', true) ?: $token;
        if (preg_match('/^[^\s\/>]+/', $token, $m) === 1) {
            return rtrim($m[0], '/');
        }
        return '';
    }

    private static function replaceTagName(string $token, string $from, string $to): string
    {
        $idx = stripos($token, $from);
        if ($idx === false) {
            return $token;
        }
        return substr($token, 0, $idx) . $to . substr($token, $idx + strlen($from));
    }

    private function injectAttrs(string $token): string
    {
        $trimmed = trim($token);
        if (preg_match('/^<\s*([a-zA-Z][a-zA-Z0-9:-]*)\s*([^>]*)>$/', $trimmed, $m) !== 1) {
            return $token;
        }
        $name = $m[1];
        $attrs = trim(rtrim(trim($m[2]), '/'));
        $selfClose = str_ends_with($trimmed, '/>');
        $parts = [$attrs];
        if ($this->nextInt(2) === 0) {
            $parts[] = 'data-x="' . $this->token(6) . '"';
        }
        if ($this->nextInt(2) === 0) {
            $parts[] = 'class="c-' . $this->token(5) . '"';
        }
        if ($this->nextInt(3) === 0) {
            $parts[] = 'id="n-' . $this->token(5) . '"';
        }
        $newAttrs = trim(implode(' ', array_filter($parts)));
        return $selfClose ? "<{$name} {$newAttrs}/>" : "<{$name} {$newAttrs}>";
    }

    private function injectTableLayoutAttrs(string $token, string $tagLower): string
    {
        $lower = strtolower($token);
        $extra = [];
        if ($tagLower === 'table') {
            if (!str_contains($lower, 'role=') && $this->nextInt(2) === 0) {
                $extra[] = 'role="presentation"';
            }
            if (!str_contains($lower, 'cellpadding=') && $this->nextInt(2) === 0) {
                $extra[] = 'cellpadding="' . (string) $this->nextInt(2) . '"';
            }
            if (!str_contains($lower, 'cellspacing=') && $this->nextInt(2) === 0) {
                $extra[] = 'cellspacing="' . (string) $this->nextInt(2) . '"';
            }
            if (!str_contains($lower, 'border=') && $this->nextInt(2) === 0) {
                $extra[] = 'border="0"';
            }
        } elseif ($tagLower === 'td' || $tagLower === 'th') {
            if (!str_contains($lower, 'valign=') && $this->nextInt(3) === 0) {
                $extra[] = 'valign="top"';
            }
        }
        if ($extra === []) {
            return $token;
        }
        $selfClose = str_ends_with(rtrim($token), '/>');
        $core = $selfClose ? substr(rtrim($token), 0, -2) : substr($token, 0, -1);
        $core = rtrim($core);
        $joined = implode(' ', $extra);
        return $selfClose ? "{$core} {$joined}/>" : "{$core} {$joined}>";
    }

    private function token(int $n): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        $len = strlen($alphabet);
        for ($i = 0; $i < $n; $i++) {
            $out .= $alphabet[$this->nextInt($len)];
        }
        return $out;
    }
}
