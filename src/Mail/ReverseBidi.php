<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

/**
 * Reverse-bidi on keywords: random 2+ char segments, each segment applyFull() (display = original).
 */
final class ReverseBidi
{
    private const LRI = "\u{2066}";
    private const RLI = "\u{2067}";
    private const FSI = "\u{2068}";
    private const RLO = "\u{202E}";
    private const RLE = "\u{202B}";
    private const PDF = "\u{202C}";
    private const PDI = "\u{2069}";

    /** @var list<string> */
    private const ISOLATE_OPENERS = [self::LRI, self::RLI, self::FSI];

    /** @var array<int, true> */
    private const BIDI_CODEPOINTS = [
        0x200B => true, 0x200C => true, 0x200D => true, 0xFEFF => true, 0x2060 => true,
        0x202A => true, 0x202B => true, 0x202C => true, 0x202D => true, 0x202E => true,
        0x2066 => true, 0x2067 => true, 0x2068 => true, 0x2069 => true,
    ];

    /** Whole-string reverse hide (legacy). */
    public static function applyFull(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || $chars === []) {
            return $text;
        }
        return self::LRI . self::RLO . implode('', array_reverse($chars)) . self::PDF . self::PDI;
    }

    /**
     * Segment-based reverse-bidi obfuscation:
     * Punctuation / symbols / whitespace remain in place as separators;
     * Word runes are split into random 2-4 char chunks and reversed with RLO.
     */
    public static function obfuscateText(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $text = self::normalizeControlsRawUnicode($text);
        if (self::hasRtlOverride($text)) {
            return self::finalizeHeaderField($text);
        }

        $parts = preg_split('/([\p{P}\p{S}\p{Z}\s]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return self::finalizeHeaderField(self::obfuscateWordRandomChunks($text));
        }

        $out = '';
        foreach ($parts as $part) {
            if (preg_match('/^[\p{P}\p{S}\p{Z}\s]+$/u', $part)) {
                $out .= $part;
            } else {
                $out .= self::obfuscateWordRandomChunks($part);
            }
        }

        return self::finalizeHeaderField($out);
    }

    public static function hasRtlOverride(string $text): bool
    {
        return str_contains($text, self::RLO) || str_contains($text, self::RLE);
    }

    public static function normalizeControlsRawUnicode(string $text): string
    {
        if ($text === '' || !str_contains($text, '&#')) {
            return $text;
        }
        return preg_replace_callback(
            '/&#x([0-9a-fA-F]+);|&#([0-9]+);/',
            static function (array $m): string {
                $cp = isset($m[1]) && $m[1] !== '' ? hexdec($m[1]) : (int) ($m[2] ?? 0);
                if (isset(self::BIDI_CODEPOINTS[$cp])) {
                    return mb_chr($cp, 'UTF-8');
                }
                return $m[0];
            },
            $text
        ) ?? $text;
    }

    public static function ensureIsolatedFormat(string $text): string
    {
        $text = self::normalizeControlsRawUnicode($text);
        if (!self::hasRtlOverride($text)) {
            return $text;
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || $chars === []) {
            return $text;
        }
        $out = '';
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            $ch = $chars[$i];
            if ($ch !== self::RLO && $ch !== self::RLE) {
                $out .= $ch;
                continue;
            }
            if ($i > 0 && in_array($chars[$i - 1], self::ISOLATE_OPENERS, true)) {
                $out .= $ch;
                continue;
            }
            $j = $i + 1;
            while ($j < $n && $chars[$j] !== self::PDF) {
                $j++;
            }
            if ($j >= $n) {
                $out .= $ch;
                continue;
            }
            $out .= self::ISOLATE_OPENERS[random_int(0, count(self::ISOLATE_OPENERS) - 1)];
            for ($k = $i; $k <= $j; $k++) {
                $out .= $chars[$k];
            }
            $out .= self::PDI;
            $i = $j;
        }
        return $out;
    }

    public static function finalizeHeaderField(string $text): string
    {
        $text = self::normalizeControlsRawUnicode($text);
        $text = self::ensureIsolatedFormat($text);
        return self::isolate($text);
    }

    /** Wrap LRI/PDI so a trailing '+' is not pulled into a bare RLO run. */
    public static function isolate(string $text): string
    {
        if ($text === '' || !self::hasRtlOverride($text)) {
            return $text;
        }
        if (str_starts_with($text, self::LRI) && str_ends_with($text, self::PDI)) {
            return $text;
        }
        return self::LRI . $text . self::PDI;
    }

    public static function restorePlaintext(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $text = self::normalizeControlsRawUnicode($text);
        $text = self::unwindRtlOverrides($text);
        $text = self::unwrapIsolates($text);
        $text = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{2060}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? $text;
        return self::stripVariationSelectors($text);
    }

    public static function stripVariationSelectors(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        return preg_replace('/[\x{FE00}-\x{FE0F}]/u', '', $text) ?? $text;
    }

    private static function unwindRtlOverrides(string $text): string
    {
        while (true) {
            $rloPos = mb_strrpos($text, self::RLO, 0, 'UTF-8');
            $rlePos = mb_strrpos($text, self::RLE, 0, 'UTF-8');
            $start = false;
            if ($rloPos !== false && ($rlePos === false || $rloPos > $rlePos)) {
                $start = $rloPos;
            } elseif ($rlePos !== false) {
                $start = $rlePos;
            }
            if ($start === false) {
                break;
            }
            $before = mb_substr($text, 0, $start, 'UTF-8');
            $after = mb_substr($text, $start + 1, null, 'UTF-8');
            $pdfPos = mb_strpos($after, self::PDF, 0, 'UTF-8');
            if ($pdfPos === false) {
                $text = $before . $after;
                continue;
            }
            $inner = mb_substr($after, 0, $pdfPos, 'UTF-8');
            $rest = mb_substr($after, $pdfPos + 1, null, 'UTF-8');
            $chars = preg_split('//u', $inner, -1, PREG_SPLIT_NO_EMPTY);
            $text = $before . implode('', array_reverse($chars === false ? [] : $chars)) . $rest;
        }
        return $text;
    }

    private static function unwrapIsolates(string $text): string
    {
        $openers = self::ISOLATE_OPENERS;
        while (true) {
            $start = false;
            foreach ($openers as $op) {
                $pos = mb_strrpos($text, $op, 0, 'UTF-8');
                if ($pos !== false && ($start === false || $pos > $start)) {
                    $start = $pos;
                }
            }
            if ($start === false) {
                break;
            }
            $before = mb_substr($text, 0, $start, 'UTF-8');
            $after = mb_substr($text, $start + 1, null, 'UTF-8');
            $pdiPos = mb_strpos($after, self::PDI, 0, 'UTF-8');
            if ($pdiPos === false) {
                $text = $before . $after;
                continue;
            }
            $inner = mb_substr($after, 0, $pdiPos, 'UTF-8');
            $rest = mb_substr($after, $pdiPos + 1, null, 'UTF-8');
            $text = $before . $inner . $rest;
        }
        return $text;
    }

    /** @param list<string> $keywords */
    public static function applyAtKeywordsEveryTwoChars(string $text, array $keywords, bool $isHtml = false): string
    {
        if ($text === '' || $keywords === []) {
            return $text;
        }
        $text = self::normalizeControlsRawUnicode($text);
        $keywords = self::sortKeywordsByLength($keywords);
        foreach ($keywords as $kw) {
            $kw = self::stripVariationSelectors($kw);
            if (mb_strlen($kw, 'UTF-8') < 2) {
                continue;
            }
            $text = self::applyInMatches($text, $kw, $isHtml);
        }
        return self::normalizeControlsRawUnicode($text);
    }

    /** @param list<string> $keywords */
    private static function sortKeywordsByLength(array $keywords): array
    {
        $keywords = array_values(array_filter($keywords, static fn($k) => $k !== ''));
        usort($keywords, static fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

        return $keywords;
    }

    /** @param list<string> $keywords */
    public static function applyEveryTwoCharsToBodyText(string $text, bool $isHtml, array $keywords): string
    {
        return self::applyAtKeywordsEveryTwoChars($text, $keywords, $isHtml);
    }

    private static function applyInMatches(string $text, string $keyword, bool $isHtml): string
    {
        if ($keyword === '' || mb_strlen($keyword, 'UTF-8') < 2) {
            return $text;
        }

        $starts = [];
        $offset = 0;
        $kwLen = strlen($keyword);
        while (($pos = strpos($text, $keyword, $offset)) !== false) {
            $end = $pos + $kwLen;
            $word = substr($text, $pos, $kwLen);
            if ($isHtml && (self::isInsideHtmlTag($text, $pos, $end) || self::isInsideHtmlAttribute($text, $pos))) {
                $offset = $pos + 1;
                continue;
            }
            if (self::isInsideBidiRun($text, $pos) || self::hasRtlOverride($word)) {
                $offset = $pos + 1;
                continue;
            }
            $starts[] = $pos;
            $offset = $end;
        }

        for ($i = count($starts) - 1; $i >= 0; $i--) {
            $start = $starts[$i];
            $word = substr($text, $start, $kwLen);
            $replaced = self::obfuscateWordRandomChunks($word);
            $text = substr($text, 0, $start) . $replaced . substr($text, $start + $kwLen);
        }

        return $text;
    }

    /** Random segment sizes (each >= 2 runes when possible); trailing 1 rune stays plain. */
    private static function obfuscateWordRandomChunks(string $word): string
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || count($chars) < 2) {
            return $word;
        }

        $out = '';
        $n = count($chars);
        for ($i = 0; $i < $n; ) {
            $remaining = $n - $i;
            if ($remaining < 2) {
                $out .= $chars[$i];
                break;
            }
            $size = self::randomChunkSize($remaining);
            $chunk = array_slice($chars, $i, $size);
            $out .= self::applyFull(implode('', $chunk));
            $i += $size;
        }

        return self::hasRtlOverride($out) ? self::isolate($out) : $out;
    }

    private static function randomChunkSize(int $remaining, int $minLen = 2): int
    {
        if ($remaining <= $minLen) {
            return $remaining;
        }

        $maxLen = min(4, $remaining);
        $choices = [];
        for ($size = $minLen; $size <= $maxLen; $size++) {
            $left = $remaining - $size;
            if ($left === 0 || $left >= $minLen || $left === 1) {
                $choices[] = $size;
            }
        }

        if ($choices === []) {
            return min(4, $remaining);
        }

        return $choices[random_int(0, count($choices) - 1)];
    }

    /** True if $bytePos sits inside an unclosed RLO/RLE run. */
    public static function isInsideBidiRun(string $text, int $bytePos): bool
    {
        if ($bytePos <= 0) {
            return false;
        }
        $prefix = substr($text, 0, $bytePos);
        $rlo = strrpos($prefix, self::RLO);
        $rle = strrpos($prefix, self::RLE);
        $open = false;
        if ($rlo !== false && ($rle === false || $rlo > $rle)) {
            $open = $rlo;
        } elseif ($rle !== false) {
            $open = $rle;
        }
        if ($open === false) {
            return false;
        }
        return strpos($prefix, self::PDF, $open) === false;
    }

    private static function isInsideHtmlTag(string $text, int $start, int $end): bool
    {
        $before = strrpos(substr($text, 0, $start), '<');
        $afterTag = strrpos(substr($text, 0, $start), '>');
        if ($before === false) {
            return false;
        }
        return $afterTag === false || $before > $afterTag;
    }

    private static function isInsideHtmlAttribute(string $text, int $pos): bool
    {
        if ($pos <= 0) {
            return false;
        }
        $len = strlen($text);
        for ($i = $pos - 1; $i >= 0; $i--) {
            $c = $text[$i];
            if ($c === '"' || $c === "'") {
                $j = $i - 1;
                while ($j >= 0 && ($text[$j] === ' ' || $text[$j] === "\t")) {
                    $j--;
                }
                return $j >= 0 && $text[$j] === '=';
            }
            if ($c === '<' || $c === '>') {
                return false;
            }
        }
        return false;
    }
}
