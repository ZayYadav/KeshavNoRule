<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use RuntimeException;
use Throwable;

final class KeyManager
{
    public const DEVICE_LIMITS = [1,2,5,10,20,30,50,100,500,1000];

    public static function price(int $durationSeconds, bool $unlimitedExpiry): int
    {
        if ($unlimitedExpiry) {
            return max(0, (int)Config::get('unlimited_key_cost', 100));
        }

        $days = max(1, (int)ceil(max(3600, $durationSeconds) / 86400));
        return $days * max(0, (int)Config::get('key_cost', 1));
    }

    public static function targetsFor(array $actor): array
    {
        $pdo = Database::pdo();

        if ($actor['role'] === 'owner') {
            return $pdo->query(
                "SELECT id,username,role
                 FROM users
                 WHERE status='active'
                 ORDER BY username
                 LIMIT 1000"
            )->fetchAll() ?: [];
        }

        if ($actor['role'] === 'admin') {
            return $pdo->query(
                "SELECT id,username,role
                 FROM users
                 WHERE status='active' AND role<>'owner'
                 ORDER BY username
                 LIMIT 1000"
            )->fetchAll() ?: [];
        }

        if ($actor['role'] === 'reseller') {
            $q = $pdo->prepare(
                "SELECT id,username,role
                 FROM users
                 WHERE status='active' AND (id=? OR referred_by=?)
                 ORDER BY username
                 LIMIT 1000"
            );
            $q->execute([$actor['id'], $actor['id']]);
            return $q->fetchAll() ?: [];
        }

        $q = $pdo->prepare(
            "SELECT id,username,role
             FROM users
             WHERE status='active' AND id=?
             LIMIT 1"
        );
        $q->execute([$actor['id']]);
        return $q->fetchAll() ?: [];
    }

