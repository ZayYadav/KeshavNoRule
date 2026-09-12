<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;
    private static bool $compatibilityChecked = false;

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Config::get('db_host'),
            Config::get('db_port'),
            Config::get('db_name')
        );

        self::$pdo = new PDO(
            $dsn,
            Config::get('db_user'),
            Config::get('db_pass'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );

        self::ensureCompatibility(self::$pdo);
        return self::$pdo;
    }

    /**
     * Keep already-installed panels compatible with schema additions that are
     * required by the multi-application API feature. This is deliberately
     * narrow: it does not replace a normal schema.sql installation.
     *
     * The one-time backfills only run when an access table is first created.
     * A later request can therefore never resurrect access that the Owner
     * intentionally removed.
     */
    private static function ensureCompatibility(PDO $pdo): void
    {
        if (self::$compatibilityChecked) return;

        foreach (['users', 'referral_invites', 'license_keys'] as $table) {
            if (!self::tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'Panel database base schema is incomplete. Import the latest database/schema.sql.'
                );
            }
        }

        $lockName = 'teamdark_multi_app_schema_v2';
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 8)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Panel database upgrade is busy. Retry in a few seconds.');
        }

        try {
            $userAccessExisted = self::tableExists($pdo, 'user_app_access');
            $referralAccessExisted = self::tableExists($pdo, 'referral_app_access');

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS app_registry (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(80) NOT NULL,
                    endpoint_token VARCHAR(64) NOT NULL UNIQUE,
                    notes VARCHAR(240) NOT NULL DEFAULT '',
                    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
                    is_official TINYINT(1) NOT NULL DEFAULT 0,
                    created_by BIGINT UNSIGNED NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_app_registry_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                    UNIQUE KEY uq_app_registry_name(name),
                    INDEX idx_app_registry_status(status),
                    INDEX idx_app_registry_official(is_official)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $pdo->exec(
                "INSERT INTO app_registry(id,name,endpoint_token,notes,status,is_official,created_by)
                 VALUES(1,'Official','official','Permanent backward-compatible /connect application API.','active',1,NULL)
                 ON DUPLICATE KEY UPDATE
                   name=IF(is_official=1,name,VALUES(name)),
                   status=IF(is_official=1,'active',status),
                   is_official=IF(id=1,1,is_official)"
            );

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS user_app_access (
                    user_id BIGINT UNSIGNED NOT NULL,
                    app_id BIGINT UNSIGNED NOT NULL,
                    granted_by BIGINT UNSIGNED NULL,
                    source VARCHAR(24) NOT NULL DEFAULT 'owner',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY(user_id,app_id),
                    CONSTRAINT fk_user_app_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_user_app_app FOREIGN KEY (app_id) REFERENCES app_registry(id) ON DELETE CASCADE,
                    CONSTRAINT fk_user_app_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX idx_user_app_app(app_id),
                    INDEX idx_user_app_granter(granted_by)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            if (!$userAccessExisted) {
                $pdo->exec(
                    "INSERT IGNORE INTO user_app_access(user_id,app_id,granted_by,source)
                     SELECT id,1,NULL,'migration' FROM users"
                );
            }

            if (!self::columnExists($pdo, 'referral_invites', 'expires_at')) {
                $pdo->exec(
                    'ALTER TABLE referral_invites ADD COLUMN expires_at DATETIME NULL AFTER status'
                );
                $pdo->exec(
                    "UPDATE referral_invites
                     SET expires_at=DATE_ADD(created_at,INTERVAL 7 DAY)
                     WHERE expires_at IS NULL AND status='pending'"
                );
            }

            if (!self::columnExists($pdo, 'referral_invites', 'grant_balance')) {
                $pdo->exec(
                    'ALTER TABLE referral_invites ADD COLUMN grant_balance BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER role'
                );
            }

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS referral_app_access (
                    referral_id BIGINT UNSIGNED NOT NULL,
                    app_id BIGINT UNSIGNED NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY(referral_id,app_id),
                    CONSTRAINT fk_ref_app_referral FOREIGN KEY (referral_id) REFERENCES referral_invites(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ref_app_app FOREIGN KEY (app_id) REFERENCES app_registry(id) ON DELETE CASCADE,
                    INDEX idx_ref_app_app(app_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            if (!$referralAccessExisted) {
                $pdo->exec(
                    'INSERT IGNORE INTO referral_app_access(referral_id,app_id) SELECT id,1 FROM referral_invites'
                );
            }

            if (!self::columnExists($pdo, 'license_keys', 'app_id')) {
                $pdo->exec(
                    'ALTER TABLE license_keys ADD COLUMN app_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER telegram_user_id'
                );
            }

            $pdo->exec('UPDATE license_keys SET app_id=1 WHERE app_id IS NULL OR app_id=0');

            if (!self::indexExists($pdo, 'license_keys', 'idx_keys_app')) {
                $pdo->exec('ALTER TABLE license_keys ADD INDEX idx_keys_app(app_id)');
            }

            self::$compatibilityChecked = true;
        } catch (PDOException $e) {
            error_log(
                'TeamDark schema compatibility failure: '.$e->getCode().' '.$e->getMessage()
            );
            throw new RuntimeException(
                'Panel database upgrade could not be completed. Run the latest database/schema.sql and verify CREATE/ALTER privileges.',
                0,
                $e
            );
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
            }
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $q = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
        );
        $q->execute([$table]);
        return (bool)$q->fetchColumn();
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $q = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
        );
        $q->execute([$table, $column]);
        return (bool)$q->fetchColumn();
    }

    private static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $q = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1'
        );
        $q->execute([$table, $index]);
        return (bool)$q->fetchColumn();
    }
}
