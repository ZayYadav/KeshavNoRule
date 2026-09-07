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
            'app_name' => $get('APP_NAME', 'TeamDark Panel'),
            'app_url' => rtrim($get('APP_URL', ''), '/'),
            'app_key' => $get('APP_KEY'),
            'db_host' => $get('DB_HOST', '127.0.0.1'),
            'db_port' => $get('DB_PORT', '3306'),
            'db_name' => $get('DB_NAME', 'teamdark_panel'),
            'db_user' => $get('DB_USER', 'root'),
            'db_pass' => $get('DB_PASS', ''),
            'session_name' => $get('SESSION_NAME', 'TDSESSID'),
            'referrer_bonus' => max(0, (int)$get('REFERRER_BONUS', '5')),
            'signup_bonus' => max(0, (int)$get('SIGNUP_BONUS', '2')),
            'key_cost' => max(0, (int)$get('KEY_COST', '1')),
            'token_ttl' => max(300, (int)$get('API_TOKEN_TTL', '86400')),
        ];

        if (self::$data['app_key'] === '') {
            throw new \RuntimeException('APP_KEY is required. Generate 32 random bytes and store as base64 in .env.');
        }
    }

    public static function get(string $key, $default = null)
    {
        return self::$data[$key] ?? $default;
    }
}
