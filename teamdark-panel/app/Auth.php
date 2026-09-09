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
                    auth_version,login_not_before,
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
        $sessionVersion = (int)($_SESSION['auth_version'] ?? 0);
        $currentVersion = max(1, (int)($u['auth_version'] ?? 1));

        if (
            $sessionFingerprint === ''
            || !hash_equals($currentFingerprint, $sessionFingerprint)
            || $sessionVersion !== $currentVersion
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

        $q = Database::pdo()->prepare(
            'SELECT * FROM users WHERE username=? LIMIT 1'
        );
        $q->execute([$normalizedUsername]);
        $u = $q->fetch();

        // Keep missing, disabled and active accounts on the same Argon2id verification path.
        $dummy = '$argon2id$v=19$m=65536,t=4,p=1$b1E4Q0tMM1pacUxwVkovMA$/X40szipuNg1RTdnxJnZppqKebCsj0QdtO2LokHSdSo';
        $verifyHash = $u && !empty($u['password_hash'])
            ? (string)$u['password_hash']
            : $dummy;
        $passwordOk = password_verify($password, $verifyHash);
        $ok = $u && $u['status'] === 'active' && $passwordOk;

        if (
            $ok
            && !empty($u['login_not_before'])
            && strtotime((string)$u['login_not_before']) > time()
        ) {
            Security::audit((int)$u['id'], 'login_delayed', [
                'not_before'=>$u['login_not_before'],
            ]);
            throw new \RuntimeException('Account activation delay is still active. Try again shortly.');
        }

        if (!$ok) {
            if ($normalizedUsername !== '') {
                // Consume the account bucket only after a failed credential check.
                // A correct password is never blocked by failures from another attacker/IP.
                Security::rateLimit(
                    $ratePrefix.'-account-failures',
                    12,
                    900,
                    $normalizedUsername
                );
            }

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
            Security::clearRateLimit($ratePrefix.'-account-failures', $normalizedUsername);
        }

        try {
            Security::audit((int)$u['id'], 'password_verified', [
                'second_factor_required'=>(int)($u['telegram_2fa_enabled'] ?? 0) === 1,
            ]);
        } catch (\Throwable) {
        }

        return $u;
    }

    public static function verifyCurrentPassword(int $userId, string $password): bool
    {
        if ($userId <= 0 || strlen($password) > 200) {
            return false;
        }

        Security::rateLimit('current-password-ip', 12, 900);
        Security::rateLimit('current-password-user', 8, 900, (string)$userId);

        $q = Database::pdo()->prepare(
            'SELECT password_hash,status FROM users WHERE id=? LIMIT 1'
        );
        $q->execute([$userId]);
        $row = $q->fetch();

        $dummy = '$argon2id$v=19$m=65536,t=4,p=1$b1E4Q0tMM1pacUxwVkovMA$/X40szipuNg1RTdnxJnZppqKebCsj0QdtO2LokHSdSo';
        $valid = password_verify(
            $password,
            $row && !empty($row['password_hash']) ? (string)$row['password_hash'] : $dummy
        );

        if (!$row || $row['status'] !== 'active' || !$valid) {
            Security::audit($row ? $userId : null, 'current_password_failed');
            return false;
        }

        Security::clearRateLimit('current-password-user', (string)$userId);
        self::markRecentAuth();
        return true;
    }

    public static function completeLogin(array $user): void
    {
        $userId = (int)($user['id'] ?? 0);

        $q = Database::pdo()->prepare(
            'SELECT id,name,username,role,balance,telegram_chat_id,
                    telegram_2fa_enabled,telegram_2fa_enabled_at,
                    auth_version,login_not_before,
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
        $_SESSION['auth_version'] = max(1, (int)($u['auth_version'] ?? 1));
        $_SESSION['recent_auth_at'] = time();
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

    public static function bumpAuthVersion(
        int $userId,
        bool $revokeApiTokens = true
    ): int {
        if ($userId <= 0) {
            throw new \RuntimeException('Invalid user.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'UPDATE users SET auth_version=auth_version+1 WHERE id=?'
            )->execute([$userId]);

            if ($revokeApiTokens) {
                $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')
                    ->execute([$userId]);
            }

            $q = $pdo->prepare(
                'SELECT auth_version FROM users WHERE id=? LIMIT 1'
            );
            $q->execute([$userId]);
            $version = max(1, (int)$q->fetchColumn());
            $pdo->commit();

            return $version;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function refreshCurrentSessionVersion(int $userId, int $version): void
    {
        Security::startSession();
        if ((int)($_SESSION['uid'] ?? 0) === $userId) {
            $_SESSION['auth_version'] = max(1, $version);
            $_SESSION['recent_auth_at'] = time();
            session_regenerate_id(true);
        }
    }

    public static function refreshCurrentSessionCredentials(
        int $userId,
        int $version,
        string $passwordHash
    ): void {
        Security::startSession();

        if ((int)($_SESSION['uid'] ?? 0) !== $userId) {
            return;
        }

        $_SESSION['auth_version'] = max(1, $version);
        $_SESSION['auth_password_fingerprint'] =
            self::passwordFingerprint($passwordHash);
        $_SESSION['recent_auth_at'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        session_regenerate_id(true);
    }

    public static function markRecentAuth(): void
    {
        Security::startSession();
        $_SESSION['recent_auth_at'] = time();
        session_regenerate_id(true);
    }

    public static function recentlyAuthenticated(int $seconds = 300): bool
    {
        Security::startSession();
        $ts = (int)($_SESSION['recent_auth_at'] ?? 0);
        return $ts > 0 && (time() - $ts) <= max(60, $seconds);
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
