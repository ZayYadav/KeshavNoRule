<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use PDO;
use RuntimeException;
use Throwable;

final class KeyEditor
{
    public static function get(array $actor, int $keyId): array
    {
        $row = self::rowForActor(Database::pdo(), $actor, $keyId, false);
        if (!$row) throw new RuntimeException('Key not found or not allowed.');

        try {
            $row['plain_key'] = Crypto::decrypt(
                $row['key_cipher'],
                $row['key_iv'],
                $row['key_tag']
            );
        } catch (Throwable) {
            throw new RuntimeException('Stored key could not be decrypted.');
        }

        return $row;
    }

    public static function save(
        array $actor,
        int $keyId,
        string $plainKey,
        string $label,
        int $durationDays,
        bool $unlimitedExpiry,
        int $maxDevices,
        bool $unlimitedDevices
    ): int {
        // Editing validity/device entitlement is a generation-class action.
        // Owner always bypasses this switch through PanelControl::assertGeneration().
        PanelControl::assertGeneration($actor);

        $plainKey = trim($plainKey);
        $label = trim($label);

        if (strlen($plainKey) < 5 || strlen($plainKey) > 80) {
            throw new RuntimeException('Key value must be 5–80 characters.');
        }
        if (strlen($label) > 100) {
            throw new RuntimeException('Label must be 100 characters or fewer.');
        }
        if (!$unlimitedExpiry && ($durationDays < 1 || $durationDays > 36500)) {
            throw new RuntimeException('Validity must be between 1 and 36500 days.');
        }
        if (!$unlimitedDevices && ($maxDevices < 1 || $maxDevices > 1000000)) {
            throw new RuntimeException('Device limit must be between 1 and 1000000.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $row = self::rowForActor($pdo, $actor, $keyId, true);
            if (!$row) throw new RuntimeException('Key not found or not allowed.');

            $deviceQ = $pdo->prepare(
                'SELECT COUNT(*) FROM license_devices WHERE license_key_id=? AND active=1'
            );
            $deviceQ->execute([$keyId]);
            $activeDevices = (int)$deviceQ->fetchColumn();

            if (!$unlimitedDevices && $maxDevices < $activeDevices) {
                throw new RuntimeException(
                    'Device limit cannot be lower than the currently active device count ('.$activeDevices.'). Reset devices first.'
                );
            }

            [$lookupHash, $legacyHash] = Crypto::licenseLookupHashes($plainKey);
            $dup = $pdo->prepare(
                'SELECT id FROM license_keys WHERE id<>? AND key_hash IN (?,?) LIMIT 1'
            );
            $dup->execute([$keyId, $lookupHash, $legacyHash]);
            if ($dup->fetch()) {
                throw new RuntimeException('That key value already exists.');
            }

            $durationSeconds = $unlimitedExpiry ? 0 : $durationDays * 86400;
            $oldUnlimited = (bool)$row['unlimited_expiry'];
            $oldDuration = (int)$row['duration_seconds'];
            $oldPrice = KeyManager::price($oldDuration, $oldUnlimited);
            $newPrice = KeyManager::price($durationSeconds, $unlimitedExpiry);
            $upgradeCost = ($actor['role'] ?? '') === 'owner'
                ? 0
                : max(0, $newPrice - $oldPrice);

            if ($upgradeCost > 0) {
                $balanceQ = $pdo->prepare(
                    'SELECT balance,status FROM users WHERE id=? FOR UPDATE'
                );
                $balanceQ->execute([(int)$actor['id']]);
                $account = $balanceQ->fetch();

                if (!$account || $account['status'] !== 'active') {
                    throw new RuntimeException('Account unavailable.');
                }

                $balance = (int)$account['balance'];
                if ($balance < $upgradeCost) {
                    throw new RuntimeException(
                        'Insufficient balance for this validity upgrade. Required: '.$upgradeCost.' credit(s).'
                    );
                }

                $pdo->prepare(
                    'UPDATE users SET balance=balance-? WHERE id=?'
                )->execute([$upgradeCost, (int)$actor['id']]);

                $pdo->prepare(
                    'INSERT INTO balance_ledger(user_id,actor_user_id,amount,reason) VALUES(?,?,?,?)'
                )->execute([
                    (int)$actor['id'],
                    (int)$actor['id'],
                    -$upgradeCost,
                    'License validity upgrade',
                ]);
            }

            $newHash = Crypto::licenseLookupHash($plainKey);
            $keyChanged = !hash_equals((string)$row['key_hash'], $newHash);
            [$cipher, $iv, $tag] = Crypto::encrypt($plainKey);
            $expiresAt = null;
            $status = (string)$row['status'];
            $activatedAt = $row['activated_at'] ? (string)$row['activated_at'] : null;

            if (!$unlimitedExpiry && $activatedAt !== null) {
                $activatedTs = strtotime($activatedAt);
                if ($activatedTs === false) {
                    throw new RuntimeException('Stored activation time is invalid.');
                }

                $expiresAt = date('Y-m-d H:i:s', $activatedTs + $durationSeconds);
                if ($status !== 'revoked') {
                    $status = strtotime($expiresAt) <= time()
                        ? 'expired'
                        : ($status === 'expired' ? 'active' : $status);
                }
            } elseif ($unlimitedExpiry && $status === 'expired') {
                $status = $activatedAt !== null ? 'active' : 'unused';
            }

            $q = $pdo->prepare(
                'UPDATE license_keys SET key_hash=?,key_hash_version=2,key_cipher=?,key_iv=?,key_tag=?,label=?,duration_seconds=?,unlimited_expiry=?,expires_at=?,max_devices=?,unlimited_devices=?,status=? WHERE id=?'
            );
            $q->execute([
                $newHash,
                $cipher,
                $iv,
                $tag,
                $label,
                $durationSeconds,
                $unlimitedExpiry ? 1 : 0,
                $expiresAt,
                $unlimitedDevices ? 1 : $maxDevices,
                $unlimitedDevices ? 1 : 0,
                $status,
                $keyId,
            ]);

            $pdo->commit();

            Security::audit((int)$actor['id'], 'license_edited', [
                'license_id'=>$keyId,
                'owner_id'=>(int)$row['owner_user_id'],
                'validity_days'=>$unlimitedExpiry ? null : $durationDays,
                'unlimited_expiry'=>$unlimitedExpiry,
                'max_devices'=>$unlimitedDevices ? null : $maxDevices,
                'unlimited_devices'=>$unlimitedDevices,
                'key_value_changed'=>$keyChanged,
                'upgrade_cost'=>$upgradeCost,
            ]);

            return $upgradeCost;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function rowForActor(PDO $pdo, array $actor, int $keyId, bool $forUpdate): ?array
    {
        if ($keyId <= 0) return null;
        $sql = "SELECT k.*,u.username owner_name,u.name owner_display_name,u.role owner_role
                FROM license_keys k JOIN users u ON u.id=k.owner_user_id WHERE k.id=?";
        $params = [$keyId];

        if (($actor['role'] ?? '') !== 'owner') {
            $sql .= ' AND k.owner_user_id=?';
            $params[] = (int)$actor['id'];
        }

        $sql .= ' LIMIT 1'.($forUpdate ? ' FOR UPDATE' : '');
        $q = $pdo->prepare($sql);
        $q->execute($params);
        return $q->fetch() ?: null;
    }
}
