<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use PDOException;
use RuntimeException;
use Throwable;

final class LoaderAuthService
{
    // Compatibility secret pinned to the current TeamDarkLoader native verifier.
    // It is never returned by HTML or API responses.
    private const NATIVE_CONTRACT_SECRET = 'Vm8Lk7Uj2JmsjCPVPVjrLa7zgfx3uz9E';

    public static function makeToken(
        string $game,
        string $userKey,
        string $serial
    ): string {
        $configured = (string)Config::get('teamdark_auth_secret', '');
        $secret = self::NATIVE_CONTRACT_SECRET;

        // A stale/placeholder server .env must not produce a valid-key token mismatch.
        // Keep logging the configuration problem so the Owner can repair .env later.
        if ($configured === '' || !hash_equals(
            hash('sha256', $secret),
            hash('sha256', $configured)
        )) {
            error_log('TeamDark /connect native token secret differs from server .env; using pinned native compatibility contract.');
        }

        // Exact TeamDarkLoader native contract:
        // PUBG-user_key-serial-SERVER_SECRET
        // md5() returns lowercase hex, matching CalcMD5() in main.cpp.
        return md5(
            $game . '-' .
            $userKey . '-' .
            $serial . '-' .
            $secret
        );
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
            strlen($game) > 16 ||
            strlen($userKey) > 200 ||
            strlen($serial) > 255
        ) {
            return self::fail('Invalid Request');
        }

        // Token generation is pinned to the current native contract in makeToken().
        // TEAMDARK_AUTH_SECRET remains a configuration health signal only.
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            [$keyHash, $legacyKeyHash] =
                Crypto::licenseLookupHashes($userKey);

            $q = $pdo->prepare(
                "SELECT k.*, u.status AS account_status
                 FROM license_keys k
                 JOIN users u ON u.id=k.owner_user_id
                 WHERE k.key_hash IN (?,?)
                 ORDER BY CASE WHEN k.key_hash=? THEN 0 ELSE 1 END
                 LIMIT 1
                 FOR UPDATE"
            );
            $q->execute([$keyHash, $legacyKeyHash, $keyHash]);
            $key = $q->fetch();

            if (!$key) {
                $pdo->rollBack();
                return self::fail('Invalid Key');
            }

