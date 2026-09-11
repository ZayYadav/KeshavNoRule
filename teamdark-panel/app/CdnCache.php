<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use Throwable;

final class CdnCache
{
    private static bool $legacyAliasSchemaReady = false;

    public static function enabled(): bool
    {
        return (bool)Config::get('cloudflare_cache_enabled', false);
    }

    public static function cacheSeconds(): int
    {
        return max(300, min(604800, (int)Config::get('cdn_file_cache_seconds', 86400)));
    }

    /**
     * Permanent bearer URL for one file slot.
     *
     * The signature intentionally excludes version/hash so replacing the bytes in
     * the same user_uploads row never changes the shared download URL.
     */
    public static function vaultUrl(array $row): string
    {
        if (!self::enabled()) return '';

        $base = rtrim((string)Config::get('app_url', ''), '/');
        $id = (int)($row['id'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        if ($base === '' || $id <= 0 || $userId <= 0) return '';

        $signature = self::stableVaultSignature($id, $userId);
        if ($signature === '') return '';

        return $base.'/cdn-files/'.$id.'/'.$signature.'/asset.zip';
    }

    public static function verifyStableVaultSignature(array $row, string $signature): bool
    {
        $id = (int)($row['id'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        $signature = strtolower(trim($signature));
        if ($id <= 0 || $userId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return false;
        }

        $expected = self::stableVaultSignature($id, $userId);
        return $expected !== '' && hash_equals($expected, $signature);
    }

    /**
     * Legacy v1 verifier kept so already-shared versioned links continue to work.
     */
    public static function verifyVaultSignature(array $row, string $signature): bool
    {
        $id = (int)($row['id'] ?? 0);
        $version = (int)($row['version'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        $sha = strtolower(trim((string)($row['sha256'] ?? '')));
        $signature = strtolower(trim($signature));
        if ($id <= 0 || $version <= 0 || $userId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $sha) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return false;
        }

        $expected = self::legacyVaultSignature($id, $version, $userId, $sha);
        return $expected !== '' && hash_equals($expected, $signature);
    }

    public static function legacyAliasMatches(int $fileId, int $version, string $signature): bool
    {
        if ($fileId <= 0 || $version <= 0 || !preg_match('/^[a-f0-9]{64}$/i', $signature)) return false;
        self::ensureLegacyAliasSchema();

        try {
            $q = Database::pdo()->prepare(
                'SELECT 1 FROM cdn_file_legacy_aliases WHERE file_id=? AND legacy_version=? AND legacy_signature=? LIMIT 1'
            );
            $q->execute([$fileId, $version, strtolower($signature)]);
            return (bool)$q->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public static function purgeVaultFile(int $fileId): bool
    {
        if (!self::enabled() || $fileId <= 0) return true;
        $base = rtrim((string)Config::get('app_url', ''), '/');
        if ($base === '') {
            error_log('TeamDark CDN purge skipped: APP_URL is empty.');
            return false;
        }

        $urls = [$base.'/files/download?id='.$fileId];
        $row = self::currentVaultRow($fileId);
        if ($row !== null) {
            $stable = self::vaultUrl($row);
            if ($stable !== '') $urls[] = $stable;
            $legacy = self::legacyVaultUrl($row);
            if ($legacy !== '') $urls[] = $legacy;
        }

        $purged = self::purgeUrls($urls);
        $warmed = $row === null ? true : self::warmVaultRow($row);
        return $purged && $warmed;
    }

    public static function purgeVaultRow(array $row): bool
    {
        if (!self::enabled()) return true;
        $id = (int)($row['id'] ?? 0);
        $base = rtrim((string)Config::get('app_url', ''), '/');
        if ($id <= 0 || $base === '') return false;

        // Preserve the old v1 signed URL as an alias before its version/hash is
        // superseded. It will resolve to the latest bytes in this same file slot.
        self::rememberLegacyAlias($row);

        $urls = [$base.'/files/download?id='.$id];
        $stable = self::vaultUrl($row);
        if ($stable !== '') $urls[] = $stable;
        $legacy = self::legacyVaultUrl($row);
        if ($legacy !== '') $urls[] = $legacy;

        $purged = self::purgeUrls($urls);

        // UploadManager calls this after the replacement transaction commits.
        // Re-read the slot and warm exactly the same stable URL with fresh bytes.
        $current = self::currentVaultRow($id);
        $warmed = $current === null ? true : self::warmVaultRow($current);
        return $purged && $warmed;
    }

    /**
     * Prime Cloudflare after upload/replace. Failure never invalidates the upload;
     * the first real download can still populate cache normally.
     */
    public static function warmVaultRow(array $row): bool
    {
        if (!self::enabled()) return true;
        $url = self::vaultUrl($row);
        if ($url === '') return false;
        if (!function_exists('curl_init')) {
            error_log('TeamDark CDN warm skipped: PHP cURL extension is unavailable.');
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TeamDarkPanel-CDN-Warm/2.0',
            CURLOPT_HTTPHEADER => [
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Accept: application/octet-stream,*/*;q=0.8',
            ],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data): int {
                return strlen($data);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $success = $ok !== false && $status >= 200 && $status < 300;
        if (!$success) {
            error_log('TeamDark CDN warm failed for file #'.(int)($row['id'] ?? 0).' (HTTP '.$status.($error !== '' ? ', network error' : '').').');
        }
        return $success;
    }

    public static function purgeUrls(array $urls): bool
    {
        if (!self::enabled()) return true;

        $zoneId = trim((string)Config::get('cloudflare_zone_id', ''));
        $apiToken = trim((string)Config::get('cloudflare_api_token', ''));
        if ($zoneId === '' || $apiToken === '') {
            error_log('TeamDark CDN purge skipped: Cloudflare zone ID or API token is not configured.');
            return false;
        }
        if (!function_exists('curl_init')) {
            error_log('TeamDark CDN purge skipped: PHP cURL extension is unavailable.');
            return false;
        }

        $files = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) $files[$url] = $url;
        }
        $files = array_values($files);
        if ($files === []) return true;

        try {
            $payload = json_encode(['files'=>$files], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            error_log('TeamDark CDN purge failed while encoding request.');
            return false;
        }

        $ch = curl_init('https://api.cloudflare.com/client/v4/zones/'.rawurlencode($zoneId).'/purge_cache');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$apiToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TeamDarkPanel-CDN-Purge/2.0',
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($response)) {
            error_log('TeamDark CDN purge failed: cURL request error'.($curlError !== '' ? ' (network)' : '').'.');
            return false;
        }

        $decoded = json_decode($response, true);
        $success = $status >= 200 && $status < 300 && is_array($decoded) && ($decoded['success'] ?? false) === true;
        if (!$success) error_log('TeamDark CDN purge failed: Cloudflare HTTP '.$status.'.');
        return $success;
    }

    private static function currentVaultRow(int $fileId): ?array
    {
        try {
            $q = Database::pdo()->prepare('SELECT * FROM user_uploads WHERE id=? LIMIT 1');
            $q->execute([$fileId]);
            $row = $q->fetch();
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function legacyVaultUrl(array $row): string
    {
        $base = rtrim((string)Config::get('app_url', ''), '/');
        $id = (int)($row['id'] ?? 0);
        $version = (int)($row['version'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        $sha = strtolower(trim((string)($row['sha256'] ?? '')));
        if ($base === '' || $id <= 0 || $version <= 0 || $userId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
            return '';
        }
        $signature = self::legacyVaultSignature($id, $version, $userId, $sha);
        return $signature === '' ? '' : $base.'/cdn-files/'.$id.'/'.$version.'/'.$signature.'/asset.zip';
    }

    private static function rememberLegacyAlias(array $row): void
    {
        $id = (int)($row['id'] ?? 0);
        $version = (int)($row['version'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        $sha = strtolower(trim((string)($row['sha256'] ?? '')));
        if ($id <= 0 || $version <= 0 || $userId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $sha)) return;

        $signature = self::legacyVaultSignature($id, $version, $userId, $sha);
        if ($signature === '') return;
        self::ensureLegacyAliasSchema();

        try {
            Database::pdo()->prepare(
                'INSERT IGNORE INTO cdn_file_legacy_aliases(file_id,legacy_version,legacy_signature) VALUES(?,?,?)'
            )->execute([$id, $version, $signature]);
        } catch (Throwable $e) {
            error_log('TeamDark legacy CDN alias could not be stored for file #'.$id.'.');
        }
    }

    private static function ensureLegacyAliasSchema(): void
    {
        if (self::$legacyAliasSchemaReady) return;
        try {
            Database::pdo()->exec(
                "CREATE TABLE IF NOT EXISTS cdn_file_legacy_aliases (
                    file_id BIGINT UNSIGNED NOT NULL,
                    legacy_version INT UNSIGNED NOT NULL,
                    legacy_signature CHAR(64) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY(file_id,legacy_version,legacy_signature),
                    CONSTRAINT fk_cdn_alias_file FOREIGN KEY (file_id) REFERENCES user_uploads(id) ON DELETE CASCADE,
                    INDEX idx_cdn_alias_created(created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            self::$legacyAliasSchemaReady = true;
        } catch (Throwable $e) {
            // Stable v2 links do not depend on this compatibility table. On hosts
            // without CREATE privilege only pre-v2 links lose alias continuity.
            error_log('TeamDark CDN legacy alias table is unavailable.');
        }
    }

    private static function stableVaultSignature(int $id, int $userId): string
    {
        $raw = base64_decode((string)Config::get('app_key', ''), true);
        if ($raw === false || strlen($raw) < 32) return '';
        $key = hash_hmac('sha256', 'TeamDark CDN stable file v2', $raw, true);
        return hash_hmac('sha256', $id.'|'.$userId, $key);
    }

    private static function legacyVaultSignature(int $id, int $version, int $userId, string $sha): string
    {
        $raw = base64_decode((string)Config::get('app_key', ''), true);
        if ($raw === false || strlen($raw) < 32) return '';
        $key = hash_hmac('sha256', 'TeamDark CDN signed file v1', $raw, true);
        return hash_hmac('sha256', $id.'|'.$version.'|'.$userId.'|'.$sha, $key);
    }
}
