<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * GD decodes every image into an uncompressed truecolor buffer (~4 bytes per pixel plus
 * overhead), so a small compressed upload can still exhaust memory_limit and crash with a 500.
 * Check the estimate before decoding and reject with a normal validation error instead.
 */
final class ImageMemory
{
    public static function assertDecodable(int $width, int $height, string $field): void
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return; // unlimited
        }
        $needed = (int) ($width * $height * 4 * 1.7) + 8 * 1024 * 1024;
        if (memory_get_usage(true) + $needed > $limit) {
            throw ValidationException::withMessages([$field => 'This image is too large to process. Use a smaller screenshot or photo.']);
        }
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
