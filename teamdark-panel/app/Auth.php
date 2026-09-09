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
            'SELECT id,name,username,role,balance,telegram_chat_id,
                    telegram_2fa_enabled,telegram_2fa_enabled_at,
                    status,password_hash,created_at
             FROM users WHERE id=? LIMIT 1'
        );
        $q->execute([$id]);
        $u = $q->fetch();

        if (!$u || $u['status'] !== 'active') {
            Security::invalidateSession();
            return null;
        }

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
            header('Location: /login');
            exit;
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

    public static function verifyPasswordCredentials(
        string $username,
        string $password,
        string $ratePrefix = 'web-login'
    ): ?array {
        $normalizedUsername = strtolower(trim($username));

        Security::rateLimit($ratePrefix, 8, 600);

        if (
            strlen($normalizedUsername) > 64
            || strlen($password) > 200
            || str_contains($normalizedUsername, "\0")
        ) {
            return null;
        }

        if ($normalizedUsername !== '') {
            Security::rateLimit(
                $ratePrefix.'-account',
                60,
                900,
                $normalizedUsername
            );
        }

        $q = Database::pdo()->prepare(
            'SELECT * FROM users WHERE username=? LIMIT 1'
        );
        $q->execute([$normalizedUsername]);
        $u = $q->fetch();

        $dummy = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

        if (!$u) {
            password_verify($password, $dummy);
        }

        $ok = $u
            && $u['status'] === 'active'
            && password_verify($password, (string)$u['password_hash']);

        if (!$ok) {
            Security::audit(
                $u ? (int)$u['id'] : null,
                $ratePrefix === 'api-login' ? 'api_login_failed' : 'login_failed',
                ['username'=>substr($normalizedUsername, 0, 64)]
            );
            return null;
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

        if (PanelControl::blocked($u)) {
            Security::audit((int)$u['id'], 'login_maintenance_blocked');
            throw new \RuntimeException(PanelControl::settings()['message']);
        }

        if ($normalizedUsername !== '') {
            Security::clearRateLimit($ratePrefix.'-account', $normalizedUsername);
        }

        try {
            Security::audit((int)$u['id'], 'password_verified', [
                'second_factor_required'=>(int)($u['telegram_2fa_enabled'] ?? 0) === 1,
            ]);
        } catch (\Throwable) {
        }

        return $u;
    }

    public static function completeLogin(array $user): void
    {
        $userId = (int)($user['id'] ?? 0);

        $q = Database::pdo()->prepare(
            'SELECT id,name,username,role,balance,telegram_chat_id,
                    telegram_2fa_enabled,telegram_2fa_enabled_at,
                    status,password_hash,created_at
             FROM users
             WHERE id=?
             LIMIT 1'
        );
        $q->execute([$userId]);
        $u = $q->fetch();

        if (!$u || $u['status'] !== 'active') {
            throw new \RuntimeException('Account is unavailable.');
        }

        if (PanelControl::blocked($u)) {
            throw new \RuntimeException(PanelControl::settings()['message']);
        }

        Security::startSession();
        session_regenerate_id(true);

        unset($_SESSION['pending_2fa']);

        $_SESSION['uid'] = $userId;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['auth_password_fingerprint'] =
            self::passwordFingerprint((string)$u['password_hash']);
        $_SESSION['session_started_at'] = time();
        $_SESSION['session_rotated_at'] = time();
        $_SESSION['last_activity'] = time();

        Database::pdo()->prepare(
            'UPDATE users SET last_login_at=NOW(), last_login_ip=? WHERE id=?'
        )->execute([Security::clientIp(), $userId]);

        Security::audit($userId, 'login_success', [
            'two_factor'=>(int)$u['telegram_2fa_enabled'] === 1,
        ]);
    }

    public static function beginTwoFactorSession(array $challenge): void
    {
        $userId = (int)($challenge['user_id'] ?? 0);
        $challengeId = (int)($challenge['challenge_id'] ?? 0);
        $expiresTs = (int)($challenge['expires_ts'] ?? 0);

        if ($userId <= 0 || $challengeId <= 0 || $expiresTs <= time()) {
            throw new \RuntimeException('Could not start two-factor authentication.');
        }

        Security::startSession();
        session_regenerate_id(true);

        unset(
            $_SESSION['uid'],
            $_SESSION['auth_password_fingerprint']
        );

        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['pending_2fa'] = [
            'user_id'=>$userId,
            'challenge_id'=>$challengeId,
            'expires_ts'=>$expiresTs,
        ];
        $_SESSION['session_rotated_at'] = time();
        $_SESSION['last_activity'] = time();
    }

    public static function pendingTwoFactor(): ?array
    {
        Security::startSession();

        $pending = $_SESSION['pending_2fa'] ?? null;

        if (
            !is_array($pending)
            || (int)($pending['user_id'] ?? 0) <= 0
            || (int)($pending['challenge_id'] ?? 0) <= 0
            || (int)($pending['expires_ts'] ?? 0) <= time()
        ) {
            unset($_SESSION['pending_2fa']);
            return null;
        }

        return $pending;
    }

    public static function clearPendingTwoFactor(): void
    {
        Security::startSession();
        unset($_SESSION['pending_2fa']);
    }

    public static function login(string $username, string $password): bool
    {
        $u = self::verifyPasswordCredentials($username, $password, 'web-login');

        if (!$u) {
            return false;
        }

        if ((int)($u['telegram_2fa_enabled'] ?? 0) === 1) {
            throw new \RuntimeException('Two-factor authentication required.');
        }

        self::completeLogin($u);
        return true;
    }

    public static function logout(): void
    {
        try {
            $u = self::user();
            try {
                Security::audit($u ? (int)$u['id'] : null, 'logout');
            } catch (\Throwable) {
            }
        } finally {
            Security::destroySession();
        }
    }
}
