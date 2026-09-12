<?php
declare(strict_types=1);

namespace TeamDark\Panel;

require_once __DIR__.'/AppRegistry.php';

use RuntimeException;
use Throwable;

final class ReferralManager
{
    public const MAX_REFERRAL_BALANCE = 1000000000;
    public const REGISTRATION_LIMITS = [1, 2, 10, 100, 1000];

    private static bool $supportTablesReady = false;

    public static function prepareStorage(): void
    {
        self::ensureSupportTables(Database::pdo());
    }

    public static function allowedRoles(array $actor): array
    {
        if (($actor['role'] ?? '') === 'owner') {
            return ['admin','reseller','user'];
        }

        if (($actor['role'] ?? '') === 'admin') {
            return ['user'];
        }

        return [];
    }

    public static function create(
        array $actor,
        string $role,
        mixed $appIds = [],
        mixed $grantBalance = 0,
        mixed $maxRegistrations = 1
    ): array {
        $allowed = self::allowedRoles($actor);
        if (!in_array($role, $allowed, true)) {
            throw new RuntimeException('You cannot create a referral for this role.');
        }

        $selectedApps = AppRegistry::validateReferralApps($actor, $appIds);
        $grantBalance = self::normalizeGrantBalance($grantBalance);
        $maxRegistrations = self::normalizeRegistrationLimit($maxRegistrations);

        if (($actor['role'] ?? '') !== 'owner' && $maxRegistrations !== 1) {
            throw new RuntimeException('Only Owner can create a referral for multiple registrations.');
        }

        $pdo = Database::pdo();
        self::ensureSupportTables($pdo);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = 'NR-REF-'.strtoupper(bin2hex(random_bytes(6)));
            $pdo->beginTransaction();
            try {
                // Serialize referral balance reservations per creator so parallel
                // requests cannot bypass the Admin balance policy.
                $actorLock = $pdo->prepare(
                    'SELECT id,role,status FROM users WHERE id=? LIMIT 1 FOR UPDATE'
                );
                $actorLock->execute([(int)$actor['id']]);
                $freshActor = $actorLock->fetch();
                if (!$freshActor || $freshActor['status'] !== 'active') {
                    throw new RuntimeException('Referral creator account is unavailable.');
                }
                if (!in_array($role, self::allowedRoles($freshActor), true)) {
                    throw new RuntimeException('You cannot create a referral for this role.');
                }
                if (($freshActor['role'] ?? '') !== 'owner' && $maxRegistrations !== 1) {
                    throw new RuntimeException('Only Owner can create a referral for multiple registrations.');
                }
                self::assertGrantBalanceAllowed($pdo, $freshActor, $grantBalance);

                $pdo->prepare(
                    "INSERT INTO referral_invites(code,created_by,role,grant_balance,status,expires_at)
                     VALUES(?,?,?,?,'pending',DATE_ADD(NOW(),INTERVAL 7 DAY))"
                )->execute([$code, $actor['id'], $role, $grantBalance]);

                $id = (int)$pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO referral_invite_limits(referral_id,max_registrations,used_count,updated_by) VALUES(?,?,0,?)'
                )->execute([$id, $maxRegistrations, (int)$actor['id']]);

                AppRegistry::attachAppsToReferral($pdo, $actor, $id, $selectedApps);
                $pdo->commit();

                try {
                    Security::audit((int)$actor['id'], 'referral_created', [
                        'invite_id'=>$id,
                        'role'=>$role,
                        'app_ids'=>$selectedApps,
                        'grant_balance'=>$grantBalance,
                        'max_registrations'=>$maxRegistrations,
                    ]);
                } catch (Throwable) {
                }

                return [
                    'id'=>$id,
                    'code'=>$code,
                    'role'=>$role,
                    'app_ids'=>$selectedApps,
                    'grant_balance'=>$grantBalance,
                    'max_registrations'=>$maxRegistrations,
                    'used_count'=>0,
                ];
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e->getCode() !== '23000') throw $e;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }

        throw new RuntimeException('Could not generate a unique referral. Try again.');
    }

    private static function normalizeGrantBalance(mixed $raw): int
    {
        if ($raw === null || $raw === '') return 0;
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^\d{1,10}$/', trim($raw))) {
            $value = (int)trim($raw);
        } else {
            throw new RuntimeException('Referral balance must be a whole number from 0 to 1,000,000,000.');
        }

        if ($value < 0 || $value > self::MAX_REFERRAL_BALANCE) {
            throw new RuntimeException('Referral balance must be between 0 and 1,000,000,000 credits.');
        }
        return $value;
    }

    private static function normalizeRegistrationLimit(mixed $raw): int
    {
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^\d{1,4}$/', trim($raw))) {
            $value = (int)trim($raw);
        } else {
            throw new RuntimeException('Invalid referral registration limit.');
        }

        if (!in_array($value, self::REGISTRATION_LIMITS, true)) {
            throw new RuntimeException('Referral registration limit must be 1, 2, 10, 100 or 1000.');
        }
        return $value;
    }

    private static function ensureSupportTables(\PDO $pdo): void
    {
        if (self::$supportTablesReady) return;
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Referral storage must be prepared before starting a registration transaction.');
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS referral_invite_limits (
                referral_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                max_registrations INT UNSIGNED NOT NULL DEFAULT 1,
                used_count INT UNSIGNED NOT NULL DEFAULT 0,
                updated_by BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_ref_limit_referral FOREIGN KEY (referral_id) REFERENCES referral_invites(id) ON DELETE CASCADE,
                CONSTRAINT fk_ref_limit_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_ref_limit_max(max_registrations),
                INDEX idx_ref_limit_used(used_count)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS referral_invite_uses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                referral_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_ref_use_referral FOREIGN KEY (referral_id) REFERENCES referral_invites(id) ON DELETE CASCADE,
                CONSTRAINT fk_ref_use_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY uq_ref_use_user(referral_id,user_id),
                INDEX idx_ref_use_referral(referral_id),
                INDEX idx_ref_use_user(user_id),
                INDEX idx_ref_use_time(used_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Backfill legacy invitations without changing their historical behavior.
        $pdo->exec(
            "INSERT IGNORE INTO referral_invite_limits(referral_id,max_registrations,used_count,updated_by)
             SELECT id,1,CASE WHEN status='used' THEN 1 ELSE 0 END,NULL
             FROM referral_invites"
        );
        $pdo->exec(
            "INSERT IGNORE INTO referral_invite_uses(referral_id,user_id,used_at)
             SELECT id,used_by,COALESCE(used_at,created_at)
             FROM referral_invites
             WHERE used_by IS NOT NULL"
        );

        self::$supportTablesReady = true;
    }

    private static function assertGrantBalanceAllowed(\PDO $pdo, array $actor, int $grantBalance): void
    {
        if ($grantBalance <= 0 || ($actor['role'] ?? '') === 'owner') return;
        if (($actor['role'] ?? '') !== 'admin') {
            throw new RuntimeException('This account cannot attach balance to referrals.');
        }
        if (!(bool)Config::get('admin_balance_adjustments_enabled', false)) {
            throw new RuntimeException('Referral balance grants are disabled for Admin accounts by Owner policy.');
        }

        $limit = (int)Config::get('admin_balance_daily_limit', 10000);
        if ($limit <= 0) {
            throw new RuntimeException('Admin balance grants are currently disabled by Owner policy.');
        }

        $spentQ = $pdo->prepare(
            "SELECT COALESCE(SUM(amount),0)
             FROM balance_ledger
             WHERE actor_user_id=?
               AND amount>0
               AND reason IN ('Manual balance adjustment','Referral balance grant')
               AND created_at>=CURDATE()"
        );
        $spentQ->execute([(int)$actor['id']]);
        $spentToday = (int)$spentQ->fetchColumn();

        $pendingQ = $pdo->prepare(
            "SELECT COALESCE(SUM(
                    i.grant_balance * GREATEST(COALESCE(l.max_registrations,1)-COALESCE(l.used_count,0),0)
                ),0)
             FROM referral_invites i
             LEFT JOIN referral_invite_limits l ON l.referral_id=i.id
             WHERE i.created_by=? AND i.status='pending'
               AND (i.expires_at IS NULL OR i.expires_at>NOW())"
        );
        $pendingQ->execute([(int)$actor['id']]);
        $reserved = (int)$pendingQ->fetchColumn();

        if (($spentToday + $reserved + $grantBalance) > $limit) {
            throw new RuntimeException('Admin referral balance limit reached. Pending referral grants also count toward the limit.');
        }
    }

    public static function grantInviteAppsToUser(\PDO $pdo, int $inviteId, int $userId, int $grantedBy): array
    {
        return AppRegistry::grantReferralToUser($pdo, $inviteId, $userId, $grantedBy);
    }

    public static function grantInviteBalanceToUser(\PDO $pdo, array $invite, int $userId): int
    {
        $inviteId = (int)($invite['id'] ?? 0);
        if ($inviteId <= 0 || $userId <= 0) {
            throw new RuntimeException('Referral balance grant is invalid.');
        }

        // Treat the database row as authoritative. Do not trust caller-provided
        // grant/creator values, which may be compact, stale or accidentally altered.
        $inviteQ = $pdo->prepare(
            "SELECT created_by,grant_balance
             FROM referral_invites
             WHERE id=? AND status='pending'
             LIMIT 1"
        );
        $inviteQ->execute([$inviteId]);
        $storedInvite = $inviteQ->fetch();
        if (!$storedInvite) {
            throw new RuntimeException('Referral balance grant is no longer available.');
        }

        $grant = (int)$storedInvite['grant_balance'];
        $creatorId = (int)$storedInvite['created_by'];
        if ($grant <= 0) return 0;
        if ($grant > self::MAX_REFERRAL_BALANCE || $creatorId <= 0) {
            throw new RuntimeException('Referral balance grant is invalid.');
        }

        $q = $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=? AND role<>'owner'");
        $q->execute([$grant, $userId]);
        if ($q->rowCount() !== 1) {
            throw new RuntimeException('Could not apply referral balance to the new account.');
        }

        $pdo->prepare(
            'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
        )->execute([
            $userId,
            $creatorId,
            $grant,
            'Referral balance grant',
        ]);
        return $grant;
    }

    public static function validateForRegistration(string $code): array
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 80) {
            throw new RuntimeException('A valid referral code is required.');
        }

        self::expireDue();
        $pdo = Database::pdo();
        self::ensureSupportTables($pdo);
        $q = $pdo->prepare(
            "SELECT i.*,u.role AS creator_role,u.status AS creator_status,
                    COALESCE(l.max_registrations,1) AS max_registrations,
                    COALESCE(l.used_count,CASE WHEN i.status='used' THEN 1 ELSE 0 END) AS used_count
             FROM referral_invites i
             JOIN users u ON u.id=i.created_by
             LEFT JOIN referral_invite_limits l ON l.referral_id=i.id
             WHERE i.code=?
             LIMIT 1"
        );
        $q->execute([$code]);
        return self::validateLoadedInvite($pdo, $q->fetch());
    }

    public static function lockForRegistration(\PDO $pdo, string $code): array
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 80) {
            throw new RuntimeException('A valid referral code is required.');
        }

        self::ensureSupportTables($pdo);
        $q = $pdo->prepare(
            "SELECT i.*,u.role AS creator_role,u.status AS creator_status,
                    COALESCE(l.max_registrations,1) AS max_registrations,
                    COALESCE(l.used_count,CASE WHEN i.status='used' THEN 1 ELSE 0 END) AS used_count
             FROM referral_invites i
             JOIN users u ON u.id=i.created_by
             LEFT JOIN referral_invite_limits l ON l.referral_id=i.id
             WHERE i.code=?
             LIMIT 1
             FOR UPDATE"
        );
        $q->execute([$code]);
        return self::validateLoadedInvite($pdo, $q->fetch());
    }

    private static function validateLoadedInvite(\PDO $pdo, array|false $invite): array
    {
        if (!$invite || ($invite['status'] ?? '') !== 'pending') {
            throw new RuntimeException('This referral is invalid, fully used, revoked or expired.');
        }
        if (($invite['expires_at'] ?? null) && strtotime((string)$invite['expires_at']) <= time()) {
            throw new RuntimeException('This referral is invalid, fully used, revoked or expired.');
        }
        if (($invite['creator_status'] ?? '') !== 'active') {
            throw new RuntimeException('This referral is no longer authorized.');
        }
        if (!self::creatorCanIssueRole((string)$invite['creator_role'], (string)$invite['role'])) {
            throw new RuntimeException('This referral is no longer authorized.');
        }

        $maxRegistrations = (int)($invite['max_registrations'] ?? 1);
        $usedCount = max(0, (int)($invite['used_count'] ?? 0));
        if (!in_array($maxRegistrations, self::REGISTRATION_LIMITS, true) || $usedCount >= $maxRegistrations) {
            throw new RuntimeException('This referral has reached its registration limit.');
        }

        $appQ = $pdo->prepare(
            "SELECT a.id,a.name,a.endpoint_token,a.is_official,a.status
             FROM referral_app_access ra
             JOIN app_registry a ON a.id=ra.app_id
             WHERE ra.referral_id=? AND a.status='active'
             ORDER BY a.is_official DESC,a.id ASC"
        );
        $appQ->execute([(int)$invite['id']]);
        $appRows = $appQ->fetchAll() ?: [];
        $appIds = array_map(static fn(array $app): int => (int)$app['id'], $appRows);

        // Fail closed. Legacy referrals are backfilled exactly once by schema.sql.
        // An invite that loses all active app mappings must never silently become Official.
        if (!$appIds) {
            throw new RuntimeException('This referral has no active application API assigned.');
        }

        if (($invite['creator_role'] ?? '') !== 'owner') {
            $placeholders = implode(',', array_fill(0, count($appIds), '?'));
            $params = array_merge([(int)$invite['created_by']], $appIds);
            $accessQ = $pdo->prepare(
                "SELECT COUNT(DISTINCT ua.app_id)
                 FROM user_app_access ua
                 JOIN app_registry a ON a.id=ua.app_id
                 WHERE ua.user_id=?
                   AND a.status='active'
                   AND ua.app_id IN (".$placeholders.")"
            );
            $accessQ->execute($params);
            if ((int)$accessQ->fetchColumn() !== count($appIds)) {
                throw new RuntimeException('This referral contains application access that is no longer authorized.');
            }
        }

        $invite['max_registrations'] = $maxRegistrations;
        $invite['used_count'] = $usedCount;
        $invite['remaining_registrations'] = max(0, $maxRegistrations - $usedCount);
        $invite['app_ids'] = $appIds;
        $invite['app_apis'] = array_map(
            static fn(array $app): array => [
                'id'=>(int)$app['id'],
                'name'=>(string)$app['name'],
                'endpoint'=>AppRegistry::endpointUrl($app),
                'is_official'=>(int)$app['is_official'] === 1,
            ],
            $appRows
        );
        return $invite;
    }

    public static function consumeRegistration(\PDO $pdo, array $invite, int $userId): array
    {
        self::ensureSupportTables($pdo);

        $inviteId = (int)($invite['id'] ?? 0);
        if ($inviteId <= 0 || $userId <= 0) {
            throw new RuntimeException('Referral registration usage is invalid.');
        }

        $max = (int)($invite['max_registrations'] ?? 1);
        $used = max(0, (int)($invite['used_count'] ?? 0));
        if (!in_array($max, self::REGISTRATION_LIMITS, true) || $used >= $max) {
            throw new RuntimeException('This referral has reached its registration limit.');
        }

        $q = $pdo->prepare(
            "UPDATE referral_invite_limits
             SET used_count=used_count+1
             WHERE referral_id=? AND used_count<max_registrations"
        );
        $q->execute([$inviteId]);
        if ($q->rowCount() !== 1) {
            throw new RuntimeException('This referral has reached its registration limit.');
        }

        $stateQ = $pdo->prepare(
            'SELECT max_registrations,used_count FROM referral_invite_limits WHERE referral_id=? LIMIT 1'
        );
        $stateQ->execute([$inviteId]);
        $state = $stateQ->fetch();
        if (!$state) {
            throw new RuntimeException('Referral registration counter is unavailable.');
        }

        $newUsed = (int)$state['used_count'];
        $storedMax = (int)$state['max_registrations'];
        $status = $newUsed >= $storedMax ? 'used' : 'pending';

        $u = $pdo->prepare(
            "UPDATE referral_invites
             SET status=?,used_by=?,used_at=NOW()
             WHERE id=? AND status='pending'"
        );
        $u->execute([$status, $userId, $inviteId]);
        if ($u->rowCount() !== 1) {
            throw new RuntimeException('Referral state changed before registration completed.');
        }

        $pdo->prepare(
            'INSERT INTO referral_invite_uses(referral_id,user_id) VALUES(?,?)'
        )->execute([$inviteId, $userId]);

        return [
            'max_registrations'=>$storedMax,
            'used_count'=>$newUsed,
            'remaining_registrations'=>max(0, $storedMax - $newUsed),
            'status'=>$status,
        ];
    }

    public static function setMaxRegistrations(array $actor, int $inviteId, mixed $rawLimit): array
    {
        if (($actor['role'] ?? '') !== 'owner') {
            throw new RuntimeException('Owner access required.');
        }
        if ($inviteId <= 0) {
            throw new RuntimeException('Invalid referral.');
        }

        $limit = self::normalizeRegistrationLimit($rawLimit);
        $pdo = Database::pdo();
        self::ensureSupportTables($pdo);
        self::expireDue();
        $pdo->beginTransaction();

        try {
            $q = $pdo->prepare(
                "SELECT i.id,i.code,i.status,i.expires_at,l.used_count,l.max_registrations
                 FROM referral_invites i
                 JOIN referral_invite_limits l ON l.referral_id=i.id
                 WHERE i.id=?
                 LIMIT 1
                 FOR UPDATE"
            );
            $q->execute([$inviteId]);
            $row = $q->fetch();
            if (!$row) {
                throw new RuntimeException('Referral not found.');
            }
            if ($row['status'] === 'revoked') {
                throw new RuntimeException('Revoked referrals cannot be reactivated.');
            }
            if (($row['expires_at'] ?? null) && strtotime((string)$row['expires_at']) <= time()) {
                throw new RuntimeException('Expired referrals cannot be reactivated.');
            }

            $used = max(0, (int)$row['used_count']);
            if ($used > $limit) {
                throw new RuntimeException('Limit cannot be lower than registrations already completed.');
            }

            $newStatus = $used >= $limit ? 'used' : 'pending';
            $pdo->prepare(
                'UPDATE referral_invite_limits SET max_registrations=?,updated_by=? WHERE referral_id=?'
            )->execute([$limit, (int)$actor['id'], $inviteId]);
            $pdo->prepare('UPDATE referral_invites SET status=? WHERE id=?')
                ->execute([$newStatus, $inviteId]);
            $pdo->commit();

            Security::audit((int)$actor['id'], 'referral_registration_limit_changed', [
                'invite_id'=>$inviteId,
                'code'=>(string)$row['code'],
                'old_limit'=>(int)$row['max_registrations'],
                'new_limit'=>$limit,
                'used_count'=>$used,
                'status'=>$newStatus,
            ]);

            return [
                'id'=>$inviteId,
                'code'=>(string)$row['code'],
                'max_registrations'=>$limit,
                'used_count'=>$used,
                'remaining_registrations'=>max(0, $limit-$used),
                'status'=>$newStatus,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function creatorCanIssueRole(string $creatorRole, string $inviteRole): bool
    {
        return in_array(
            $inviteRole,
            match ($creatorRole) {
                'owner' => ['admin','reseller','user'],
                'admin' => ['user'],
                default => [],
            },
            true
        );
    }

    public static function revokeUnauthorizedPendingForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $pdo = Database::pdo();
        $q = $pdo->prepare(
            'SELECT role,status FROM users WHERE id=? LIMIT 1'
        );
        $q->execute([$userId]);
        $user = $q->fetch();

        if (!$user) {
            return 0;
        }

        $allowed = $user['status'] === 'active'
            ? match ($user['role']) {
                'owner' => ['admin','reseller','user'],
                'admin' => ['user'],
                default => [],
            }
            : [];

        if (!$allowed) {
            $q = $pdo->prepare(
                "UPDATE referral_invites
                 SET status='revoked'
                 WHERE created_by=? AND status='pending'"
            );
            $q->execute([$userId]);
            return $q->rowCount();
        }

        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $params = array_merge([$userId], $allowed);
        $q = $pdo->prepare(
            "UPDATE referral_invites
             SET status='revoked'
             WHERE created_by=?
               AND status='pending'
               AND role NOT IN (".$placeholders.")"
        );
        $q->execute($params);
        return $q->rowCount();
    }

    public static function expireDue(): void
    {
        Database::pdo()->prepare(
            "UPDATE referral_invites
             SET status='revoked'
             WHERE status='pending'
               AND expires_at IS NOT NULL
               AND expires_at<=NOW()"
        )->execute();
    }

    public static function visible(array $actor): array
    {
        self::expireDue();

        if (!in_array(($actor['role'] ?? ''), ['owner','admin'], true)) {
            return [];
        }

        $pdo = Database::pdo();
        self::ensureSupportTables($pdo);

        $sql = "SELECT i.id,i.code,i.role,i.grant_balance,i.status,i.created_at,i.used_at,i.expires_at,
                       COALESCE(l.max_registrations,1) AS max_registrations,
                       COALESCE(l.used_count,CASE WHEN i.status='used' THEN 1 ELSE 0 END) AS used_count,
                       c.username creator_username,
                       u.username used_username,u.name used_name,
                       COALESCE((SELECT GROUP_CONCAT(a.name ORDER BY a.is_official DESC,a.name SEPARATOR ', ')
                                 FROM referral_app_access ra
                                 JOIN app_registry a ON a.id=ra.app_id
                                 WHERE ra.referral_id=i.id),'No App API') AS app_names,
                       (SELECT COUNT(*) FROM referral_app_access ra2 WHERE ra2.referral_id=i.id) AS app_count
                FROM referral_invites i
                JOIN users c ON c.id=i.created_by
                LEFT JOIN users u ON u.id=i.used_by
                LEFT JOIN referral_invite_limits l ON l.referral_id=i.id";
        $params = [];

        if ($actor['role'] === 'admin') {
            $sql .= ' WHERE i.created_by=?';
            $params[] = $actor['id'];
        }

        $sql .= ' ORDER BY i.id DESC LIMIT 1000';

        $q = $pdo->prepare($sql);
        $q->execute($params);

        return $q->fetchAll() ?: [];
    }
}
