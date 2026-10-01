<?php

declare(strict_types=1);

namespace Warship\Injector\Util;

/**
 * Pure PHP QR Code generator (Byte mode, Versions 1-10, ECC M/Q).
 * Output: PNG DataURL or raw PNG binary without requiring ext-gd.
 */
final class QrEncoder
{
    /** Total codewords per version (V1 to V10) */
    private const TOTAL_CODEWORDS = [
        1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134,
        6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346,
    ];

    /** ECC M: [data_codewords, ec_codewords_per_block, num_blocks_g1, data_per_block_g1, num_blocks_g2, data_per_block_g2] */
    private const ECC_M = [
        1 => [16, 10, 1, 16, 0, 0],
        2 => [28, 16, 1, 28, 0, 0],
        3 => [44, 26, 1, 44, 0, 0],
        4 => [64, 18, 2, 32, 0, 0],
        5 => [86, 24, 2, 43, 0, 0],
        6 => [108, 16, 4, 27, 0, 0],
        7 => [124, 18, 4, 31, 0, 0],
        8 => [154, 22, 2, 38, 2, 39],
        9 => [182, 22, 3, 36, 2, 37],
        10 => [216, 26, 4, 43, 1, 44],
    ];

    /** Alignment pattern center positions for V2-V10 */
    private const ALIGNMENT_COORDS = [
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Format info bit strings for mask 0-7, ECC M */
    private const FORMAT_INFO_M = [
        0 => 0x5412, // 00 + mask 000
        1 => 0x5125, // 00 + mask 001
        2 => 0x5e7c, // 00 + mask 010
        3 => 0x5b4b, // 00 + mask 011
        4 => 0x45f9, // 00 + mask 100
        5 => 0x40ce, // 00 + mask 101
        6 => 0x4f97, // 00 + mask 110
        7 => 0x4aa0, // 00 + mask 111
    ];

    private static ?array $gfExp = null;
    private static ?array $gfLog = null;

    public static function encodeDataUrl(string $text, int $targetSize = 200, int $margin = 4): string
    {
        $png = self::encodePng($text, $targetSize, $margin);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    public static function encodePng(string $text, int $targetSize = 200, int $margin = 4): string
    {
        $matrix = self::generateMatrix($text);
        $modules = count($matrix);
        $totalModules = $modules + ($margin * 2);
        $scale = max(1, (int) floor($targetSize / $totalModules));
        if ($scale < 1) {
            $scale = 1;
        }
        $actualSize = $totalModules * $scale;

        // Build 2D pixel grid: 1 = white (background), 0 = black (module)
        $grid = [];
        for ($y = 0; $y < $actualSize; $y++) {
            $grid[$y] = array_fill(0, $actualSize, 1);
        }

        for ($r = 0; $r < $modules; $r++) {
            for ($c = 0; $c < $modules; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $topY = ($r + $margin) * $scale;
                    $leftX = ($c + $margin) * $scale;
                    for ($dy = 0; $dy < $scale; $dy++) {
                        for ($dx = 0; $dx < $scale; $dx++) {
                            $grid[$topY + $dy][$leftX + $dx] = 0;
                        }
                    }
                }
            }
        }

        if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
            $im = imagecreatetruecolor($actualSize, $actualSize);
            $white = imagecolorallocate($im, 255, 255, 255);
            $black = imagecolorallocate($im, 0, 0, 0);
            imagefilledrectangle($im, 0, 0, $actualSize - 1, $actualSize - 1, $white);
            for ($y = 0; $y < $actualSize; $y++) {
                for ($x = 0; $x < $actualSize; $x++) {
                    if ($grid[$y][$x] === 0) {
                        imagesetpixel($im, $x, $y, $black);
                    }
                }
            }
            ob_start();
            imagepng($im);
            $out = (string) ob_get_clean();
            imagedestroy($im);
            return $out;
        }

        return self::renderPngChunks($actualSize, $actualSize, $grid);
    }

