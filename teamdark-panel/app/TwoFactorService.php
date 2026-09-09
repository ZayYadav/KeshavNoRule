<?php
declare(strict_types=1);

namespace TeamDark\Panel;

require_once __DIR__.'/Auth.php';

use RuntimeException;
use Throwable;

final class TwoFactorService
{
    private const ACTIVATION_TTL = 600;
    private const LOGIN_TTL = 300;
    private const MAX_LOGIN_ATTEMPTS = 5;

    public static function enabled(array $user): bool
    {
        if (array_key_exists('telegram_2fa_enabled', $user)) {
            return (int)$user['telegram_2fa_enabled'] === 1;
        }

        $q = Database::pdo()->prepare(
            'SELECT telegram_2fa_enabled FROM users WHERE id=? LIMIT 1'
        );
        $q->execute([(int)$user['id']]);

        return (int)$q->fetchColumn() === 1;
    }

    public static function createActivationToken(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        $chatId = (int)($user['telegram_chat_id'] ?? 0);

        if ($userId <= 0 || $chatId <= 0) {
            throw new RuntimeException('Link your Telegram account before enabling 2FA.');
        }

        if (self::enabled($user)) {
            throw new RuntimeException('Telegram 2FA is already enabled.');
        }

        Security::rateLimit('2fa-activation-user', 6, 3600, (string)$userId);

        $pdo = Database::pdo();
        $pdo->prepare(
            'DELETE FROM telegram_2fa_activation_tokens
             WHERE user_id=? OR expires_at<=NOW() OR used_at IS NOT NULL'
        )->execute([$userId]);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = 'TD2FA-'.strtoupper(bin2hex(random_bytes(6)));
            $hash = Crypto::fingerprint('2fa-activation|'.$userId.'|'.$code);

            try {
                $pdo->prepare(
                    'INSERT INTO telegram_2fa_activation_tokens(
                        user_id,code_hash,expires_at
                     ) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
                )->execute([$userId, $hash]);

                try {
                    Security::audit($userId, '2fa_activation_key_issued');
                } catch (Throwable) {
                }

                return [
                    'code'=>$code,
                    'expires_at'=>date('Y-m-d H:i:s', time() + self::ACTIVATION_TTL),
                ];
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Could not create a 2FA activation key.');
    }