            // Transparently upgrade an old plain SHA-256 lookup hash to APP_KEY HMAC.
            // This changes only server storage; the native /connect response is untouched.
            if (
                array_key_exists('key_hash_version', $key)
                && (
                    (int)$key['key_hash_version'] < 2
                    || hash_equals((string)$key['key_hash'], $legacyKeyHash)
                )
            ) {
                $pdo->prepare(
                    'UPDATE license_keys
                     SET key_hash=?,key_hash_version=2
                     WHERE id=?'
                )->execute([$keyHash, $key['id']]);
                $key['key_hash'] = $keyHash;
                $key['key_hash_version'] = 2;
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
            $unlimitedExpiry =
                (int)($key['unlimited_expiry'] ?? 0) === 1;

            if (
                !$unlimitedExpiry &&
                !empty($key['expires_at']) &&
                strtotime((string)$key['expires_at']) <= $nowTs
            ) {
                $pdo->prepare(
                    "UPDATE license_keys SET status='expired' WHERE id=?"
                )->execute([$key['id']]);

                $pdo->commit();
                return self::fail('Key Expired');
            }

            // First successful login starts the countdown.
            // The license row is locked, so concurrent first-use requests
            // cannot race to set different activation timestamps.
            if (empty($key['activated_at']) || $status === 'unused') {
                $activatedAt = $nowString;
                $expiresAt = null;

                if (!$unlimitedExpiry) {
                    $durationSeconds = max(
                        3600,
                        (int)($key['duration_seconds'] ?? 86400)
                    );

                    $expiresAt = date(
                        'Y-m-d H:i:s',
                        $nowTs + $durationSeconds
                    );
                }

                $pdo->prepare(
                    "UPDATE license_keys
                     SET activated_at=?,
                         expires_at=?,
                         status='active',
                         last_used_at=?
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

            $deviceHash = Crypto::fingerprint($serial);
            $storedSerial = 'h:'.$deviceHash;
            $storedIp = $ipAddress === ''
                ? ''
                : 'h:'.substr(Crypto::fingerprint('ip|'.$ipAddress), 0, 43);

            $deviceQ = $pdo->prepare(
                "SELECT id,active
                 FROM license_devices
                 WHERE license_key_id=? AND device_hash=?
                 LIMIT 1"
            );
            $deviceQ->execute([$key['id'], $deviceHash]);
            $device = $deviceQ->fetch();

            $unlimitedDevices =
                (int)($key['unlimited_devices'] ?? 0) === 1;
            $maxDevices = max(
                1,
                (int)($key['max_devices'] ?? 1)
            );

            if ($device && (int)$device['active'] === 1) {
                // Same serial: do not consume another slot.
                $pdo->prepare(
                    "UPDATE license_devices
                     SET serial=?,last_seen_at=?,ip_address=?
                     WHERE id=?"
                )->execute([
                    $storedSerial,
                    $nowString,
                    $storedIp,
                    $device['id'],
                ]);
            } else {
                $countQ = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM license_devices
                     WHERE license_key_id=? AND active=1"
                );
                $countQ->execute([$key['id']]);
                $usedDevices = (int)$countQ->fetchColumn();

                if (
                    !$unlimitedDevices &&
                    $usedDevices >= $maxDevices
                ) {
                    $pdo->rollBack();
                    return self::fail('Device Limit Reached');
                }

                if ($device) {
                    // Previously reset serial: reactivate the same unique row.
                    $pdo->prepare(
                        "UPDATE license_devices
                         SET active=1,
                             device_hash=?,
                             serial=?,
                             first_seen_at=?,
                             last_seen_at=?,
                             ip_address=?
                         WHERE id=?"
                    )->execute([
                        $deviceHash,
                        $storedSerial,
                        $nowString,
                        $nowString,
                        $storedIp,
                        $device['id'],
                    ]);
                } else {
                    try {
                        $pdo->prepare(
                            "INSERT INTO license_devices(
                                license_key_id,
                                device_hash,
                                serial,
                                device_label,
                                first_seen_at,
                                last_seen_at,
                                ip_address,
                                active
                             ) VALUES(?,?,?,?,?,?,?,1)"
                        )->execute([
                            $key['id'],
                            $deviceHash,
                            $storedSerial,
                            '',
                            $nowString,
                            $nowString,
                            $storedIp,
                        ]);
                    } catch (PDOException $e) {
                        // Defensive duplicate recovery. The key row lock
                        // already serializes normal device registrations.
                        if ($e->getCode() !== '23000') {
                            throw $e;
                        }

                        $again = $pdo->prepare(
                            "SELECT id
                             FROM license_devices
                             WHERE license_key_id=? AND device_hash=?
                             LIMIT 1"
                        );
                        $again->execute([$key['id'], $deviceHash]);
                        $found = $again->fetch();

                        if (!$found) {
                            throw $e;
                        }

                        $pdo->prepare(
                            "UPDATE license_devices
                             SET active=1,
                                 serial=?,
                                 last_seen_at=?,
                                 ip_address=?
                             WHERE id=?"
                        )->execute([
                            $storedSerial,
                            $nowString,
                            $storedIp,
                            $found['id'],
                        ]);
                    }
                }
            }

            $pdo->prepare(
                "UPDATE license_keys
                 SET last_used_at=?
                 WHERE id=?"
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
                Security::audit(
                    (int)$key['owner_user_id'],
                    'loader_login_success',
                    [
                        'license_id'=>(int)$key['id'],
                        'serial_hash'=>substr(
                            hash('sha256', $serial),
                            0,
                            16
                        ),
                        'used_devices'=>$usedDevices,
                    ]
                );
            } catch (Throwable) {
                // Login must not fail because optional audit logging failed.
            }

            // Must be fresh for the native ±60 second check.
            $rng = time();

            $expiredDate = $unlimitedExpiry
                ? 'UNLIMITED'
                : (string)$key['expires_at'];

            return [
                'status'=>true,
                'data'=>[
                    'token'=>self::makeToken(
                        $game,
                        $userKey,
                        $serial
                    ),
                    'rng'=>$rng,
                    'expired_date'=>$expiredDate,
                    'exdate'=>$expiredDate,
                    'EXP'=>$expiredDate,
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
            'status'=>false,
            'reason'=>$reason,
        ];
    }
}
