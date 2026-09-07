<?php
declare(strict_types=1);

namespace TeamDark\Panel;

final class Security
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
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
        session_start();
    }

    public static function headers(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        if (self::isHttps()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
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
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    public static function rateLimit(string $bucket, int $max, int $windowSeconds): void
    {
        $pdo = Database::pdo();
        $identity = hash('sha256', $bucket . '|' . self::clientIp());
        $start = time() - $windowSeconds;
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM rate_limits WHERE touched_at < FROM_UNIXTIME(?)')->execute([$start]);
            $q = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket_hash=? FOR UPDATE');
            $q->execute([$identity]);
            $row = $q->fetch();
            if (!$row) {
                $pdo->prepare('INSERT INTO rate_limits(bucket_hash,hits,touched_at) VALUES(?,1,NOW())')->execute([$identity]);
            } else {
                if ((int)$row['hits'] >= $max) throw new \RuntimeException('Too many requests. Try again later.');
                $pdo->prepare('UPDATE rate_limits SET hits=hits+1,touched_at=NOW() WHERE bucket_hash=?')->execute([$identity]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function audit(?int $userId, string $action, array $meta = []): void
    {
        $stmt = Database::pdo()->prepare('INSERT INTO audit_logs(user_id,action,ip_address,user_agent,meta_json) VALUES(?,?,?,?,?)');
        $stmt->execute([
            $userId,
            substr($action, 0, 100),
            self::clientIp(),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
