<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use Throwable;

final class CdnCache
{
    public static function enabled(): bool
    {
        return (bool)Config::get('cloudflare_cache_enabled', false);
    }

    public static function cacheSeconds(): int
    {
        return max(300, min(604800, (int)Config::get('cdn_file_cache_seconds', 86400)));
    }

    public static function vaultUrl(array $row): string
    {
        if (!self::enabled()) return '';

        $base = rtrim((string)Config::get('app_url', ''), '/');
        $id = (int)($row['id'] ?? 0);
        $version = (int)($row['version'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        $sha = strtolower(trim((string)($row['sha256'] ?? '')));
        if ($base === '' || $id <= 0 || $version <= 0 || $userId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
            return '';
        }

        $signature = self::vaultSignature($id, $version, $userId, $sha);
        if ($signature === '') return '';

        return $base.'/cdn-files/'.$id.'/'.$version.'/'.$signature.'/asset.zip';
    }

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

        $expected = self::vaultSignature($id, $version, $userId, $sha);
        return $expected !== '' && hash_equals($expected, $signature);
    }

    public static function purgeVaultFile(int $fileId): bool
    {
        if (!self::enabled() || $fileId <= 0) return true;
        $base = rtrim((string)Config::get('app_url', ''), '/');
        if ($base === '') {
            error_log('TeamDark CDN purge skipped: APP_URL is empty.');
            return false;
        }
        return self::purgeUrls([$base.'/files/download?id='.$fileId]);
    }

    public static function purgeVaultRow(array $row): bool
    {
        if (!self::enabled()) return true;
        $id = (int)($row['id'] ?? 0);
        $base = rtrim((string)Config::get('app_url', ''), '/');
        if ($id <= 0 || $base === '') return false;

        $urls = [$base.'/files/download?id='.$id];
        $cdn = self::vaultUrl($row);
        if ($cdn !== '') $urls[] = $cdn;
        return self::purgeUrls($urls);
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

    private static function vaultSignature(int $id, int $version, int $userId, string $sha): string
    {
        $raw = base64_decode((string)Config::get('app_key', ''), true);
        if ($raw === false || strlen($raw) < 32) return '';
        $key = hash_hmac('sha256', 'TeamDark CDN signed file v1', $raw, true);
        return hash_hmac('sha256', $id.'|'.$version.'|'.$userId.'|'.$sha, $key);
    }
}
