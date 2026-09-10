<?php
declare(strict_types=1);

namespace TeamDark\Panel;

require_once __DIR__.'/AppRegistry.php';

use RuntimeException;
use Throwable;

final class ReferralManager
{
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

    public static function create(array $actor, string $role, mixed $appIds = []): array
    {
        $allowed = self::allowedRoles($actor);
        if (!in_array($role, $allowed, true)) {
            throw new RuntimeException('You cannot create a referral for this role.');
        }

        $selectedApps = AppRegistry::validateReferralApps($actor, $appIds);
        $pdo = Database::pdo();

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = 'TD-REF-'.strtoupper(bin2hex(random_bytes(6)));
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "INSERT INTO referral_invites(code,created_by,role,status,expires_at)
                     VALUES(?,?,?,'pending',DATE_ADD(NOW(),INTERVAL 7 DAY))"
                )->execute([$code, $actor['id'], $role]);

                $id = (int)$pdo->lastInsertId();
                AppRegistry::attachAppsToReferral($pdo, $actor, $id, $selectedApps);
                $pdo->commit();

                try {
                    Security::audit((int)$actor['id'], 'referral_created', [
                        'invite_id'=>$id,
                        'role'=>$role,
                        'app_ids'=>$selectedApps,
                    ]);
                } catch (Throwable) {
                }

                return [
                    'id'=>$id,
                    'code'=>$code,
                    'role'=>$role,
                    'app_ids'=>$selectedApps,
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

    public static function grantInviteAppsToUser(\PDO $pdo, int $inviteId, int $userId, int $grantedBy): array
    {
        return AppRegistry::grantReferralToUser($pdo, $inviteId, $userId, $grantedBy);
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
        $invite = $q->fetch();

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
            "SELECT a.id
             FROM referral_app_access ra
             JOIN app_registry a ON a.id=ra.app_id
             WHERE ra.referral_id=? AND a.status='active'
             ORDER BY a.is_official DESC,a.id ASC"
        );
        $appQ->execute([(int)$invite['id']]);
        $appIds = array_map('intval', $appQ->fetchAll(\PDO::FETCH_COLUMN) ?: []);

        // Backward compatibility for legacy/directly-created referrals that
        // predate app scoping: safely bind them to Official if the creator is
        // still allowed to grant Official. New panel-created referrals always
        // carry explicit app assignments.
        if (!$appIds && AppRegistry::userHasApp(
            (int)$invite['created_by'],
            (string)$invite['creator_role'],
            AppRegistry::OFFICIAL_ID
        )) {
            $pdo->prepare(
                'INSERT IGNORE INTO referral_app_access(referral_id,app_id) VALUES(?,?)'
            )->execute([(int)$invite['id'], AppRegistry::OFFICIAL_ID]);
            $appIds = [AppRegistry::OFFICIAL_ID];
        }

        if (!$appIds) {
            throw new RuntimeException('This referral has no active application API assigned.');
        }

        if (($invite['creator_role'] ?? '') !== 'owner') {
            foreach ($appIds as $appId) {
                if (!AppRegistry::userHasApp(
                    (int)$invite['created_by'],
                    (string)$invite['creator_role'],
                    $appId
                )) {
                    throw new RuntimeException('This referral contains application access that is no longer authorized.');
                }
            }
        }

        $invite['app_ids'] = $appIds;
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

        $sql = "SELECT i.id,i.code,i.role,i.status,i.created_at,i.used_at,
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
