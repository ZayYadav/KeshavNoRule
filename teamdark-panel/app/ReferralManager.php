<?php
declare(strict_types=1);

namespace TeamDark\Panel;

require_once __DIR__.'/AppRegistry.php';

use RuntimeException;
use Throwable;

final class ReferralManager
{
    public const MAX_REFERRAL_BALANCE = 1000000000;

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

    public static function create(array $actor, string $role, mixed $appIds = [], mixed $grantBalance = 0): array
    {
        $allowed = self::allowedRoles($actor);
        if (!in_array($role, $allowed, true)) {
            throw new RuntimeException('You cannot create a referral for this role.');
        }

        $selectedApps = AppRegistry::validateReferralApps($actor, $appIds);
        $grantBalance = self::normalizeGrantBalance($grantBalance);
        $pdo = Database::pdo();

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = 'TD-REF-'.strtoupper(bin2hex(random_bytes(6)));
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
                self::assertGrantBalanceAllowed($pdo, $freshActor, $grantBalance);

                $pdo->prepare(
                    "INSERT INTO referral_invites(code,created_by,role,grant_balance,status,expires_at)
                     VALUES(?,?,?,?,'pending',DATE_ADD(NOW(),INTERVAL 7 DAY))"
                )->execute([$code, $actor['id'], $role, $grantBalance]);

                $id = (int)$pdo->lastInsertId();
                AppRegistry::attachAppsToReferral($pdo, $actor, $id, $selectedApps);
                $pdo->commit();

                try {
                    Security::audit((int)$actor['id'], 'referral_created', [
                        'invite_id'=>$id,
                        'role'=>$role,
                        'app_ids'=>$selectedApps,
                        'grant_balance'=>$grantBalance,
                    ]);
                } catch (Throwable) {
                }

                return [
                    'id'=>$id,
                    'code'=>$code,
                    'role'=>$role,
                    'app_ids'=>$selectedApps,
                    'grant_balance'=>$grantBalance,
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
            "SELECT COALESCE(SUM(grant_balance),0)
             FROM referral_invites
             WHERE created_by=? AND status='pending'
               AND (expires_at IS NULL OR expires_at>NOW())"
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
        $q = $pdo->prepare(
            "SELECT i.*,u.role AS creator_role,u.status AS creator_status
             FROM referral_invites i
             JOIN users u ON u.id=i.created_by
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

        $q = $pdo->prepare(
            "SELECT i.*,u.role AS creator_role,u.status AS creator_status
             FROM referral_invites i
             JOIN users u ON u.id=i.created_by
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
            throw new RuntimeException('This referral is invalid, used, revoked or expired.');
        }
        if (($invite['expires_at'] ?? null) && strtotime((string)$invite['expires_at']) <= time()) {
            throw new RuntimeException('This referral is invalid, used, revoked or expired.');
        }
        if (($invite['creator_status'] ?? '') !== 'active') {
            throw new RuntimeException('This referral is no longer authorized.');
        }
        if (!self::creatorCanIssueRole((string)$invite['creator_role'], (string)$invite['role'])) {
            throw new RuntimeException('This referral is no longer authorized.');
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

        $sql = "SELECT i.id,i.code,i.role,i.grant_balance,i.status,i.created_at,i.used_at,
                       c.username creator_username,
                       u.username used_username,u.name used_name,
                       COALESCE((SELECT GROUP_CONCAT(a.name ORDER BY a.is_official DESC,a.name SEPARATOR ', ')
                                 FROM referral_app_access ra
                                 JOIN app_registry a ON a.id=ra.app_id
                                 WHERE ra.referral_id=i.id),'No App API') AS app_names,
                       (SELECT COUNT(*) FROM referral_app_access ra2 WHERE ra2.referral_id=i.id) AS app_count
                FROM referral_invites i
                JOIN users c ON c.id=i.created_by
                LEFT JOIN users u ON u.id=i.used_by";
        $params = [];

        if ($actor['role'] === 'admin') {
            $sql .= ' WHERE i.created_by=?';
            $params[] = $actor['id'];
        }

        $sql .= ' ORDER BY i.id DESC LIMIT 1000';

        $q = Database::pdo()->prepare($sql);
        $q->execute($params);

        return $q->fetchAll() ?: [];
    }
}
