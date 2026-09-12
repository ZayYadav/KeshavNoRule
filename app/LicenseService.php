<?php
declare(strict_types=1);

namespace TeamDark\Panel;

final class LicenseService
{
    public static function expireDue(): void
    {
        KeyManager::expireDue();
    }

    public static function activate(
        string $plainKey,
        string $deviceId,
        string $deviceLabel = ''
    ): array {
        $plainKey = trim($plainKey);
        $deviceId = trim($deviceId);
        $deviceLabel = trim($deviceLabel);

        if (strlen($plainKey) < 12 || strlen($plainKey) > 200) {
            return [
                'ok'=>false,
                'code'=>'INVALID_KEY',
                'message'=>'Invalid license key.',
            ];
        }

        if (strlen($deviceId) < 4 || strlen($deviceId) > 255) {
            return [
                'ok'=>false,
                'code'=>'INVALID_DEVICE',
                'message'=>'Invalid device identifier.',
            ];
        }

        $native = LoaderAuthService::authenticate(
            'PUBG',
            $plainKey,
            $deviceId,
            Security::clientIp()
        );

        if (!($native['status'] ?? false)) {
            $reason = (string)($native['reason'] ?? 'Access denied');

            $map = [
                'Invalid Key'=>['INVALID_KEY','License key not found.'],
                'Invalid Game'=>['INVALID_GAME','Invalid game.'],
                'Key Expired'=>['KEY_EXPIRED','License key has expired.'],
                'Key Disabled'=>['KEY_DISABLED','License key is disabled.'],
                'Key Revoked'=>['KEY_REVOKED','License key is revoked.'],
                'Device Limit Reached'=>['DEVICE_LIMIT','Maximum device limit reached.'],
                'Missing Parameters'=>['INVALID_REQUEST','Missing parameters.'],
                'Invalid Request'=>['INVALID_REQUEST','Invalid request.'],
            ];

            [$code, $message] = $map[$reason]
                ?? ['ACCESS_DENIED', $reason];

            return [
                'ok'=>false,
                'code'=>$code,
                'message'=>$message,
            ];
        }

        $pdo = Database::pdo();
        [$keyHash, $legacyKeyHash] =
            Crypto::licenseLookupHashes($plainKey);

        if ($deviceLabel !== '') {
            $pdo->prepare(
                "UPDATE license_devices d
                 JOIN license_keys k ON k.id=d.license_key_id
                 SET d.device_label=?
                 WHERE k.key_hash IN (?,?) AND d.device_hash=?"
            )->execute([
                substr($deviceLabel, 0, 120),
                $keyHash,
                $legacyKeyHash,
                Crypto::fingerprint($deviceId),
            ]);
        }

        $q = $pdo->prepare(
            "SELECT k.*,
                    (SELECT COUNT(*) FROM license_devices d
                     WHERE d.license_key_id=k.id AND d.active=1) AS used_devices
             FROM license_keys k
             WHERE k.key_hash IN (?,?)
             ORDER BY CASE WHEN k.key_hash=? THEN 0 ELSE 1 END
             LIMIT 1"
        );
        $q->execute([$keyHash, $legacyKeyHash, $keyHash]);
        $row = $q->fetch();

        if (!$row) {
            return [
                'ok'=>false,
                'code'=>'INVALID_KEY',
                'message'=>'License key not found.',
            ];
        }

        $unlimitedDevices = (int)$row['unlimited_devices'] === 1;
        $used = (int)$row['used_devices'];
        $maxDevices = $unlimitedDevices
            ? null
            : max(1, (int)$row['max_devices']);

        return [
            'ok'=>true,
            'code'=>'ACTIVE',
            'message'=>'License active.',
            'license'=>[
                'id'=>(int)$row['id'],
                'status'=>$row['status'],
                'activated_at'=>$row['activated_at'],
                'expires_at'=>(int)$row['unlimited_expiry'] === 1
                    ? 'UNLIMITED'
                    : $row['expires_at'],
                'duration_seconds'=>(int)$row['duration_seconds'],
                'unlimited_expiry'=>(int)$row['unlimited_expiry'] === 1,
                'max_devices'=>$maxDevices,
                'unlimited_devices'=>$unlimitedDevices,
                'used_devices'=>$used,
                'remaining_devices'=>$unlimitedDevices
                    ? null
                    : max(0, $maxDevices - $used),
                'server_time'=>date('Y-m-d H:i:s'),
            ],
        ];
    }
}
