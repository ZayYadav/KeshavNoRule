<?php
declare(strict_types=1);

namespace TeamDark\Panel;

final class Auth
{
    private const RANK = ['user'=>10, 'reseller'=>20, 'admin'=>30, 'owner'=>40];

    private static function passwordFingerprint(string $hash): string
    {
        return hash('sha256', $hash);
    }

    public static function user(): ?array
    {
        Security::startSession();
        $id = (int)($_SESSION['uid'] ?? 0);
        if ($id <= 0) return null;

        $q = Database::pdo()->prepare(
            'SELECT id,name,username,role,balance,telegram_chat_id,status,password_hash,created_at
             FROM users WHERE id=? LIMIT 1'
        );
        $q->execute([$id]);
        $u = $q->fetch();

        if (!$u || $u['status'] !== 'active') {
            Security::invalidateSession();
            return null;
        }

        // Password changes immediately revoke every older PHP web session.
        $sessionFingerprint = (string)($_SESSION['auth_password_fingerprint'] ?? '');
        $currentFingerprint = self::passwordFingerprint((string)$u['password_hash']);

        if (
            $sessionFingerprint === ''
            || !hash_equals($currentFingerprint, $sessionFingerprint)
        ) {
            Security::invalidateSession();
            return null;
        }

        unset($u['password_hash']);
        return $u;
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if (!$u) {
            header('Location: /login'); exit;
        }
        return $u;
    }

    public static function requireRole(array $user, string $minimum): void
    {
        if ((self::RANK[$user['role']] ?? 0) < (self::RANK[$minimum] ?? 999)) {
            http_response_code(403);
            throw new \RuntimeException('Forbidden.');
        }
    }

    public static function canManageRole(array $actor, string $targetRole): bool
    {
        $actorRank = self::RANK[$actor['role']] ?? 0;
        $targetRank = self::RANK[$targetRole] ?? 999;
        return $actor['role'] === 'owner' ? $targetRank < 40 : $targetRank < $actorRank;
    }

    public static function login(string $username, string $password): bool
    {
        $normalizedUsername = strtolower(trim($username));

        Security::rateLimit('web-login', 8, 600);
        if ($normalizedUsername !== '') {
            Security::rateLimit(
                'web-login-account',
                60,
                900,
                $normalizedUsername
            );
        }

        $q = Database::pdo()->prepare('SELECT * FROM users WHERE username=? LIMIT 1');
        $q->execute([$normalizedUsername]);
        $u = $q->fetch();

        $dummy = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        if (!$u) {
            password_verify($password, $dummy);
        }

        $ok = $u
            && $u['status'] === 'active'
            && password_verify($password, $u['password_hash']);

        if (!$ok) {
            Security::audit(
                $u ? (int)$u['id'] : null,
                'login_failed',
                ['username'=>substr($normalizedUsername, 0, 64)]
            );
            return false;
        }

        if (password_needs_rehash(
            (string)$u['password_hash'],
            defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT
        )) {
            $newHash = Security::passwordHash($password);
            Database::pdo()->prepare(
                'UPDATE users SET password_hash=? WHERE id=?'
            )->execute([$newHash, $u['id']]);
            $u['password_hash'] = $newHash;
        }

        Security::startSession();
        session_regenerate_id(true);

        if (PanelControl::blocked($u)) {
            Security::audit((int)$u['id'], 'login_maintenance_blocked');
            throw new \RuntimeException(PanelControl::settings()['message']);
        }

        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['auth_password_fingerprint'] =
            self::passwordFingerprint((string)$u['password_hash']);
        $_SESSION['session_started_at'] = time();
        $_SESSION['session_rotated_at'] = time();
        $_SESSION['last_activity'] = time();

        if ($normalizedUsername !== '') {
            Security::clearRateLimit('web-login-account', $normalizedUsername);
        }

        Database::pdo()->prepare(
            'UPDATE users SET last_login_at=NOW(), last_login_ip=? WHERE id=?'
        )->execute([Security::clientIp(), $u['id']]);

        Security::audit((int)$u['id'], 'login_success');
        return true;
    }

    public static function logout(): void
    {
        $u = self::user();
        Security::audit($u ? (int)$u['id'] : null, 'logout');
        Security::destroySession();
    }
}
