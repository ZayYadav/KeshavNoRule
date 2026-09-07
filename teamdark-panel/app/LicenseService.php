<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use DateInterval;
use DateTimeImmutable;
use Throwable;

final class LicenseService
{
    public static function expireDue(): void
    {
        Database::pdo()
            ->prepare("UPDATE license_keys SET status='expired' WHERE status IN ('unused','active') AND expires_at IS NOT NULL AND expires_at <= NOW()")
            ->execute();
    }

    public static function activate(string $plainKey, string $deviceId, string $deviceLabel = ''): array
    {
        $plainKey = trim($plainKey);
        $deviceId = trim($deviceId);
        $deviceLabel = trim($deviceLabel);

        if (strlen($plainKey) < 12 || strlen($plainKey) > 200) {
            return ['ok'=>false, 'code'=>'INVALID_KEY', 'message'=>'Invalid license key.'];
        }
        if (strlen($deviceId) < 4 || strlen($deviceId) > 255) {
            return ['ok'=>false, 'code'=>'INVALID_DEVICE', 'message'=>'Invalid device identifier.'];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $keyHash = hash('sha256', $plainKey);
            $q = $pdo->prepare(
                "SELECT k.*, u.status AS owner_status
                 FROM license_keys k
                 JOIN users u ON u.id=k.owner_user_id
                 WHERE k.key_hash=?
                 LIMIT 1
                 FOR UPDATE"
            );
            $q->execute([$keyHash]);
            $row = $q->fetch();

            if (!$row) {
                $pdo->rollBack();
                return ['ok'=>false, 'code'=>'INVALID_KEY', 'message'=>'License key not found.'];
            }

            if ($row['owner_status'] !== 'active' || $row['status'] === 'disabled') {
                $pdo->rollBack();
                return ['ok'=>false, 'code'=>'KEY_DISABLED', 'message'=>'License key is disabled.'];
            }

            $nowRow = $pdo->query('SELECT NOW() AS now_value')->fetch();
            $now = new DateTimeImmutable((string)$nowRow['now_value']);

            if ($row['expires_at'] !== null && new DateTimeImmutable((string)$row['expires_at']) <= $now) {
                $pdo->prepare("UPDATE license_keys SET status='expired' WHERE id=?")->execute([$row['id']]);
                $pdo->commit();
                return [
                    'ok'=>false,
                    'code'=>'KEY_EXPIRED',
                    'message'=>'License key has expired.',
                    'expires_at'=>$row['expires_at'],
                ];
            }

            if ($row['status'] === 'expired') {
                $pdo->rollBack();
                return [
                    'ok'=>false,
                    'code'=>'KEY_EXPIRED',
                    'message'=>'License key has expired.',
                    'expires_at'=>$row['expires_at'],
                ];
            }

            if ($row['activated_at'] === null || $row['status'] === 'unused') {
                $duration = max(3600, (int)$row['duration_seconds']);
                $activatedAt = $now->format('Y-m-d H:i:s');
                $expiresAt = $now->add(new DateInterval('PT'.$duration.'S'))->format('Y-m-d H:i:s');

                $pdo->prepare(
                    "UPDATE license_keys
                     SET activated_at=?, expires_at=?, status='active', last_used_at=?
                     WHERE id=?"
                )->execute([$activatedAt, $expiresAt, $activatedAt, $row['id']]);

                $row['activated_at'] = $activatedAt;
                $row['expires_at'] = $expiresAt;
                $row['status'] = 'active';
            }

            $deviceHash = Crypto::fingerprint($deviceId);
            $existing = $pdo->prepare(
                "SELECT id FROM license_devices WHERE license_key_id=? AND device_hash=? LIMIT 1"
            );
            $existing->execute([$row['id'], $deviceHash]);
            $device = $existing->fetch();

            if (!$device) {
                $countQ = $pdo->prepare("SELECT COUNT(*) FROM license_devices WHERE license_key_id=?");
                $countQ->execute([$row['id']]);
                $used = (int)$countQ->fetchColumn();
                $maxDevices = max(1, (int)$row['max_devices']);

                if ($used >= $maxDevices) {
                    $pdo->rollBack();
                    return [
                        'ok'=>false,
                        'code'=>'DEVICE_LIMIT',
                        'message'=>'Maximum device limit reached.',
                        'max_devices'=>$maxDevices,
                        'used_devices'=>$used,
                    ];
                }

                $pdo->prepare(
                    "INSERT INTO license_devices(license_key_id,device_hash,device_label,first_seen_at,last_seen_at)
                     VALUES(?,?,?,NOW(),NOW())"
                )->execute([
                    $row['id'],
                    $deviceHash,
                    substr($deviceLabel, 0, 120),
                ]);
            } else {
                $pdo->prepare(
                    "UPDATE license_devices
                     SET last_seen_at=NOW(), device_label=CASE WHEN ?<>'' THEN ? ELSE device_label END
                     WHERE id=?"
                )->execute([
                    substr($deviceLabel, 0, 120),
                    substr($deviceLabel, 0, 120),
                    $device['id'],
                ]);
            }

            $pdo->prepare("UPDATE license_keys SET last_used_at=NOW() WHERE id=?")->execute([$row['id']]);

            $countQ = $pdo->prepare("SELECT COUNT(*) FROM license_devices WHERE license_key_id=?");
            $countQ->execute([$row['id']]);
            $used = (int)$countQ->fetchColumn();
            $maxDevices = max(1, (int)$row['max_devices']);

            $pdo->commit();

            Security::audit((int)$row['owner_user_id'], 'license_validated', [
                'license_id'=>(int)$row['id'],
                'device_hash'=>substr($deviceHash, 0, 16),
            ]);

            return [
                'ok'=>true,
                'code'=>'ACTIVE',
                'message'=>'License active.',
                'license'=>[
                    'id'=>(int)$row['id'],
                    'status'=>'active',
                    'activated_at'=>$row['activated_at'],
                    'expires_at'=>$row['expires_at'],
                    'duration_seconds'=>(int)$row['duration_seconds'],
                    'max_devices'=>$maxDevices,
                    'used_devices'=>$used,
                    'remaining_devices'=>max(0, $maxDevices - $used),
                    'server_time'=>$now->format('Y-m-d H:i:s'),
                ],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