    public static function visibleKeys(array $actor, string $filter = 'current'): array
    {
        self::expireDue();

        $pdo = Database::pdo();

        $sql = "SELECT k.*,u.username owner_name,u.role owner_role,
                       c.username creator_name,
                       (SELECT COUNT(*) FROM license_devices d
                        WHERE d.license_key_id=k.id AND d.active=1) AS device_count
                FROM license_keys k
                JOIN users u ON u.id=k.owner_user_id
                JOIN users c ON c.id=k.created_by";

        $where = [];
        $params = [];

        if ($actor['role'] === 'admin') {
            $where[] = "u.role<>'owner'";
        } elseif ($actor['role'] === 'reseller') {
            $where[] = "(k.created_by=? OR k.owner_user_id=? OR u.referred_by=?)";
            $params[] = $actor['id'];
            $params[] = $actor['id'];
            $params[] = $actor['id'];
        } elseif ($actor['role'] === 'user') {
            $where[] = "k.owner_user_id=?";
            $params[] = $actor['id'];
        }

        if ($filter === 'expired') {
            $where[] = "k.status='expired'";
        } elseif ($filter === 'current') {
            $where[] = "k.status<>'expired'";
        } elseif (in_array($filter, ['unused','active','disabled','revoked'], true)) {
            $where[] = 'k.status=?';
            $params[] = $filter;
        }

        if ($where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        $sql .= ' ORDER BY k.id DESC LIMIT 1000';

        $q = $pdo->prepare($sql);
        $q->execute($params);

        return $q->fetchAll() ?: [];
    }

    public static function create(
        array $actor,
        int $targetUserId,
        string $label,
        int $durationSeconds,
        bool $unlimitedExpiry,
        int $maxDevices,
        bool $unlimitedDevices
    ): array {
        if (!$unlimitedExpiry && $durationSeconds < 3600) {
            throw new RuntimeException('Key duration must be at least 1 hour.');
        }

        if (!$unlimitedDevices && !in_array($maxDevices, self::DEVICE_LIMITS, true)) {
            throw new RuntimeException('Invalid maximum device count.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $target = self::targetFor($pdo, $actor, $targetUserId);
            if (!$target) {
                throw new RuntimeException('Target user not allowed.');
            }

            $cost = $actor['role'] === 'owner'
                ? 0
                : self::price($durationSeconds, $unlimitedExpiry);

            if ($cost > 0) {
                $balanceQ = $pdo->prepare(
                    "SELECT role,balance FROM users WHERE id=? FOR UPDATE"
                );
                $balanceQ->execute([$actor['id']]);
                $balanceRow = $balanceQ->fetch();

                if (!$balanceRow || $balanceRow['role'] === 'owner') {
                    $cost = 0;
                } else {
                    $balance = (int)$balanceRow['balance'];
                    if ($balance < $cost) {
                        throw new RuntimeException('Insufficient balance.');
                    }

                    $pdo->prepare(
                        'UPDATE users SET balance=balance-? WHERE id=?'
                    )->execute([$cost, $actor['id']]);

                    $pdo->prepare(
                        'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
                    )->execute([
                        $actor['id'],
                        $actor['id'],
                        -$cost,
                        'PUBG license generation',
                    ]);
                }
            }

            $plain = self::newLicense();
            [$cipher, $iv, $tag] = Crypto::encrypt($plain);

            $pdo->prepare(
                "INSERT INTO license_keys(
                    owner_user_id,created_by,key_hash,key_cipher,key_iv,key_tag,
                    label,game,duration_seconds,unlimited_expiry,
                    activated_at,expires_at,last_used_at,
                    max_devices,unlimited_devices,status
                 ) VALUES(?,?,?,?,?,?,?,'PUBG',?,?,NULL,NULL,NULL,?,?,'unused')"
            )->execute([
                $target['id'],
                $actor['id'],
                hash('sha256', $plain),
                $cipher,
                $iv,
                $tag,
                substr($label, 0, 100),
                $unlimitedExpiry ? 0 : max(3600, $durationSeconds),
                $unlimitedExpiry ? 1 : 0,
                $unlimitedDevices ? 1 : max(1, $maxDevices),
                $unlimitedDevices ? 1 : 0,
            ]);

            $id = (int)$pdo->lastInsertId();
            $pdo->commit();

            try {
                Security::audit((int)$actor['id'], 'license_created', [
                    'license_id'=>$id,
                    'owner_id'=>(int)$target['id'],
                    'cost'=>$cost,
                    'unlimited_expiry'=>$unlimitedExpiry,
                    'unlimited_devices'=>$unlimitedDevices,
                ]);
            } catch (Throwable) {
            }

            return [
                'id'=>$id,
                'key'=>$plain,
                'cost'=>$cost,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function action(array $actor, int $keyId, string $action): void
    {
        if (roleRankForManager($actor['role']) < 20) {
            throw new RuntimeException('Forbidden.');
        }

        self::expireDue();

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $key = self::keyForActor($pdo, $actor, $keyId, true);

            if (!$key) {
                throw new RuntimeException('Key not found or not allowed.');
            }

            if ($action === 'delete') {
                if ($actor['role'] !== 'owner') {
                    throw new RuntimeException('Only Owner can delete keys.');
                }

                $pdo->prepare('DELETE FROM license_keys WHERE id=?')
                    ->execute([$keyId]);

                $pdo->commit();
                return;
            }

            if ($action === 'revoke') {
                $pdo->prepare(
                    "UPDATE license_keys SET status='revoked' WHERE id=?"
                )->execute([$keyId]);
            } elseif ($action === 'disable') {
                if (!in_array($key['status'], ['unused','active'], true)) {
                    throw new RuntimeException('This key cannot be disabled.');
                }

                $pdo->prepare(
                    "UPDATE license_keys SET status='disabled' WHERE id=?"
                )->execute([$keyId]);
            } elseif ($action === 'enable') {
                if ($key['status'] !== 'disabled') {
                    throw new RuntimeException('Only disabled keys can be enabled.');
                }

                if (
                    !(bool)$key['unlimited_expiry']
                    && !empty($key['expires_at'])
                    && strtotime((string)$key['expires_at']) <= time()
                ) {
                    $pdo->prepare(
                        "UPDATE license_keys SET status='expired' WHERE id=?"
                    )->execute([$keyId]);
                    throw new RuntimeException('Key has expired.');
                }

                $next = empty($key['activated_at']) ? 'unused' : 'active';
                $pdo->prepare(
                    'UPDATE license_keys SET status=? WHERE id=?'
                )->execute([$next, $keyId]);
            } else {
                throw new RuntimeException('Invalid key action.');
            }

            $pdo->commit();

            try {
                Security::audit((int)$actor['id'], 'license_'.$action, [
                    'license_id'=>$keyId,
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

    public static function devices(array $actor, int $keyId): array
    {
        self::expireDue();

        $pdo = Database::pdo();
        $key = self::keyForActor($pdo, $actor, $keyId, false);

        if (!$key) {
            throw new RuntimeException('Key not found or not allowed.');
        }

        $q = $pdo->prepare(
            "SELECT id,serial,first_seen_at,last_seen_at,ip_address,active
             FROM license_devices
             WHERE license_key_id=?
             ORDER BY active DESC,last_seen_at DESC"
        );
        $q->execute([$keyId]);

        return [
            'key'=>$key,
            'devices'=>$q->fetchAll() ?: [],
            'can_manage'=>roleRankForManager($actor['role']) >= 20,
        ];
    }

    public static function resetDevices(
        array $actor,
        int $keyId,
        ?int $deviceId = null
    ): int {
        if (roleRankForManager($actor['role']) < 20) {
            throw new RuntimeException('Forbidden.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $key = self::keyForActor($pdo, $actor, $keyId, true);
            if (!$key) {
                throw new RuntimeException('Key not found or not allowed.');
            }

            if ($deviceId !== null) {
                $q = $pdo->prepare(
                    "UPDATE license_devices
                     SET active=0
                     WHERE id=? AND license_key_id=? AND active=1"
                );
                $q->execute([$deviceId, $keyId]);
            } else {
                $q = $pdo->prepare(
                    "UPDATE license_devices
                     SET active=0
                     WHERE license_key_id=? AND active=1"
                );
                $q->execute([$keyId]);
            }

            $changed = $q->rowCount();
            $pdo->commit();

            try {
                Security::audit((int)$actor['id'], 'license_device_reset', [
                    'license_id'=>$keyId,
                    'device_id'=>$deviceId,
                    'count'=>$changed,
                ]);
            } catch (Throwable) {
            }

            return $changed;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function expireDue(): void
    {
        Database::pdo()->prepare(
            "UPDATE license_keys
             SET status='expired'
             WHERE unlimited_expiry=0
               AND expires_at IS NOT NULL
               AND expires_at<=NOW()
               AND status IN ('active','disabled')"
        )->execute();
    }

    private static function targetFor(
        \PDO $pdo,
        array $actor,
        int $targetId
    ): ?array {
        $q = $pdo->prepare(
            'SELECT id,username,role,referred_by,status FROM users WHERE id=? LIMIT 1'
        );
        $q->execute([$targetId]);
        $target = $q->fetch();

        if (!$target || $target['status'] !== 'active') {
            return null;
        }

        if ($actor['role'] === 'owner') {
            return $target;
        }

        if ($actor['role'] === 'admin' && $target['role'] !== 'owner') {
            return $target;
        }

        if (
            $actor['role'] === 'reseller'
            && (
                (int)$target['id'] === (int)$actor['id']
                || (int)$target['referred_by'] === (int)$actor['id']
            )
        ) {
            return $target;
        }

        if (
            $actor['role'] === 'user'
            && (int)$target['id'] === (int)$actor['id']
        ) {
            return $target;
        }

        return null;
    }

    private static function keyForActor(
        \PDO $pdo,
        array $actor,
        int $keyId,
        bool $forUpdate
    ): ?array {
        $sql = "SELECT k.*,u.username owner_name,u.role owner_role,u.referred_by owner_referred_by
                FROM license_keys k
                JOIN users u ON u.id=k.owner_user_id
                WHERE k.id=?
                LIMIT 1";

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $q = $pdo->prepare($sql);
        $q->execute([$keyId]);
        $key = $q->fetch();

        if (!$key) {
            return null;
        }

        if ($actor['role'] === 'owner') {
            return $key;
        }

        if ($actor['role'] === 'admin' && $key['owner_role'] !== 'owner') {
            return $key;
        }

        if (
            $actor['role'] === 'reseller'
            && (
                (int)$key['created_by'] === (int)$actor['id']
                || (int)$key['owner_user_id'] === (int)$actor['id']
                || (int)$key['owner_referred_by'] === (int)$actor['id']
            )
        ) {
            return $key;
        }

        if (
            $actor['role'] === 'user'
            && (int)$key['owner_user_id'] === (int)$actor['id']
        ) {
            return $key;
        }

        return null;
    }

    private static function newLicense(): string
    {
        $raw = strtoupper(bin2hex(random_bytes(16)));
        return 'TD-'.implode('-', str_split($raw, 8));
    }
}

function roleRankForManager(string $role): int
{
    return [
        'user'=>10,
        'reseller'=>20,
        'admin'=>30,
        'owner'=>40,
    ][$role] ?? 0;
}
