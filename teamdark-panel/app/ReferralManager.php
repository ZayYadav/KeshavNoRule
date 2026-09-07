<?php
declare(strict_types=1);

namespace TeamDark\Panel;

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

    public static function create(array $actor, string $role): array
    {
        $allowed = self::allowedRoles($actor);

        if (!in_array($role, $allowed, true)) {
            throw new RuntimeException('You cannot create a referral for this role.');
        }

        $pdo = Database::pdo();

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = 'TD-REF-'.strtoupper(bin2hex(random_bytes(6)));

            try {
                $pdo->prepare(
                    "INSERT INTO referral_invites(code,created_by,role,status)
                     VALUES(?,?,?,'pending')"
                )->execute([
                    $code,
                    $actor['id'],
                    $role,
                ]);

                $id = (int)$pdo->lastInsertId();

                try {
                    Security::audit((int)$actor['id'], 'referral_created', [
                        'invite_id'=>$id,
                        'role'=>$role,
                    ]);
                } catch (Throwable) {
                }

                return [
                    'id'=>$id,
                    'code'=>$code,
                    'role'=>$role,
                ];
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Could not generate a unique referral. Try again.');
    }

    public static function visible(array $actor): array
    {
        if (!in_array(($actor['role'] ?? ''), ['owner','admin'], true)) {
            return [];
        }

        $sql = "SELECT i.id,i.code,i.role,i.status,i.created_at,i.used_at,
                       c.username creator_username,
                       u.username used_username,u.name used_name
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
