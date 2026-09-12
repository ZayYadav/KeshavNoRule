<?php
declare(strict_types=1);

namespace TeamDark\Panel;

final class Config
{
    private static array $data = [];

    public static function load(string $root): void
    {
        if (self::$data) return;

        $env = $root . '/.env';
        if (is_file($env)) {
            foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || substr($line, 0, 1) === '#' || strpos($line, '=') === false) {
                    continue;
                }

                [$k, $v] = array_map('trim', explode('=', $line, 2));

                $first = substr($v, 0, 1);
                $last = substr($v, -1);
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $v = substr($v, 1, -1);
                }

                if ($k !== '' && getenv($k) === false) {
                    putenv($k . '=' . $v);
                }
            }
        }

        $get = static function (string $k, string $d = ''): string {
            $value = getenv($k);
            return (string)($value !== false ? $value : $d);
        };

        self::$data = [
            'app_name' => $get('APP_NAME', 'No Rule Panel'),
            'app_url' => rtrim($get('APP_URL', ''), '/'),
            'app_key' => $get('APP_KEY'),
            'db_host' => $get('DB_HOST', '127.0.0.1'),
            'db_port' => $get('DB_PORT', '3306'),
            'db_name' => $get('DB_NAME', 'norule_panel'),
            'db_user' => $get('DB_USER', 'root'),
            'db_pass' => $get('DB_PASS', ''),
            'session_name' => $get('SESSION_NAME', 'NRSESSID'),
            'session_idle_seconds' => max(300, (int)$get('SESSION_IDLE_SECONDS', '1800')),
            'session_rotate_seconds' => max(300, (int)$get('SESSION_ROTATE_SECONDS', '900')),
            'session_absolute_seconds' => max(1800, min(604800, (int)$get('SESSION_ABSOLUTE_SECONDS', '43200'))),
            'referrer_bonus' => max(0, (int)$get('REFERRER_BONUS', '5')),
            'signup_bonus' => max(0, (int)$get('SIGNUP_BONUS', '2')),
            'registration_bonuses_enabled' => strtolower($get('REGISTRATION_BONUSES_ENABLED', 'false')) === 'true',
            // KEY_COST is treated as the price per started 24-hour period.
            'key_cost' => max(0, (int)$get('KEY_COST', '1')),
            'unlimited_key_cost' => max(0, (int)$get('UNLIMITED_KEY_COST', '100')),
            // Preferred No Rule variable first; the TeamDark name remains a compatibility fallback
            // so an existing native client/server deployment can be migrated without downtime.
            'teamdark_auth_secret' => $get('NORULE_AUTH_SECRET', $get('TEAMDARK_AUTH_SECRET', '')),
            'telegram_bot_token' => $get('TELEGRAM_BOT_TOKEN', ''),
            'telegram_webhook_secret' => $get('TELEGRAM_WEBHOOK_SECRET', ''),
            'telegram_owner_chat_id' => trim($get('TELEGRAM_OWNER_CHAT_ID', '')),
            'telegram_owner_mutations_enabled' => strtolower($get('TELEGRAM_OWNER_MUTATIONS_ENABLED', 'false')) === 'true',
            'telegram_linked_key_generation_enabled' => strtolower($get('TELEGRAM_LINKED_KEY_GENERATION_ENABLED', 'false')) === 'true',
            'legacy_license_api_enabled' => strtolower($get('LEGACY_LICENSE_API_ENABLED', 'false')) === 'true',
            'api_reveal_license_keys' => strtolower($get('API_REVEAL_LICENSE_KEYS', 'false')) === 'true',
            'telegram_owner_sensitive_reads_enabled' => strtolower($get('TELEGRAM_OWNER_SENSITIVE_READS_ENABLED', 'false')) === 'true',
            'telegram_guest_free_keys_enabled' => strtolower($get('TELEGRAM_GUEST_FREE_KEYS_ENABLED', 'false')) === 'true',
            'admin_balance_adjustments_enabled' => strtolower($get('ADMIN_BALANCE_ADJUSTMENTS_ENABLED', 'false')) === 'true',
            'admin_balance_daily_limit' => max(0, min(1000000, (int)$get('ADMIN_BALANCE_DAILY_LIMIT', '10000'))),
            'admin_referral_bonus_enabled' => strtolower($get('ADMIN_REFERRAL_BONUS_ENABLED', 'false')) === 'true',
            'audit_retention_days' => max(7, min(3650, (int)$get('AUDIT_RETENTION_DAYS', '90'))),
            'broadcast_retention_days' => max(7, min(3650, (int)$get('BROADCAST_RETENTION_DAYS', '30'))),
            'trusted_proxy_cidrs' => trim($get('TRUSTED_PROXY_CIDRS', '')),
            'token_ttl' => max(300, min(604800, (int)$get('API_TOKEN_TTL', '86400'))),
            'cloudflare_cache_enabled' => strtolower($get('CLOUDFLARE_CACHE_ENABLED', 'false')) === 'true',
            'cloudflare_zone_id' => trim($get('CLOUDFLARE_ZONE_ID', '')),
            'cloudflare_api_token' => trim($get('CLOUDFLARE_API_TOKEN', '')),
            'cdn_file_cache_seconds' => max(300, min(604800, (int)$get('CDN_FILE_CACHE_SECONDS', '86400'))),
        ];

        if (self::$data['app_key'] === '') {
            throw new \RuntimeException('APP_KEY is required. Generate 32 random bytes and store as base64 in .env.');
        }

        $decodedAppKey = base64_decode((string)self::$data['app_key'], true);
        if ($decodedAppKey === false || strlen($decodedAppKey) < 32) {
            throw new \RuntimeException('APP_KEY must be valid base64 containing at least 32 random bytes.');
        }

        $nativeSecret = (string)self::$data['teamdark_auth_secret'];
        if ($nativeSecret !== '' && strlen($nativeSecret) < 24) {
            throw new \RuntimeException('NORULE_AUTH_SECRET is too short.');
        }

        $webhookSecret = (string)self::$data['telegram_webhook_secret'];
        if (
            $webhookSecret !== ''
            && (
                strlen($webhookSecret) < 24
                || strlen($webhookSecret) > 256
                || !preg_match('/^[A-Za-z0-9_-]+$/', $webhookSecret)
            )
        ) {
            throw new \RuntimeException('TELEGRAM_WEBHOOK_SECRET must be 24-256 characters using A-Z, a-z, 0-9, underscore or dash.');
        }
    }

    public static function get(string $key, $default = null)
    {
        return self::$data[$key] ?? $default;
    }
}
