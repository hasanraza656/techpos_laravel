<?php

namespace App\Domain\Backup;

use RuntimeException;

/**
 * Streaming AES-256-GCM so archives of any size never have to fit in memory.
 *
 * Format: "TPBK1\n" then chunks of [1 byte last-flag][4 byte length][12 byte nonce][16 byte tag][ciphertext].
 * Each chunk authenticates the header, its index and the last-flag, so reordering, dropping or
 * truncating chunks, a wrong key or any byte change fails decryption.
 */
final class BackupCipher
{
    private const MAGIC = "TPBK1\n";

    private const CHUNK = 1048576;

    public static function key(?string $configured): string
    {
        $raw = is_string($configured) && str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : false;
        if (! is_string($raw) || strlen($raw) !== 32) {
            throw new RuntimeException('BACKUP_KEY is missing or invalid. Generate one with `php artisan techpos:backup-key`.');
        }

        return $raw;
    }

    public static function encrypt(string $source, string $target, string $key): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'wb');
        try {
            fwrite($out, self::MAGIC);
            $index = 0;
            $chunk = fread($in, self::CHUNK);
            do {
                $next = feof($in) ? '' : fread($in, self::CHUNK);
                $last = $next === '' || $next === false;
                $nonce = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt((string) $chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::aad($index, $last));
                if ($cipher === false) {
                    throw new RuntimeException('Backup encryption failed.');
                }
                fwrite($out, ($last ? "\1" : "\0").pack('N', strlen($cipher)).$nonce.$tag.$cipher);
                $chunk = $next;
                $index++;
            } while (! $last);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function decrypt(string $source, string $target, string $key): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'wb');
        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('This is not a Tech POS backup file.');
            }
            $index = 0;
            $finished = false;
            while (! $finished) {
                $header = fread($in, 33);
                if ($header === false || strlen($header) !== 33) {
                    throw new RuntimeException('The backup file is truncated.');
                }
                $last = $header[0] === "\1";
                $length = unpack('N', substr($header, 1, 4))[1];
                if ($length > self::CHUNK + 16) {
                    throw new RuntimeException('The backup file is corrupted.');
                }
                $cipher = $length ? fread($in, $length) : '';
                if (strlen((string) $cipher) !== $length) {
                    throw new RuntimeException('The backup file is truncated.');
                }
                $plain = openssl_decrypt((string) $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($header, 5, 12), substr($header, 17, 16), self::aad($index, $last));
                if ($plain === false) {
                    throw new RuntimeException('The backup cannot be decrypted: wrong BACKUP_KEY or the file was changed.');
                }
                fwrite($out, $plain);
                $finished = $last;
                $index++;
            }
            if (fread($in, 1) !== '') {
                throw new RuntimeException('The backup file has unexpected trailing data.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private static function aad(int $index, bool $last): string
    {
        return self::MAGIC.pack('J', $index).($last ? "\1" : "\0");
    }
}
