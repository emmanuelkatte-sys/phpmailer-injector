<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

/**
 * 对齐 goMail syncInlineImageCIDReferences / SanitizeCIDReferences。
 */
final class InlineCid
{
    /**
     * @param list<array{cid:string,file_name:string}> $images
     */
    public static function syncReferences(string $html, array $images): string
    {
        $result = $html;
        foreach ($images as $img) {
            $cid = $img['cid'];
            $fileName = $img['file_name'];
            if ($cid === '') {
                continue;
            }
            $cidRef = 'cid:' . $cid;
            $stem = $cid;
            $dot = strrpos($fileName, '.');
            if ($dot !== false && $dot > 0) {
                $stem = substr($fileName, 0, $dot);
            }
            $names = [];
            foreach ([$fileName, $stem, $cid] as $name) {
                if ($name === '' || isset($names[$name])) {
                    continue;
                }
                $names[$name] = true;
                foreach (['"', "'"] as $q) {
                    $result = str_replace('src=' . $q . $name . $q, 'src=' . $q . $cidRef . $q, $result);
                    $oldCid = 'cid:' . $name;
                    if ($oldCid !== $cidRef) {
                        $result = str_replace('src=' . $q . $oldCid . $q, 'src=' . $q . $cidRef . $q, $result);
                    }
                }
            }
        }
        return $result;
    }

    public static function sanitizeReferences(string $html): string
    {
        if ($html === '' || stripos($html, 'cid:') === false) {
            return $html;
        }
        $offset = 0;
        $out = '';
        $len = strlen($html);
        while ($offset < $len) {
            $idx = stripos($html, 'cid:', $offset);
            if ($idx === false) {
                $out .= substr($html, $offset);
                break;
            }
            $out .= substr($html, $offset, $idx - $offset);
            $out .= 'cid:';
            $j = $idx + 4;
            while ($j < $len) {
                $ch = $html[$j];
                if ($ch === '"' || $ch === "'" || $ch === '>' || $ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n") {
                    break;
                }
                $slice = substr($html, $j);
                if (preg_match('/^[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{2060}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $slice, $m) === 1) {
                    $j += strlen($m[0]);
                    continue;
                }
                $out .= $ch;
                $j++;
            }
            $offset = $j;
        }
        return $out;
    }

    public static function fromFileName(string $fileName): string
    {
        $base = basename($fileName);
        $cid = pathinfo($base, PATHINFO_FILENAME);
        return $cid !== '' ? $cid : $base;
    }
}
