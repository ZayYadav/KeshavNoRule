<?php
declare(strict_types=1);

namespace TeamDark\Panel;

final class Crypto
{
    private static function key(): string
    {
        $raw = base64_decode((string)Config::get('app_key'), true);
        if ($raw === false || strlen($raw) < 32) {
            throw new \RuntimeException('APP_KEY must be base64 for at least 32 random bytes.');
        }
        return substr(hash('sha256', $raw, true), 0, 32);
    }

    public static function encrypt(string $plain): array
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) throw new \RuntimeException('Encryption failed.');
        return [base64_encode($cipher), base64_encode($iv), base64_encode($tag)];
    }

    public static function decrypt(string $cipherB64, string $ivB64, string $tagB64): string
    {
        $plain = openssl_decrypt(
            base64_decode($cipherB64, true) ?: '',
            'aes-256-gcm', self::key(), OPENSSL_RAW_DATA,
            base64_decode($ivB64, true) ?: '',
            base64_decode($tagB64, true) ?: ''
        );
        if ($plain === false) throw new \RuntimeException('Decryption failed.');
        return $plain;
    }
}
