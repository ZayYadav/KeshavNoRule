<?php
declare(strict_types=1);

namespace TeamDark\Panel;

require_once __DIR__.'/PanelControl.php';

final class Security
{
    private static bool $housekeepingAttempted = false;
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::touchSession();
            return;
        }

        session_name((string)Config::get('session_name'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        session_start();
        self::touchSession();
    }

    private static function touchSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $now = time();
        $idle = max(300, (int)Config::get('session_idle_seconds', 1800));
        $rotate = max(300, (int)Config::get('session_rotate_seconds', 900));
        $absolute = max(1800, (int)Config::get('session_absolute_seconds', 43200));

        $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
        $startedAt = (int)($_SESSION['session_started_at'] ?? 0);

        if (
            ($lastActivity > 0 && ($now - $lastActivity) > $idle)
            || ($startedAt > 0 && ($now - $startedAt) > $absolute)
        ) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        $lastRotate = (int)($_SESSION['session_rotated_at'] ?? 0);
        if ($lastRotate === 0) {
            $_SESSION['session_rotated_at'] = $now;
        } elseif (($now - $lastRotate) >= $rotate) {
            session_regenerate_id(true);
            $_SESSION['session_rotated_at'] = $now;
        }

        if (empty($_SESSION['session_started_at'])) {
            $_SESSION['session_started_at'] = $now;
        }

        $_SESSION['last_activity'] = $now;
    }

    public static function invalidateSession(): void
    {
        self::startSession();
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['session_started_at'] = time();
        $_SESSION['session_rotated_at'] = time();
        $_SESSION['last_activity'] = time();
    }

    public static function destroySession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSession();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => (string)($params['path'] ?? '/'),
                'domain' => (string)($params['domain'] ?? ''),
                'secure' => (bool)($params['secure'] ?? self::isHttps()),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }

        session_destroy();
    }

    public static function headers(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
        header("Content-Security-Policy: default-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; connect-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'");
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf'];
    }

    public static function verifyCsrf(?string $token): void
    {
        self::startSession();
        $known = (string)($_SESSION['csrf'] ?? '');
        if ($known === '' || $token === null || !hash_equals($known, $token)) {
            throw new \RuntimeException('Invalid security token.');
        }
    }

    public static function passwordHash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($password, $algo);
    }

    public static function clientIp(): string
    {
        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }

        if (!self::trustedProxy($remote)) {
            return $remote;
        }

        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
            return $cf;
        }

        $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff !== '') {
            $first = trim(explode(',', $xff, 2)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        return $remote;
    }

    private static function trustedProxy(string $remote): bool
    {
        $raw = trim((string)Config::get('trusted_proxy_cidrs', ''));
        if ($raw === '') {
            return false;
        }

        foreach (preg_split('/\\s*,\\s*/', $raw) ?: [] as $cidr) {
            if ($cidr !== '' && self::ipInCidr($remote, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return hash_equals($cidr, $ip);
        }

        [$network, $prefixRaw] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton(trim($network));

        if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        $prefix = filter_var($prefixRaw, FILTER_VALIDATE_INT, [
            'options'=>['min_range'=>0,'max_range'=>$maxBits],
        ]);

        if ($prefix === false) {
            return false;
        }

        $whole = intdiv((int)$prefix, 8);
        $remain = (int)$prefix % 8;

        if ($whole > 0 && substr($ipBin, 0, $whole) !== substr($networkBin, 0, $whole)) {
            return false;
        }

        if ($remain === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remain)) & 0xFF;
        return (ord($ipBin[$whole]) & $mask) === (ord($networkBin[$whole]) & $mask);
    }

    private static function rateLimitHash(string $bucket, ?string $subject = null): string
    {
        $identity = $subject === null ? self::clientIp() : trim($subject);
        return hash('sha256', $bucket.'|'.$identity);
    }

    public static function rateLimit(
        string $bucket,
        int $max,
        int $windowSeconds,
        ?string $subject = null
    ): void {
        $pdo = Database::pdo();
        $identity = self::rateLimitHash($bucket, $subject);
        $max = max(1, $max);
        $start = time() - max(1, $windowSeconds);

        $pdo->beginTransaction();
        try {
            // INSERT IGNORE closes the concurrent "missing row" race.
            $pdo->prepare(
                'INSERT IGNORE INTO rate_limits(bucket_hash,hits,touched_at) VALUES(?,0,NOW())'
            )->execute([$identity]);

            $q = $pdo->prepare(
                'SELECT hits,UNIX_TIMESTAMP(touched_at) AS touched_ts
                 FROM rate_limits
                 WHERE bucket_hash=?
                 FOR UPDATE'
            );
            $q->execute([$identity]);
            $row = $q->fetch();

            if (!$row) {
                throw new \RuntimeException('Rate limit state unavailable.');
            }

            if ((int)$row['touched_ts'] < $start) {
                $pdo->prepare(
                    'UPDATE rate_limits SET hits=1,touched_at=NOW() WHERE bucket_hash=?'
                )->execute([$identity]);
            } else {
                if ((int)$row['hits'] >= $max) {
                    throw new \RuntimeException('Too many requests. Try again later.');
                }

                $pdo->prepare(
                    'UPDATE rate_limits SET hits=hits+1,touched_at=NOW() WHERE bucket_hash=?'
                )->execute([$identity]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Small bounded cleanup avoids a full-table DELETE on every request.
        try {
            if (random_int(1, 100) === 1) {
                $pdo->prepare(
                    'DELETE FROM rate_limits WHERE touched_at < DATE_SUB(NOW(), INTERVAL 2 DAY) LIMIT 500'
                )->execute();
            }
        } catch (\Throwable) {
            // Cleanup is optional and must never fail the request.
        }
    }

    public static function clearRateLimit(string $bucket, ?string $subject = null): void
    {
        Database::pdo()->prepare(
            'DELETE FROM rate_limits WHERE bucket_hash=?'
        )->execute([self::rateLimitHash($bucket, $subject)]);
    }

    public static function audit(?int $userId, string $action, array $meta = []): void
    {
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO audit_logs(user_id,action,ip_address,user_agent,meta_json) VALUES(?,?,?,?,?)'
            );
            $stmt->execute([
                $userId,
                substr($action, 0, 100),
                self::clientIp(),
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // Security logging must never become an availability kill-switch.
            error_log(
                'TeamDark audit write failed: '
                .get_class($e)
                .' action='
                .substr($action, 0, 100)
            );
            return;
        }

        self::housekeepingMaybe();
    }

    private static function housekeepingMaybe(): void
    {
        if (self::$housekeepingAttempted) {
            return;
        }

        self::$housekeepingAttempted = true;

        try {
            if (random_int(1, 200) !== 1) {
                return;
            }

            $pdo = Database::pdo();

            $pdo->exec(
                "DELETE FROM audit_logs
                 WHERE created_at<DATE_SUB(NOW(), INTERVAL 180 DAY)
                 LIMIT 1000"
            );
            $pdo->exec(
                "DELETE FROM rate_limits
                 WHERE touched_at<DATE_SUB(NOW(), INTERVAL 2 DAY)
                 LIMIT 1000"
            );
            $pdo->exec(
                "DELETE FROM api_tokens
                 WHERE expires_at<NOW()
                 LIMIT 1000"
            );
            $pdo->exec(
                "DELETE FROM telegram_link_tokens
                 WHERE expires_at<DATE_SUB(NOW(), INTERVAL 1 DAY)
                    OR used_at<DATE_SUB(NOW(), INTERVAL 1 DAY)
                 LIMIT 1000"
            );
            $pdo->exec(
                "DELETE FROM telegram_2fa_activation_tokens
                 WHERE expires_at<DATE_SUB(NOW(), INTERVAL 1 DAY)
                    OR used_at<DATE_SUB(NOW(), INTERVAL 1 DAY)
                 LIMIT 1000"
            );
            $pdo->exec(
                "DELETE FROM login_2fa_challenges
                 WHERE expires_at<DATE_SUB(NOW(), INTERVAL 1 DAY)
                    OR used_at<DATE_SUB(NOW(), INTERVAL 1 DAY)
                 LIMIT 1000"
            );
            $pdo->exec(
                "DELETE FROM announcement_broadcasts
                 WHERE status IN ('completed','partial','cancelled')
                   AND created_at<DATE_SUB(NOW(), INTERVAL 90 DAY)
                 LIMIT 100"
            );
        } catch (\Throwable $e) {
            error_log(
                'TeamDark housekeeping failed: '.get_class($e)
            );
        }
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // APP_URL is server-controlled and avoids relying only on a spoofable proxy header.
        $appUrl = strtolower((string)Config::get('app_url', ''));
        if (str_starts_with($appUrl, 'https://')) {
            return true;
        }

        return self::trustedProxy($remote)
            && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
