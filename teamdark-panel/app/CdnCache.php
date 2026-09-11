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
     * v3 permanent bearer URL for one file slot.
     *
     * This URL never changes when a file is replaced. The resolver response is
     * deliberately not cacheable; it redirects to a version/hash-bound payload
     * URL which Cloudflare may cache aggressively without ever serving stale
     * bytes for the permanent link.
     */
    public static function vaultUrl(array $row): string
    {
        if (!self::enabled()) return '';
        $base = self::canonicalBase();
        return $base === '' ? '' : self::stableVaultUrlForBase($row, $base);
    }

    /**
     * Immutable payload URL for the current row version. It changes whenever
     * version/hash changes, so caching it cannot make the permanent resolver stale.
     */
    public static function versionedVaultUrl(array $row): string
    {
        if (!self::enabled()) return '';
        $base = self::canonicalBase();
        return $base === '' ? '' : self::versionedVaultUrlForBase($row, $base);
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
     * v1 version/hash-bound verifier retained for immutable payload URLs and old links.
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
        $row = self::currentVaultRow($fileId);
        $urls = self::purgeUrlsForRow($row, $fileId);
        $purged = self::purgeUrls($urls);
        $warmed = $row === null ? true : self::warmVaultRow($row);
        return $purged && $warmed;
    }

    public static function purgeVaultRow(array $row): bool
    {
        if (!self::enabled()) return true;
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) return false;

        self::rememberLegacyAlias($row);
        $purged = self::purgeUrls(self::purgeUrlsForRow($row, $id));

        // UploadManager/ChunkUploadManager calls this after replacement commits.
        // Warm the new immutable payload URL, never the permanent resolver URL.
        $current = self::currentVaultRow($id);
        $warmed = $current === null ? true : self::warmVaultRow($current);
        return $purged && $warmed;
    }

    /** Prime the current immutable payload at Cloudflare after upload/replace. */
    public static function warmVaultRow(array $row): bool
    {
        if (!self::enabled()) return true;
        $url = self::versionedVaultUrl($row);
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
            CURLOPT_USERAGENT => 'TeamDarkPanel-CDN-Warm/3.0',
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

        $lastStatus = 0;
        $lastMessage = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
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
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'TeamDarkPanel-CDN-Purge/3.0',
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $lastStatus = $status;

            if (is_string($response)) {
                $decoded = json_decode($response, true);
                if ($status >= 200 && $status < 300 && is_array($decoded) && ($decoded['success'] ?? false) === true) {
                    return true;
                }
                if (is_array($decoded) && isset($decoded['errors'][0]['message'])) {
                    $lastMessage = substr((string)$decoded['errors'][0]['message'], 0, 180);
                }
            } else {
                $lastMessage = $curlError !== '' ? 'network error' : 'empty response';
            }

            $retryable = $status === 0 || $status === 429 || $status >= 500;
            if (!$retryable) break;
            usleep(200000 * $attempt);
        }

        error_log('TeamDark CDN purge failed: Cloudflare HTTP '.$lastStatus.($lastMessage !== '' ? ' - '.$lastMessage : '').'.');
        return false;
    }

    private static function purgeUrlsForRow(?array $row, int $fileId): array
    {
        $urls = [];
        foreach (self::purgeBases() as $base) {
            $urls[] = $base.'/files/download?id='.$fileId;
            if ($row === null) continue;

            $v3 = self::stableVaultUrlForBase($row, $base);
            if ($v3 !== '') $urls[] = $v3;
            $v2 = self::legacyStableVaultUrlForBase($row, $base);
            if ($v2 !== '') $urls[] = $v2;
            $versioned = self::versionedVaultUrlForBase($row, $base);
            if ($versioned !== '') $urls[] = $versioned;
        }
        return array_values(array_unique($urls));
    }

    private static function purgeBases(): array
    {
        $bases = [];
        $canonical = self::canonicalBase();
        if ($canonical !== '') $bases[$canonical] = $canonical;
        $request = self::requestBase();
        if ($request !== '') $bases[$request] = $request;
        return array_values($bases);
    }

    private static function canonicalBase(): string
    {
        $base = rtrim((string)Config::get('app_url', ''), '/');
        return $base !== '' ? $base : self::requestBase();
    }

    private static function requestBase(): string
    {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: '';
        if ($host === '') return '';
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        $scheme = ($https !== '' && $https !== 'off') || $forwarded === 'https' ? 'https' : 'http';
        return $scheme.'://'.$host;
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

    private static function stableVaultUrlForBase(array $row, string $base): string
    {
        $id = (int)($row['id'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        if ($base === '' || $id <= 0 || $userId <= 0) return '';
        $signature = self::stableVaultSignature($id, $userId);
        return $signature === '' ? '' : rtrim($base, '/').'/cdn-files/'.$id.'/'.$signature.'/latest/asset.zip';
    }

    /** v2 permanent path kept only so old shared links can still resolve. */
    private static function legacyStableVaultUrlForBase(array $row, string $base): string
    {
        $id = (int)($row['id'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        if ($base === '' || $id <= 0 || $userId <= 0) return '';
        $signature = self::stableVaultSignature($id, $userId);
        return $signature === '' ? '' : rtrim($base, '/').'/cdn-files/'.$id.'/'.$signature.'/asset.zip';
    }

    private static function versionedVaultUrlForBase(array $row, string $base): string
    {
        $id = (int)($row['id'] ?? 0);
        $version = (int)($row['version'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        $sha = strtolower(trim((string)($row['sha256'] ?? '')));
        if ($base === '' || $id <= 0 || $version <= 0 || $userId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
            return '';
        }
        $signature = self::legacyVaultSignature($id, $version, $userId, $sha);
        return $signature === '' ? '' : rtrim($base, '/').'/cdn-files/'.$id.'/'.$version.'/'.$signature.'/asset.zip';
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
        } catch (Throwable) {
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
        } catch (Throwable) {
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
