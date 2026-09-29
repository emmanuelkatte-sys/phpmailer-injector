<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

/**
 * 对齐 goMail email/noise.go：JPG/PNG 微调像素后重编码，再追加 4–16 字节随机尾部。
 * 其它格式或解码失败时只追加尾部，保证每封邮件图片哈希不同。
 */
final class ImageNoise
{
    public static function apply(string $imgBytes, string $filename): string
    {
        $lower = strtolower($filename);
        $isJpeg = str_ends_with($lower, '.jpg') || str_ends_with($lower, '.jpeg');
        $isPng = str_ends_with($lower, '.png');

        if (!$isJpeg && !$isPng) {
            return self::appendTrailingNoise($imgBytes);
        }

        if ($imgBytes === '' || !function_exists('imagecreatefromstring')) {
            return self::appendTrailingNoise($imgBytes);
        }

        $im = @imagecreatefromstring($imgBytes);
        if ($im === false) {
            return self::appendTrailingNoise($imgBytes);
        }

        if (!imageistruecolor($im) && function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($im);
        }
        imagesavealpha($im, true);

        $width = imagesx($im);
        $height = imagesy($im);
        if ($width < 1 || $height < 1) {
            imagedestroy($im);
            return self::appendTrailingNoise($imgBytes);
        }

        $pixelCount = 1 + random_int(0, 4);
        for ($i = 0; $i < $pixelCount; $i++) {
            $x = random_int(0, $width - 1);
            $y = random_int(0, $height - 1);
            $rgb = imagecolorat($im, $x, $y);
            $a = ($rgb >> 24) & 0x7F;
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $color = imagecolorallocatealpha(
                $im,
                self::clampU8($r + random_int(0, 6) - 3),
                self::clampU8($g + random_int(0, 6) - 3),
                self::clampU8($b + random_int(0, 6) - 3),
                $a
            );
            if ($color !== false) {
                imagesetpixel($im, $x, $y, $color);
            }
        }

        $encoded = self::encodeImage($im, $isJpeg);
        imagedestroy($im);
        if ($encoded === null) {
            return self::appendTrailingNoise($imgBytes);
        }
        return self::appendTrailingNoise($encoded);
    }

    private static function encodeImage($im, bool $isJpeg): ?string
    {
        ob_start();
        $ok = false;
        if ($isJpeg && function_exists('imagejpeg')) {
            $ok = imagejpeg($im, null, 90 + random_int(0, 5));
        } elseif (!$isJpeg && function_exists('imagepng')) {
            $ok = imagepng($im);
        }
        $data = (string) ob_get_clean();
        if (!$ok || $data === '') {
            return null;
        }
        return $data;
    }

    private static function appendTrailingNoise(string $data): string
    {
        $noiseLen = 4 + random_int(0, 12);
        $noise = '';
        for ($i = 0; $i < $noiseLen; $i++) {
            $noise .= chr(random_int(0, 255));
        }
        return $data . $noise;
    }

    private static function clampU8(int $v): int
    {
        if ($v < 0) {
            return 0;
        }
        if ($v > 255) {
            return 255;
        }
        return $v;
    }
}