    public static function activate(array $user, string $rawCode): void
    {
        $userId = (int)($user['id'] ?? 0);
        $chatId = (int)($user['telegram_chat_id'] ?? 0);
        $code = strtoupper(trim($rawCode));

        if ($userId <= 0 || $chatId <= 0) {
            throw new RuntimeException('Link your Telegram account before enabling 2FA.');
        }

        if (!preg_match('/^TD2FA-[A-F0-9]{12}$/', $code)) {
            throw new RuntimeException('Invalid 2FA activation key.');
        }

        Security::rateLimit('2fa-activation-verify-user', 10, 900, (string)$userId);

        $hash = Crypto::fingerprint('2fa-activation|'.$userId.'|'.$code);
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $q = $pdo->prepare(
                'SELECT id
                 FROM telegram_2fa_activation_tokens
                 WHERE user_id=?
                   AND code_hash=?
                   AND used_at IS NULL
                   AND expires_at>NOW()
                 LIMIT 1
                 FOR UPDATE'
            );
            $q->execute([$userId, $hash]);
            $tokenId = (int)$q->fetchColumn();

            if ($tokenId <= 0) {
                throw new RuntimeException('2FA activation key is invalid or expired.');
            }

            $pdo->prepare(
                'UPDATE users
                 SET telegram_2fa_enabled=1,
                     telegram_2fa_enabled_at=NOW()
                 WHERE id=? AND telegram_chat_id IS NOT NULL'
            )->execute([$userId]);

            $pdo->prepare(
                'UPDATE telegram_2fa_activation_tokens
                 SET used_at=NOW()
                 WHERE id=?'
            )->execute([$tokenId]);

            $pdo->prepare(
                'DELETE FROM telegram_2fa_activation_tokens
                 WHERE user_id=? AND id<>?'
            )->execute([$userId, $tokenId]);

            $pdo->commit();

            $newVersion = Auth::bumpAuthVersion($userId, true);
            Auth::refreshCurrentSessionVersion($userId, $newVersion);

            Security::clearRateLimit('2fa-activation-verify-user', (string)$userId);

            try {
                Security::audit($userId, '2fa_enabled', [
                    'telegram_chat_suffix'=>substr((string)$chatId, -4),
                ]);
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function disable(array $user, string $password): void
    {
        $userId = (int)($user['id'] ?? 0);

        Security::rateLimit('2fa-disable-user', 8, 900, (string)$userId);

        $q = Database::pdo()->prepare(
            'SELECT password_hash,telegram_2fa_enabled
             FROM users
             WHERE id=? AND status=\'active\'
             LIMIT 1'
        );
        $q->execute([$userId]);
        $row = $q->fetch();

        if (
            !$row
            || (int)$row['telegram_2fa_enabled'] !== 1
            || !password_verify($password, (string)$row['password_hash'])
        ) {
            throw new RuntimeException('Password is incorrect.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'UPDATE users
                 SET telegram_2fa_enabled=0,
                     telegram_2fa_enabled_at=NULL
                 WHERE id=?'
            )->execute([$userId]);

            $pdo->prepare(
                'DELETE FROM telegram_2fa_activation_tokens WHERE user_id=?'
            )->execute([$userId]);

            $pdo->prepare(
                'DELETE FROM login_2fa_challenges WHERE user_id=?'
            )->execute([$userId]);

            $pdo->commit();

            $newVersion = Auth::bumpAuthVersion($userId, true);
            Auth::refreshCurrentSessionVersion($userId, $newVersion);

            try {
                Security::audit($userId, '2fa_disabled');
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function forceDisable(int $userId, ?int $actorUserId = null): void
    {
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'UPDATE users
                 SET telegram_2fa_enabled=0,
                     telegram_2fa_enabled_at=NULL
                 WHERE id=?'
            )->execute([$userId]);
            $pdo->prepare('DELETE FROM telegram_2fa_activation_tokens WHERE user_id=?')
                ->execute([$userId]);
            $pdo->prepare('DELETE FROM login_2fa_challenges WHERE user_id=?')
                ->execute([$userId]);
            $pdo->commit();

            Auth::bumpAuthVersion($userId, true);

            try {
                Security::audit($actorUserId, '2fa_owner_reset', [
                    'target_id'=>$userId,
                ]);
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function startLoginChallenge(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        $chatId = (int)($user['telegram_chat_id'] ?? 0);

        if ($userId <= 0 || $chatId <= 0 || !self::enabled($user)) {
            throw new RuntimeException('Two-factor authentication is unavailable.');
        }

        Security::rateLimit('2fa-login-ip', 15, 900);
        Security::rateLimit('2fa-login-user', 6, 600, (string)$userId);

        $code = (string)random_int(10000000, 99999999);
        $hash = self::loginCodeHash($userId, $code);
        $pdo = Database::pdo();

        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'UPDATE login_2fa_challenges
                 SET used_at=COALESCE(used_at,NOW())
                 WHERE user_id=? AND used_at IS NULL'
            )->execute([$userId]);

            $pdo->prepare(
                'DELETE FROM login_2fa_challenges
                 WHERE expires_at<=DATE_SUB(NOW(), INTERVAL 1 DAY)'
            )->execute();

            $pdo->prepare(
                'INSERT INTO login_2fa_challenges(
                    user_id,code_hash,expires_at,attempts,created_ip
                 ) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 5 MINUTE),0,?)'
            )->execute([
                $userId,
                $hash,
                Security::clientIp(),
            ]);

            $challengeId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $display = trim((string)($user['name'] ?? ''))
            ?: (string)($user['username'] ?? 'account');

        $sent = TelegramService::sendPrivateMessage(
            $chatId,
            "🔐 <b>TEAM DARK LOGIN CODE</b>\n\n"
            ."Account: <b>".self::html($display)."</b>\n"
            ."Your 8-digit code:\n<code>".$code."</code>\n\n"
            ."⏱ Expires in 5 minutes.\n"
            ."Never share this code with anyone."
        );

        if (!$sent) {
            $pdo->prepare(
                'UPDATE login_2fa_challenges SET used_at=NOW() WHERE id=?'
            )->execute([$challengeId]);
            throw new RuntimeException('Could not deliver the Telegram verification code.');
        }

        try {
            Security::audit($userId, '2fa_login_code_sent', [
                'challenge_id'=>$challengeId,
                'telegram_chat_suffix'=>substr((string)$chatId, -4),
            ]);
        } catch (Throwable) {
        }

        return [
            'challenge_id'=>$challengeId,
            'user_id'=>$userId,
            'expires_ts'=>time() + self::LOGIN_TTL,
        ];
    }

    public static function verifyLoginChallenge(
        int $challengeId,
        int $userId,
        string $rawCode
    ): array {
        $code = trim($rawCode);

        if ($challengeId <= 0 || $userId <= 0 || !preg_match('/^[0-9]{8}$/', $code)) {
            throw new RuntimeException('Invalid verification code.');
        }

        Security::rateLimit('2fa-login-verify-ip', 30, 900);
        Security::rateLimit('2fa-login-verify-user', 15, 900, (string)$userId);

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $q = $pdo->prepare(
                'SELECT c.*,u.id AS uid,u.name,u.username,u.role,u.balance,
                        u.telegram_chat_id,u.telegram_2fa_enabled,u.telegram_2fa_enabled_at,
                        u.status,u.password_hash,u.created_at
                 FROM login_2fa_challenges c
                 JOIN users u ON u.id=c.user_id
                 WHERE c.id=? AND c.user_id=?
                 LIMIT 1
                 FOR UPDATE'
            );
            $q->execute([$challengeId, $userId]);
            $row = $q->fetch();

            if (
                !$row
                || $row['status'] !== 'active'
                || (int)$row['telegram_2fa_enabled'] !== 1
                || $row['used_at'] !== null
                || strtotime((string)$row['expires_at']) <= time()
                || (int)$row['attempts'] >= self::MAX_LOGIN_ATTEMPTS
            ) {
                throw new RuntimeException('Verification code is invalid or expired.');
            }

            $expected = self::loginCodeHash($userId, $code);
            $valid = hash_equals((string)$row['code_hash'], $expected);

            if (!$valid) {
                $nextAttempts = (int)$row['attempts'] + 1;

                $pdo->prepare(
                    'UPDATE login_2fa_challenges
                     SET attempts=?,
                         used_at=CASE WHEN ?>=? THEN NOW() ELSE used_at END
                     WHERE id=?'
                )->execute([
                    $nextAttempts,
                    $nextAttempts,
                    self::MAX_LOGIN_ATTEMPTS,
                    $challengeId,
                ]);

                $pdo->commit();

                try {
                    Security::audit($userId, '2fa_login_failed', [
                        'challenge_id'=>$challengeId,
                        'attempts'=>$nextAttempts,
                    ]);
                } catch (Throwable) {
                }

                throw new RuntimeException('Verification code is incorrect.');
            }

            $pdo->prepare(
                'UPDATE login_2fa_challenges SET used_at=NOW() WHERE id=?'
            )->execute([$challengeId]);
            $pdo->prepare(
                'UPDATE login_2fa_challenges
                 SET used_at=COALESCE(used_at,NOW())
                 WHERE user_id=? AND id<>? AND used_at IS NULL'
            )->execute([$userId, $challengeId]);

            $pdo->commit();

            Security::clearRateLimit('2fa-login-verify-user', (string)$userId);

            try {
                Security::audit($userId, '2fa_login_verified', [
                    'challenge_id'=>$challengeId,
                ]);
            } catch (Throwable) {
            }

            return [
                'id'=>(int)$row['uid'],
                'name'=>$row['name'],
                'username'=>$row['username'],
                'role'=>$row['role'],
                'balance'=>$row['balance'],
                'telegram_chat_id'=>$row['telegram_chat_id'],
                'telegram_2fa_enabled'=>$row['telegram_2fa_enabled'],
                'telegram_2fa_enabled_at'=>$row['telegram_2fa_enabled_at'],
                'status'=>$row['status'],
                'password_hash'=>$row['password_hash'],
                'created_at'=>$row['created_at'],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function loginCodeHash(int $userId, string $code): string
    {
        return Crypto::fingerprint('2fa-login|'.$userId.'|'.$code);
    }

    private static function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
