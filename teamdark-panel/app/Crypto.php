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

    public static function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, self::key());
    }

    public static function licenseLookupHash(string $value): string
    {
        return hash_hmac('sha256', 'license|'.$value, self::key());
    }

    public static function legacyLicenseLookupHash(string $value): string
    {
        return hash('sha256', $value);
    }

    public static function licenseLookupHashes(string $value): array
    {
        return [
            self::licenseLookupHash($value),
            self::legacyLicenseLookupHash($value),
        ];
    }

    public static function migrateLegacyLicenseHashes(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));

        try {
            $pdo = Database::pdo();
            $rows = $pdo->query(
                "SELECT id,key_cipher,key_iv,key_tag
                 FROM license_keys
                 WHERE key_hash_version<2
                 ORDER BY id
                 LIMIT ".$limit
            )->fetchAll() ?: [];
        } catch (\Throwable) {
            // Allows code deployment before the schema upgrade is run.
            return 0;
        }

        $changed = 0;

        foreach ($rows as $row) {
            try {
                $plain = self::decrypt(
                    (string)$row['key_cipher'],
                    (string)$row['key_iv'],
                    (string)$row['key_tag']
                );
                $hash = self::licenseLookupHash($plain);

                $q = $pdo->prepare(
                    'UPDATE license_keys
                     SET key_hash=?,key_hash_version=2
                     WHERE id=? AND key_hash_version<2'
                );
                $q->execute([$hash, (int)$row['id']]);
                $changed += $q->rowCount();
            } catch (\Throwable $e) {
                error_log(
                    'TeamDark license hash migration skipped key '
                    .(int)($row['id'] ?? 0).' '.get_class($e)
                );
            }
        }

        return $changed;
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