    public static function generateMatrix(string $text): array
    {
        self::initGf();
        $len = strlen($text);
        $version = 1;
        while ($version <= 10 && self::ECC_M[$version][0] - 2 < $len) { // 2 bytes header overhead (mode + count)
            $version++;
        }
        if ($version > 10) {
            $version = 10;
            $text = substr($text, 0, self::ECC_M[10][0] - 3);
            $len = strlen($text);
        }

        $eccSpec = self::ECC_M[$version];
        $dataCodewordsCount = $eccSpec[0];
        $ecCodewordsPerBlock = $eccSpec[1];

        // 1. BitStream encoding: Byte Mode (0100) + Character Count (8 bits for V1-9, 16 bits for V10)
        $bits = '0100';
        $countBits = ($version < 10) ? 8 : 16;
        $bits .= str_pad(decbin($len), $countBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Terminator up to 4 zeroes
        $targetBitLen = $dataCodewordsCount * 8;
        $bits .= str_repeat('0', min(4, max(0, $targetBitLen - strlen($bits))));

        // Pad to byte boundary
        if ((strlen($bits) % 8) !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        // Pad bytes 0xEC (11101100), 0x11 (00010001)
        $padToggle = true;
        while (strlen($bits) < $targetBitLen) {
            $bits .= $padToggle ? '11101100' : '00010001';
            $padToggle = !$padToggle;
        }

        $dataCodewords = [];
        for ($i = 0; $i < $targetBitLen; $i += 8) {
            $dataCodewords[] = bindec(substr($bits, $i, 8));
        }

        // 2. Block division & Reed-Solomon computation
        $blocksData = [];
        $blocksEc = [];
        $offset = 0;
        $numBlocks = $eccSpec[2] + $eccSpec[4];

        for ($b = 0; $b < $numBlocks; $b++) {
            $blockSize = ($b < $eccSpec[2]) ? $eccSpec[3] : $eccSpec[5];
            $bData = array_slice($dataCodewords, $offset, $blockSize);
            $offset += $blockSize;
            $blocksData[] = $bData;
            $blocksEc[] = self::calculateReedSolomon($bData, $ecCodewordsPerBlock);
        }

        // Interleave data codewords
        $finalCodewords = [];
        $maxDataLen = max($eccSpec[3], $eccSpec[5]);
        for ($i = 0; $i < $maxDataLen; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                if (isset($blocksData[$b][$i])) {
                    $finalCodewords[] = $blocksData[$b][$i];
                }
            }
        }
        // Interleave EC codewords
        for ($i = 0; $i < $ecCodewordsPerBlock; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $finalCodewords[] = $blocksEc[$b][$i];
            }
        }

        $finalBits = '';
        foreach ($finalCodewords as $cw) {
            $finalBits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }

        // Add remainder bits for V2-V6 (7 bits), etc.
        $remainderBits = match ($version) {
            2, 3, 4, 5, 6 => 7,
            default => 0,
        };
        $finalBits .= str_repeat('0', $remainderBits);

        // 3. Matrix construction
        $size = 21 + ($version - 1) * 4;
        $matrix = array_fill(0, $size, array_fill(0, $size, -1)); // -1 = unassigned

        // Finder patterns
        self::placeFinderPattern($matrix, 0, 0);
        self::placeFinderPattern($matrix, 0, $size - 7);
        self::placeFinderPattern($matrix, $size - 7, 0);

        // Separators
        for ($i = 0; $i < 8; $i++) {
            self::setIfValid($matrix, 7, $i, 0);
            self::setIfValid($matrix, $i, 7, 0);
            self::setIfValid($matrix, 7, $size - 8 + $i, 0);
            self::setIfValid($matrix, $i, $size - 8, 0);
            self::setIfValid($matrix, $size - 8, $i, 0);
            self::setIfValid($matrix, $size - 8 + $i, 7, 0);
        }

        // Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $matrix[6][$i] = ($i % 2 === 0) ? 1 : 0;
            $matrix[$i][6] = ($i % 2 === 0) ? 1 : 0;
        }

        // Dark module
        $matrix[4 * $version + 9][8] = 1;

        // Alignment patterns
        if (isset(self::ALIGNMENT_COORDS[$version])) {
            $coords = self::ALIGNMENT_COORDS[$version];
            foreach ($coords as $r) {
                foreach ($coords as $c) {
                    if (($r === 6 && $c === 6) ||
                        ($r === 6 && $c === $coords[count($coords) - 1] && $r < 9 && $c > $size - 9) ||
                        ($r === $coords[count($coords) - 1] && $c === 6 && $r > $size - 9 && $c < 9)) {
                        continue; // Overlaps finder
                    }
                    self::placeAlignmentPattern($matrix, $r, $c);
                }
            }
        }

        // Reserve format info area
        for ($i = 0; $i < 9; $i++) {
            if ($matrix[8][$i] === -1) $matrix[8][$i] = -2;
            if ($matrix[$i][8] === -1) $matrix[$i][8] = -2;
        }
        for ($i = 0; $i < 8; $i++) {
            if ($matrix[8][$size - 1 - $i] === -1) $matrix[8][$size - 1 - $i] = -2;
            if ($matrix[$size - 1 - $i][8] === -1) $matrix[$size - 1 - $i][8] = -2;
        }

        // 4. Place data bits in zigzag order with Mask 0 ((row + col) % 2 == 0)
        $mask = 0;
        $bitIdx = 0;
        $bitCount = strlen($finalBits);
        $upwards = true;

        for ($rightCol = $size - 1; $rightCol > 0; $rightCol -= 2) {
            if ($rightCol === 6) {
                $rightCol--; // Skip vertical timing pattern
            }
            $rows = $upwards ? range($size - 1, 0) : range(0, $size - 1);
            foreach ($rows as $r) {
                for ($c = $rightCol; $c >= $rightCol - 1; $c--) {
                    if ($matrix[$r][$c] < 0) { // Unassigned
                        $rawBit = ($bitIdx < $bitCount) ? (int) $finalBits[$bitIdx] : 0;
                        $bitIdx++;
                        // Apply Mask 0: (row + col) % 2 === 0
                        $invert = (($r + $c) % 2 === 0);
                        $matrix[$r][$c] = $invert ? ($rawBit ^ 1) : $rawBit;
                    }
                }
            }
            $upwards = !$upwards;
        }

