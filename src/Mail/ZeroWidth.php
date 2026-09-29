<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

final class ZeroWidth
{
    /** @var list<string> */
    private const CHARS = ["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}", "\u{2060}"];
    private const STRIP_ZW_PATTERN = '/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{2060}]/u';
    private const STRIP_PATTERN = '/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{2060}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public static function stripZeroWidth(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        return preg_replace(self::STRIP_ZW_PATTERN, '', $text) ?? $text;
    }

    public static function hasZeroWidth(string $text): bool
    {
        return preg_match(self::STRIP_ZW_PATTERN, $text) === 1;
    }

    public static function insertIntoText(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $parts = preg_split('/([\p{P}\p{S}\p{Z}\s]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return self::insertIntoWord($text);
        }

        $out = '';
        foreach ($parts as $part) {
            if (preg_match('/^[\p{P}\p{S}\p{Z}\s]+$/u', $part)) {
                $out .= $part;
            } else {
                $out .= self::insertIntoWord($part);
            }
        }

        return $out;
    }

    public static function stripControls(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        return preg_replace(self::STRIP_PATTERN, '', $text) ?? $text;
    }

    /** @return list<string> */
    public static function parseKeywords(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $seen = [];
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $kw = trim($part);
            if ($kw === '' || isset($seen[$kw])) {
                continue;
            }
            $seen[$kw] = true;
            $out[] = $kw;
        }
        usort($out, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        return $out;
    }

    /** @param list<string> $keywords */
    public static function insertAtKeywords(string $text, array $keywords, bool $isHtml = true): string
    {
        if ($text === '' || $keywords === []) {
            return $text;
        }
        foreach ($keywords as $kw) {
            $text = self::insertInMatches($text, $kw, $isHtml);
        }
        return $text;
    }

    private static function insertInMatches(string $text, string $keyword, bool $isHtml): string
    {
        if ($keyword === '') {
            return $text;
        }

        $starts = [];
        $offset = 0;
        while (($pos = strpos($text, $keyword, $offset)) !== false) {
            $end = $pos + strlen($keyword);
            if ($isHtml && self::isInsideHtmlTag($text, $pos, $end)) {
                $offset = $pos + 1;
                continue;
            }
            if (ReverseBidi::isInsideBidiRun($text, $pos) || ReverseBidi::hasRtlOverride(substr($text, $pos, strlen($keyword)))) {
                $offset = $pos + 1;
                continue;
            }
            $starts[] = $pos;
            $offset = $end;
        }

        for ($i = count($starts) - 1; $i >= 0; $i--) {
            $start = $starts[$i];
            $word = substr($text, $start, strlen($keyword));
            $replaced = self::insertIntoWord($word);
            $text = substr($text, 0, $start) . $replaced . substr($text, $start + strlen($keyword));
        }

        return $text;
    }

    private static function insertIntoWord(string $word): string
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || count($chars) < 2) {
            return $word;
        }

        $insertCount = random_int(1, min(3, count($chars) - 1));
        $positions = array_rand(array_fill(0, count($chars) - 1, 1), $insertCount);
        if (!is_array($positions)) {
            $positions = [$positions];
        }

        rsort($positions);
        foreach ($positions as $pos) {
            $zw = self::CHARS[array_rand(self::CHARS)];
            array_splice($chars, (int) $pos + 1, 0, [$zw]);
        }

        return implode('', $chars);
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
}
