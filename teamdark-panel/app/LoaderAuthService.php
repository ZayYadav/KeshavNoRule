<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use DateInterval;
use DateTimeImmutable;
use PDOException;
use RuntimeException;
use Throwable;

final class LoaderAuthService
{
    public static function makeToken(string $game, string $userKey, string $serial): string
    {
        $secret = (string)Config::get('teamdark_auth_secret', '');
        if ($secret === '') {
            throw new RuntimeException('TeamDark loader auth secret is not configured.');
        }

        // 1:1 with TeamDarkLoader main.cpp:
        // PUBG-user_key-serial-SERVER_SECRET -> lowercase MD5 hex.
        return md5($game . '-' . $userKey . '-' . $serial . '-' . $secret);
    }

    public static function authenticate(
        string $game,
        string $userKey,
        string $serial,
        string $ipAddress
    ): array {
        $game = trim($game);
        $userKey = trim($userKey);
        $serial = trim($serial);
        $ipAddress = substr(trim($ipAddress), 0, 45);

        if ($game === '' || $userKey === '' || $serial === '') {
            return self::fail('Missing Parameters');
        }

        if ($game !== 'PUBG') {
            return self::fail('Invalid Game');
        }

        if (
            strlen($game) > 16
            || strlen($userKey) > 200
            || strlen($serial) > 255
        ) {
            return self::fail('Invalid Request');
        }

        // Fail closed if the server was deployed without the matching native secret.
        if ((string)Config::get('teamdark_auth_secret', '') === '') {
            throw new RuntimeException('TeamDark loader auth secret is not configured.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $keyHash = hash('sha256', $userKey);

            $q = $pdo->prepare(
                "SELECT k.*, u.status AS account_status
                 FROM license_keys k
                 JOIN users u ON u.id=k.owner_user_id
                 WHERE k.key_hash=?
                 LIMIT 1
                 FOR UPDATE"
            );
            $q->execute([$keyHash]);
            $key = $q->fetch();

            if (!$key) {
                $pdo->rollBack();
                return self::fail('Invalid Key');
            }

            if (($key['game'] ?? 'PUBG') !== $game) {
                $pdo->rollBack();
                return self::fail('Invalid Game');
            }

            if (($key['account_status'] ?? 'active') !== 'active') {
                $pdo->rollBack();
                return self::fail('Key Disabled');
            }

            $status = strtolower((string)$key['status']);

            if ($status === 'revoked') {
                $pdo->rollBack();
                return self::fail('Key Revoked');
            }

            if ($status === 'disabled') {
                $pdo->rollBack();
                return self::fail('Key Disabled');
            }

            if ($status === 'expired') {
                $pdo->rollBack();
                return self::fail('Key Expired');
            }

            $nowTs = time();
            $nowString = date('Y-m-d H:i:s', $nowTs);
            $unlimitedExpiry = (int)($key['unlimited_expiry'] ?? 0) === 1;

            // Existing active key: expire it before any device mutation.
            if (
                !$unlimitedExpiry
                && !empty($key['expires_at'])
                && strtotime((string)$key['expires_at']) <= $nowTs
            ) {
                $pdo->prepare(
                    "UPDATE license_keys SET status='expired' WHERE id=?"
                )->execute([$key['id']]);

                $pdo->commit();
                return self::fail('Key Expired');
            }

            // First successful use starts the lifetime. The key row is locked,
            // so only one concurrent request can initialize this timestamp.
            if (empty($key['activated_at']) || $status === 'unused') {
                $activatedAt = $nowString;
                $expiresAt = null;

                if (!$unlimitedExpiry) {
                    $durationSeconds = max(3600, (int)($key['duration_seconds'] ?? 86400));
                    $expiresAt = date('Y-m-d H:i:s', $nowTs + $durationSeconds);
                }

                $pdo->prepare(
                    "UPDATE license_keys
                     SET activated_at=?, expires_at=?, status='active', last_used_at=?
                     WHERE id=?"
                )->execute([
                    $activatedAt,
                    $expiresAt,
                    $nowString,
                    $key['id'],
                ]);

                $key['activated_at'] = $activatedAt;
                $key['expires_at'] = $expiresAt;
                $key['status'] = 'active';
            }

            $deviceQ = $pdo->prepare(
                "SELECT id,active
                 FROM license_devices
                 WHERE license_key_id=? AND serial=?
                 LIMIT 1"
            );
            $deviceQ->execute([$key['id'], $serial]);
            $existingDevice = $deviceQ->fetch();

            $unlimitedDevices = (int)($key['unlimited_devices'] ?? 0) === 1;
            $maxDevices = max(1, (int)($key['max_devices'] ?? 1));

            if ($existingDevice && (int)$existingDevice['active'] === 1) {
                $pdo->prepare(
                    "UPDATE license_devices
                     SET last_seen_at=?, ip_address=?
                     WHERE id=?"
                )->execute([
                    $nowString,
                    $ipAddress,
                    $existingDevice['id'],
                ]);
            } else {
                $countQ = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM license_devices
                     WHERE license_key_id=? AND active=1"
                );
                $countQ->execute([$key['id']]);
                $usedDevices = (int)$countQ->fetchColumn();

                if (!$unlimitedDevices && $usedDevices >= $maxDevices) {
                    $pdo->rollBack();
                    return self::fail('Device Limit Reached');
                }

                $deviceHash = Crypto::fingerprint($serial);

                if ($existingDevice) {
                    $pdo->prepare(
                        "UPDATE license_devices
                         SET active=1, device_hash=?, first_seen_at=?, last_seen_at=?, ip_address=?
                         WHERE id=?"
                    )->execute([
                        $deviceHash,
                        $nowString,
                        $nowString,
                        $ipAddress,
                        $existingDevice['id'],
                    ]);
                } else {
                    try {
                        $pdo->prepare(
                            "INSERT INTO license_devices(
                            license_key_id,device_hash,serial,device_label,
                            first_seen_at,last_seen_at,ip_address,active
                         ) VALUES(?,?,?,?,?,?,?,1)"
                    )->execute([
                        $key['id'],
                        $deviceHash,
                        $serial,
                        '',
                        $nowString,
                        $nowString,
                        $ipAddress,
                    ]);
                } catch (PDOException $e) {
                    // Defensive duplicate handling. The key row lock already
                    // serializes normal registrations for this license.
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }

                    $again = $pdo->prepare(
                        "SELECT id
                         FROM license_devices
                         WHERE license_key_id=? AND serial=? AND active=1
                         LIMIT 1"
                    );
                    $again->execute([$key['id'], $serial]);
                    $found = $again->fetch();

                    if (!$found) {
                        throw $e;
                    }

                    $pdo->prepare(
                        "UPDATE license_devices
                         SET last_seen_at=?, ip_address=?
                         WHERE id=?"
                    )->execute([
                        $nowString,
                        $ipAddress,
                        $found['id'],
                    ]);
                }
            }

            $pdo->prepare(
                "UPDATE license_keys SET last_used_at=? WHERE id=?"
            )->execute([
                $nowString,
                $key['id'],
            ]);

            $countQ = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM license_devices
                 WHERE license_key_id=? AND active=1"
            );
            $countQ->execute([$key['id']]);
            $usedDevices = (int)$countQ->fetchColumn();

            $pdo->commit();

            try {
                Security::audit((int)$key['owner_user_id'], 'loader_login_success', [
                    'license_id'=>(int)$key['id'],
                    'serial_hash'=>substr(hash('sha256', $serial), 0, 16),
                    'used_devices'=>$usedDevices,
                ]);
            } catch (Throwable) {
                // Auth must not fail because non-critical audit logging failed.
            }

            $rng = time();
            $expiredDate = $unlimitedExpiry
                ? 'UNLIMITED'
                : (string)$key['expires_at'];

            return [
                'status' => true,
                'data' => [
                    'token' => self::makeToken($game, $userKey, $serial),
                    'rng' => $rng,
                    'expired_date' => $expiredDate,
                    'exdate' => $expiredDate,
                    'EXP' => $expiredDate,
                ],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private static function fail(string $reason): array
    {
        return [
            'status' => false,
            'reason' => $reason,
        ];
    }
}