        // 5. Apply Format Information for Mask 0, ECC M
        $formatInfo = self::FORMAT_INFO_M[$mask];
        $fBits = str_pad(decbin($formatInfo), 15, '0', STR_PAD_LEFT);

        // Top-left horizontal & vertical
        $matrix[8][0] = (int) $fBits[0];
        $matrix[8][1] = (int) $fBits[1];
        $matrix[8][2] = (int) $fBits[2];
        $matrix[8][3] = (int) $fBits[3];
        $matrix[8][4] = (int) $fBits[4];
        $matrix[8][5] = (int) $fBits[5];
        $matrix[8][7] = (int) $fBits[6];
        $matrix[8][8] = (int) $fBits[7];
        $matrix[7][8] = (int) $fBits[8];
        $matrix[5][8] = (int) $fBits[9];
        $matrix[4][8] = (int) $fBits[10];
        $matrix[3][8] = (int) $fBits[11];
        $matrix[2][8] = (int) $fBits[12];
        $matrix[1][8] = (int) $fBits[13];
        $matrix[0][8] = (int) $fBits[14];

        // Bottom-left & top-right
        for ($i = 0; $i < 7; $i++) {
            $matrix[$size - 1 - $i][8] = (int) $fBits[$i];
        }
        for ($i = 0; $i < 8; $i++) {
            $matrix[8][$size - 8 + $i] = (int) $fBits[7 + $i];
        }

        // Clean unassigned modules to 0
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] < 0) {
                    $matrix[$r][$c] = 0;
                }
            }
        }

        return $matrix;
    }

    private static function placeFinderPattern(array &$matrix, int $top, int $left): void
    {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                $isBlack = ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                $matrix[$top + $r][$left + $c] = $isBlack ? 1 : 0;
            }
        }
    }

    private static function placeAlignmentPattern(array &$matrix, int $centerR, int $centerC): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $isBlack = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0));
                $matrix[$centerR + $r][$centerC + $c] = $isBlack ? 1 : 0;
            }
        }
    }

    private static function setIfValid(array &$matrix, int $r, int $c, int $val): void
    {
        $size = count($matrix);
        if ($r >= 0 && $r < $size && $c >= 0 && $c < $size) {
            $matrix[$r][$c] = $val;
        }
    }

    private static function initGf(): void
    {
        if (self::$gfExp !== null) {
            return;
        }
        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $x;
            self::$gfExp[$i + 255] = $x;
            self::$gfLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d; // Primitive polynomial x^8 + x^4 + x^3 + x^2 + 1
            }
        }
    }

    private static function calculateReedSolomon(array $data, int $ecCount): array
    {
        // Build generator polynomial
        $gen = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $next = [1, self::$gfExp[$i]];
            $prod = array_fill(0, count($gen) + 1, 0);
            for ($j = 0; $j < count($gen); $j++) {
                for ($k = 0; $k < count($next); $k++) {
                    if ($gen[$j] !== 0 && $next[$k] !== 0) {
                        $logSum = self::$gfLog[$gen[$j]] + self::$gfLog[$next[$k]];
                        $prod[$j + $k] ^= self::$gfExp[$logSum % 255];
                    }
                }
            }
            $gen = $prod;
        }

        // Synthetic division
        $poly = array_merge($data, array_fill(0, $ecCount, 0));
        for ($i = 0; $i < count($data); $i++) {
            $coef = $poly[$i];
            if ($coef !== 0) {
                $logCoef = self::$gfLog[$coef];
                for ($j = 0; $j < count($gen); $j++) {
                    if ($gen[$j] !== 0) {
                        $poly[$i + $j] ^= self::$gfExp[(self::$gfLog[$gen[$j]] + $logCoef) % 255];
                    }
                }
            }
        }

        return array_slice($poly, count($data));
    }

    private static function renderPngChunks(int $w, int $h, array $grid): string
    {
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\x00"; // None filter
            for ($x = 0; $x < $w; $x++) {
                $raw .= ($grid[$y][$x] === 1) ? "\xFF" : "\x00";
            }
        }

        $sig = "\x89PNG\r\n\x1a\n";
        $ihdrData = pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0);
        $ihdr = pack('N', 13) . 'IHDR' . $ihdrData . pack('N', crc32('IHDR' . $ihdrData));
        $idatData = (string) gzcompress($raw);
        $idat = pack('N', strlen($idatData)) . 'IDAT' . $idatData . pack('N', crc32('IDAT' . $idatData));
        $iend = pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));

        return $sig . $ihdr . $idat . $iend;
    }
}
