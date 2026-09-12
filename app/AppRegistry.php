<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class AppRegistry
{
    public const OFFICIAL_ID = 1;
    public const MAX_REFERRAL_APPS = 25;
    public const MAX_ENDPOINT_PREFIX = 24;

    public static function normalizeIds(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);
        $ids = [];
        foreach ($values as $value) {
            if (is_int($value) || is_string($value)) {
                $id = (int)$value;
                if ($id > 0) $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        if (count($ids) > self::MAX_REFERRAL_APPS) {
            throw new RuntimeException('Too many application APIs selected.');
        }
        return $ids;
    }

    public static function official(): array
    {
        $q = Database::pdo()->prepare(
            'SELECT * FROM app_registry WHERE id=? AND is_official=1 LIMIT 1'
        );
        $q->execute([self::OFFICIAL_ID]);
        $row = $q->fetch();
        if (!$row) throw new RuntimeException('Official application API is not installed. Import the latest schema.sql.');
        return $row;
    }

    public static function allApps(bool $activeOnly = false): array
    {
        $sql = "SELECT a.*,
                       (SELECT COUNT(*) FROM user_app_access ua WHERE ua.app_id=a.id) AS user_count,
                       (SELECT COUNT(*) FROM referral_app_access ra JOIN referral_invites ri ON ri.id=ra.referral_id WHERE ra.app_id=a.id AND ri.status='pending') AS pending_referrals
                FROM app_registry a";
        if ($activeOnly) $sql .= " WHERE a.status='active'";
        $sql .= ' ORDER BY a.is_official DESC,a.name ASC,a.id ASC';
        return Database::pdo()->query($sql)->fetchAll() ?: [];
    }

    public static function activeForUser(array $actor): array
    {
        if (($actor['role'] ?? '') === 'owner') {
            return self::allApps(true);
        }

        $q = Database::pdo()->prepare(
            "SELECT a.*
             FROM app_registry a
             JOIN user_app_access ua ON ua.app_id=a.id
             WHERE ua.user_id=? AND a.status='active'
             ORDER BY a.is_official DESC,a.name ASC,a.id ASC"
        );
        $q->execute([(int)($actor['id'] ?? 0)]);
        return $q->fetchAll() ?: [];
    }

    public static function availableForReferral(array $actor): array
    {
        if (!in_array(($actor['role'] ?? ''), ['owner','admin'], true)) {
            return [];
        }
        return self::activeForUser($actor);
    }

    public static function defaultReferralAppIds(array $actor): array
    {
        $apps = self::availableForReferral($actor);
        if (!$apps) return [];

        foreach ($apps as $app) {
            if ((int)$app['id'] === self::OFFICIAL_ID) return [self::OFFICIAL_ID];
        }
        return [(int)$apps[0]['id']];
    }

    public static function validateReferralApps(array $actor, mixed $raw): array
    {
        $ids = self::normalizeIds($raw);
        if (!$ids) throw new RuntimeException('Select at least one application API for this referral.');

        $allowed = [];
        foreach (self::availableForReferral($actor) as $app) {
            $allowed[(int)$app['id']] = true;
        }
        foreach ($ids as $id) {
            if (!isset($allowed[$id])) {
                throw new RuntimeException('You cannot allot one or more selected application APIs.');
            }
        }
        return $ids;
    }

    public static function generationApp(array $actor, ?int $requestedId = null): array
    {
        $apps = self::activeForUser($actor);
        if (!$apps) throw new RuntimeException('No application API is assigned to your account.');

        if ($requestedId === null || $requestedId <= 0) {
            foreach ($apps as $app) {
                if ((int)$app['id'] === self::OFFICIAL_ID) return $app;
            }
            return $apps[0];
        }

        foreach ($apps as $app) {
            if ((int)$app['id'] === $requestedId) return $app;
        }
        throw new RuntimeException('Selected application API is not assigned to your account.');
    }

    public static function userHasApp(int $userId, string $role, int $appId): bool
    {
        if ($role === 'owner') return true;
        if ($userId <= 0 || $appId <= 0) return false;
        $q = Database::pdo()->prepare(
            "SELECT 1
             FROM user_app_access ua
             JOIN app_registry a ON a.id=ua.app_id
             WHERE ua.user_id=? AND ua.app_id=? AND a.status='active'
             LIMIT 1"
        );
        $q->execute([$userId, $appId]);
        return (bool)$q->fetchColumn();
    }

    public static function resolveEndpoint(?string $token): ?array
    {
        $token = trim((string)$token);
        if ($token === '') {
            $q = Database::pdo()->prepare(
                "SELECT * FROM app_registry WHERE id=? AND is_official=1 AND status='active' LIMIT 1"
            );
            $q->execute([self::OFFICIAL_ID]);
            return $q->fetch() ?: null;
        }

        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) return null;
        $q = Database::pdo()->prepare(
            "SELECT * FROM app_registry WHERE endpoint_token=? AND status='active' LIMIT 1"
        );
        $q->execute([$token]);
        return $q->fetch() ?: null;
    }

    public static function endpointUrl(array $app): string
    {
        $base = rtrim((string)Config::get('app_url', ''), '/');
        if ($base === '') {
            $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: '';
            if ($host !== '') $base = (Security::isHttpsRequest() ? 'https://' : 'http://').$host;
        }

        $path = (int)($app['is_official'] ?? 0) === 1
            ? '/connect'
            : '/connect/'.rawurlencode((string)$app['endpoint_token']);
        return $base !== '' ? $base.$path : $path;
    }

    private static function normalizeEndpointPrefix(mixed $raw): string
    {
        $prefix = trim((string)$raw);
        if ($prefix === '') return '';
        if (
            strlen($prefix) > self::MAX_ENDPOINT_PREFIX
            || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,22}[A-Za-z0-9])?$/', $prefix)
        ) {
            throw new RuntimeException('Endpoint prefix must be 1-24 letters, numbers, dash or underscore, and start/end with a letter or number.');
        }
        return $prefix;
    }

    private static function newEndpointToken(mixed $rawPrefix = ''): string
    {
        $prefix = self::normalizeEndpointPrefix($rawPrefix);
        $random = bin2hex(random_bytes($prefix === '' ? 16 : 12));
        return $prefix === '' ? $random : $prefix.'-'.$random;
    }

    public static function createApp(array $actor, string $name, string $notes = '', string $endpointPrefix = ''): array
    {
        Auth::requireRole($actor, 'owner');
        $name = trim($name);
        $notes = trim($notes);
        if ($name === '' || strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new RuntimeException('App API name must be 1-80 clean characters.');
        }
        if (strlen($notes) > 240) throw new RuntimeException('App API notes must be 240 bytes or fewer.');

        $pdo = Database::pdo();
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $token = self::newEndpointToken($endpointPrefix);
            try {
                $pdo->prepare(
                    "INSERT INTO app_registry(name,endpoint_token,notes,status,is_official,created_by)
                     VALUES(?,?,?,'active',0,?)"
                )->execute([$name, $token, $notes, (int)$actor['id']]);
                $id = (int)$pdo->lastInsertId();
                Security::audit((int)$actor['id'], 'app_api_created', ['app_id'=>$id,'name'=>$name]);
                $q = $pdo->prepare('SELECT * FROM app_registry WHERE id=? LIMIT 1');
                $q->execute([$id]);
                return $q->fetch() ?: ['id'=>$id,'name'=>$name,'endpoint_token'=>$token,'is_official'=>0,'status'=>'active'];
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                if ($attempt === 7) throw new RuntimeException('App API name already exists or endpoint generation collided.');
            }
        }
        throw new RuntimeException('Could not create application API.');
    }

    public static function setEnabled(array $actor, int $appId, bool $enabled): void
    {
        Auth::requireRole($actor, 'owner');
        if ($appId <= 0 || $appId === self::OFFICIAL_ID) {
            throw new RuntimeException('Official API cannot be disabled.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare(
                'SELECT id,status,is_official FROM app_registry WHERE id=? LIMIT 1 FOR UPDATE'
            );
            $q->execute([$appId]);
            $app = $q->fetch();
            if (!$app || (int)$app['is_official'] === 1) {
                throw new RuntimeException('Application API not found.');
            }

            $next = $enabled ? 'active' : 'disabled';
            if ((string)$app['status'] === $next) {
                throw new RuntimeException('Application API is already in that state.');
            }

            $pdo->prepare('UPDATE app_registry SET status=? WHERE id=?')
                ->execute([$next, $appId]);

            if (!$enabled) {
                $pdo->prepare(
                    "DELETE ra FROM referral_app_access ra
                     JOIN referral_invites ri ON ri.id=ra.referral_id
                     WHERE ra.app_id=? AND ri.status='pending'"
                )->execute([$appId]);
                $pdo->exec(
                    "UPDATE referral_invites ri
                     SET ri.status='revoked'
                     WHERE ri.status='pending'
                       AND NOT EXISTS (
                           SELECT 1 FROM referral_app_access ra
                           WHERE ra.referral_id=ri.id
                       )"
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        try {
            Security::audit((int)$actor['id'], 'app_api_status_changed', [
                'app_id'=>$appId,
                'enabled'=>$enabled,
            ]);
        } catch (Throwable) {
        }
    }

    public static function rotateEndpoint(array $actor, int $appId, string $endpointPrefix = ''): array
    {
        Auth::requireRole($actor, 'owner');
        if ($appId <= 0 || $appId === self::OFFICIAL_ID) {
            throw new RuntimeException('Official /connect endpoint is permanent and cannot be rotated.');
        }
        $pdo = Database::pdo();
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $token = self::newEndpointToken($endpointPrefix);
            try {
                $q = $pdo->prepare(
                    "UPDATE app_registry SET endpoint_token=? WHERE id=? AND is_official=0"
                );
                $q->execute([$token, $appId]);
                if ($q->rowCount() !== 1) throw new RuntimeException('Application API not found.');
                Security::audit((int)$actor['id'], 'app_api_endpoint_rotated', ['app_id'=>$appId]);
                $get = $pdo->prepare('SELECT * FROM app_registry WHERE id=? LIMIT 1');
                $get->execute([$appId]);
                return $get->fetch() ?: [];
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
            }
        }
        throw new RuntimeException('Could not rotate application endpoint.');
    }

    public static function userApps(int $userId): array
    {
        $q = Database::pdo()->prepare(
            "SELECT a.*,ua.created_at AS granted_at
             FROM user_app_access ua
             JOIN app_registry a ON a.id=ua.app_id
             WHERE ua.user_id=?
             ORDER BY a.is_official DESC,a.name ASC"
        );
        $q->execute([$userId]);
        return $q->fetchAll() ?: [];
    }

    public static function replaceUserAccess(array $actor, int $userId, mixed $rawIds): array
    {
        Auth::requireRole($actor, 'owner');
        if ($userId <= 0 || $userId === (int)$actor['id']) {
            throw new RuntimeException('Select a non-owner account to manage application access.');
        }
        $pdo = Database::pdo();
        $q = $pdo->prepare("SELECT id,username,role,status FROM users WHERE id=? AND role<>'owner' LIMIT 1");
        $q->execute([$userId]);
        $target = $q->fetch();
        if (!$target) throw new RuntimeException('Target user not found.');

        $ids = self::normalizeIds($rawIds);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $check = $pdo->prepare("SELECT id FROM app_registry WHERE status='active' AND id IN ($placeholders)");
            $check->execute($ids);
            $valid = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN) ?: []);
            sort($valid, SORT_NUMERIC);
            $expected = $ids; sort($expected, SORT_NUMERIC);
            if ($valid !== $expected) throw new RuntimeException('One or more application APIs are invalid or disabled.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_app_access WHERE user_id=?')->execute([$userId]);
            $insert = $pdo->prepare(
                "INSERT INTO user_app_access(user_id,app_id,granted_by,source) VALUES(?,?,?,'owner')"
            );
            foreach ($ids as $id) $insert->execute([$userId, $id, (int)$actor['id']]);

            if (($target['role'] ?? '') === 'admin') {
                if ($ids) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $params = array_merge([$userId], $ids);
                    $pdo->prepare(
                        "DELETE ra FROM referral_app_access ra
                         JOIN referral_invites ri ON ri.id=ra.referral_id
                         WHERE ri.created_by=? AND ri.status='pending'
                           AND ra.app_id NOT IN ($placeholders)"
                    )->execute($params);
                } else {
                    $pdo->prepare(
                        "DELETE ra FROM referral_app_access ra
                         JOIN referral_invites ri ON ri.id=ra.referral_id
                         WHERE ri.created_by=? AND ri.status='pending'"
                    )->execute([$userId]);
                }
                $pdo->prepare(
                    "UPDATE referral_invites ri
                     SET ri.status='revoked'
                     WHERE ri.created_by=? AND ri.status='pending'
                       AND NOT EXISTS (SELECT 1 FROM referral_app_access ra WHERE ra.referral_id=ri.id)"
                )->execute([$userId]);
            }

            Security::audit((int)$actor['id'], 'user_app_access_replaced', [
                'target_id'=>$userId,
                'app_ids'=>$ids,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $ids;
    }

    public static function attachAppsToReferral(PDO $pdo, array $actor, int $inviteId, mixed $rawIds): array
    {
        $ids = self::validateReferralApps($actor, $rawIds);
        $insert = $pdo->prepare(
            'INSERT INTO referral_app_access(referral_id,app_id) VALUES(?,?)'
        );
        foreach ($ids as $id) $insert->execute([$inviteId, $id]);
        return $ids;
    }

    public static function grantReferralToUser(PDO $pdo, int $inviteId, int $userId, int $grantedBy): array
    {
        $q = $pdo->prepare(
            "SELECT a.id
             FROM referral_app_access ra
             JOIN app_registry a ON a.id=ra.app_id
             WHERE ra.referral_id=? AND a.status='active'
             ORDER BY a.is_official DESC,a.id ASC"
        );
        $q->execute([$inviteId]);
        $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!$ids) throw new RuntimeException('This referral has no active application API assigned.');

        $insert = $pdo->prepare(
            "INSERT IGNORE INTO user_app_access(user_id,app_id,granted_by,source)
             VALUES(?,?,?,'referral')"
        );
        foreach ($ids as $id) $insert->execute([$userId, $id, $grantedBy]);
        return $ids;
    }
}
