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

        $days = max(1, (int)ceil(max(86400, $durationSeconds) / 86400));
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
            $q = $pdo->prepare(
                "SELECT id,username,role
                 FROM users
                 WHERE status='active'
                   AND (id=? OR referred_by=?)
                 ORDER BY username
                 LIMIT 1000"
            );
            $q->execute([$actor['id'], $actor['id']]);
            return $q->fetchAll() ?: [];
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

        $where = [
            "(k.key_source<>'telegram_guest' OR k.telegram_user_id IS NULL OR EXISTS (SELECT 1 FROM telegram_users tgvis WHERE tgvis.id=k.telegram_user_id AND tgvis.linked_user_id IS NOT NULL))"
        ];
        $params = [];

        if ($actor['role'] === 'admin') {
            $where[] = "(k.created_by=? OR k.owner_user_id=? OR u.referred_by=?)";
            $params[] = $actor['id'];
            $params[] = $actor['id'];
            $params[] = $actor['id'];
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
        string $label,
        int $durationSeconds,
        bool $unlimitedExpiry,
        int $maxDevices,
        bool $unlimitedDevices,
        string $customKey = ''
    ): array {
        PanelControl::assertGeneration($actor);
        if (!$unlimitedExpiry && $durationSeconds < 86400) {
            throw new RuntimeException('Key duration must be at least 1 day.');
        }

        if (!$unlimitedDevices && !in_array($maxDevices, self::DEVICE_LIMITS, true)) {
            throw new RuntimeException('Invalid maximum device count.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
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

            $plain = self::licenseValue($pdo, $customKey);
            [$cipher, $iv, $tag] = Crypto::encrypt($plain);

            $pdo->prepare(
                "INSERT INTO license_keys(
                    owner_user_id,created_by,key_hash,key_cipher,key_iv,key_tag,
                    label,game,duration_seconds,unlimited_expiry,
                    activated_at,expires_at,last_used_at,
                    max_devices,unlimited_devices,status
                 ) VALUES(?,?,?,?,?,?,?,'PUBG',?,?,NULL,NULL,NULL,?,?,'unused')"
            )->execute([
                $actor['id'],
                $actor['id'],
                Crypto::licenseLookupHash($plain),
                $cipher,
                $iv,
                $tag,
                substr(trim($label), 0, 100),
                $unlimitedExpiry ? 0 : max(86400, $durationSeconds),
                $unlimitedExpiry ? 1 : 0,
                $unlimitedDevices ? 1 : max(1, $maxDevices),
                $unlimitedDevices ? 1 : 0,
            ]);

            $id = (int)$pdo->lastInsertId();
            Security::audit((int)$actor['id'], 'license_created', [
                'license_id'=>$id,
                'owner_id'=>(int)$actor['id'],
                'cost'=>$cost,
                'custom'=>$customKey !== '',
                'unlimited_expiry'=>$unlimitedExpiry,
                'unlimited_devices'=>$unlimitedDevices,
            ]);
            $pdo->commit();

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


    public static function createTelegramGuestKey(
        array $ownerActor,
        int $telegramUserId
    ): array {
        PanelControl::assertGeneration($ownerActor, true);
        if (($ownerActor['role'] ?? '') !== 'owner') {
            throw new RuntimeException('Owner authority is required.');
        }

        $dailyCap = (int)Config::get(
            'telegram_guest_daily_cap',
            100
        );

        if ($dailyCap <= 0) {
            throw new RuntimeException(
                'Free Telegram guest key generation is disabled.'
            );
        }

        Security::rateLimit(
            'telegram-guest-key-global',
            $dailyCap,
            86400,
            'global'
        );
        Security::rateLimit(
            'telegram-guest-key-user',
            4,
            3600,
            (string)$telegramUserId
        );

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $q = $pdo->prepare(
                "SELECT id,chat_id,linked_user_id,guest_last_key_at,guest_key_count
                 FROM telegram_users
                 WHERE id=?
                 LIMIT 1
                 FOR UPDATE"
            );
            $q->execute([$telegramUserId]);
            $tg = $q->fetch();

            if (!$tg) {
                throw new RuntimeException('Telegram user not found.');
            }

            if (!empty($tg['linked_user_id'])) {
                throw new RuntimeException(
                    'This Telegram account is linked to a panel account. Use normal panel key rules.'
                );
            }

            $last = $tg['guest_last_key_at']
                ? strtotime((string)$tg['guest_last_key_at'])
                : false;
            $nextTs = $last ? $last + 604800 : 0;

            if ($last && $nextTs > time()) {
                throw new RuntimeException(
                    'Free 2-hour key already used. Next key: '
                    .date('Y-m-d H:i:s', $nextTs)
                );
            }

            $plain = self::licenseValue($pdo, '');
            [$cipher, $iv, $tag] = Crypto::encrypt($plain);

            $pdo->prepare(
                "INSERT INTO license_keys(
                    owner_user_id,created_by,key_hash,key_cipher,key_iv,key_tag,
                    label,game,duration_seconds,unlimited_expiry,
                    activated_at,expires_at,last_used_at,
                    max_devices,unlimited_devices,status,key_source,telegram_user_id
                 ) VALUES(?,?,?,?,?,?,?,'PUBG',7200,0,NULL,NULL,NULL,1,0,'unused','telegram_guest',?)"
            )->execute([
                $ownerActor['id'],
                $ownerActor['id'],
                Crypto::licenseLookupHash($plain),
                $cipher,
                $iv,
                $tag,
                'Telegram guest 2H',
                $telegramUserId,
            ]);

            $keyId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                "UPDATE telegram_users
                 SET guest_last_key_at=NOW(),
                     guest_key_count=guest_key_count+1,
                     last_seen_at=NOW()
                 WHERE id=?"
            )->execute([$telegramUserId]);

            Security::audit((int)$ownerActor['id'], 'telegram_guest_key_created', [
                'license_id'=>$keyId,
                'telegram_user_id'=>$telegramUserId,
                'duration_seconds'=>7200,
            ]);
            $pdo->commit();

            return [
                'id'=>$keyId,
                'key'=>$plain,
                'duration_seconds'=>7200,
                'next_eligible_at'=>date('Y-m-d H:i:s', time() + 604800),
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
        self::expireDue();

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $key = self::keyForActor($pdo, $actor, $keyId, true);

            if (!$key) {
                throw new RuntimeException('Key not found or not allowed.');
            }

            if ($action === 'delete') {
                $isSelfOwned = (int)$key['owner_user_id'] === (int)$actor['id'];

                if ($actor['role'] !== 'owner' && !$isSelfOwned) {
                    throw new RuntimeException('You can delete only your own keys.');
                }

                $pdo->prepare('DELETE FROM license_keys WHERE id=?')
                    ->execute([$keyId]);

                Security::audit((int)$actor['id'], 'license_delete', [
                    'license_id'=>$keyId, 'owner_id'=>(int)$key['owner_user_id'],
                    'creator_id'=>(int)$key['created_by'], 'label'=>$key['label'],
                ]);

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

            Security::audit((int)$actor['id'], 'license_'.$action, [
                'license_id'=>$keyId,
            ]);
            $pdo->commit();
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
            'can_manage'=>true,
        ];
    }

    public static function resetDevices(
        array $actor,
        int $keyId,
        ?int $deviceId = null
    ): int {
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
            Security::audit((int)$actor['id'], 'license_device_reset', [
                'license_id'=>$keyId,
                'device_id'=>$deviceId,
                'count'=>$changed,
            ]);
            $pdo->commit();

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

        if (
            $actor['role'] === 'admin'
            && (
                (int)$target['id'] === (int)$actor['id']
                || (int)$target['referred_by'] === (int)$actor['id']
            )
        ) {
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

        if (
            $actor['role'] === 'admin'
            && (
                (int)$key['created_by'] === (int)$actor['id']
                || (int)$key['owner_user_id'] === (int)$actor['id']
                || (int)$key['owner_referred_by'] === (int)$actor['id']
            )
        ) {
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
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $token = '';

        for ($i = 0; $i < 16; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'Team-Dark-'.$token;
    }

    private static function licenseValue(\PDO $pdo, string $customKey): string
    {
        $customKey = trim($customKey);

        if ($customKey !== '') {
            $uniqueChars = count(array_unique(
                str_split(strtolower($customKey))
            ));

            if (
                strlen($customKey) < 20
                || strlen($customKey) > 80
                || !preg_match('/^[A-Za-z0-9._-]+$/', $customKey)
                || !preg_match('/[A-Za-z]/', $customKey)
                || !preg_match('/[0-9]/', $customKey)
                || $uniqueChars < 8
            ) {
                throw new RuntimeException(
                    'Custom key must be 20–80 characters, include letters and numbers, and use at least 8 distinct characters.'
                );
            }

            [$lookupHash, $legacyHash] =
                Crypto::licenseLookupHashes($customKey);
            $q = $pdo->prepare(
                'SELECT id FROM license_keys WHERE key_hash IN (?,?) LIMIT 1'
            );
            $q->execute([$lookupHash, $legacyHash]);

            if ($q->fetch()) {
                throw new RuntimeException('Custom key already exists.');
            }

            return $customKey;
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $plain = self::newLicense();
            [$lookupHash, $legacyHash] =
                Crypto::licenseLookupHashes($plain);
            $q = $pdo->prepare(
                'SELECT id FROM license_keys WHERE key_hash IN (?,?) LIMIT 1'
            );
            $q->execute([$lookupHash, $legacyHash]);

            if (!$q->fetch()) {
                return $plain;
            }
        }

        throw new RuntimeException('Could not generate a unique key. Try again.');
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
