-- TeamDark native /connect compatibility migration.
-- Back up the database before running this file.
-- Compatible with MySQL 8 and MariaDB-style cPanel databases.

ALTER TABLE users
  MODIFY balance BIGINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE balance_ledger
  MODIFY amount BIGINT NOT NULL;

DELIMITER $$

DROP PROCEDURE IF EXISTS td_add_column $$
CREATE PROCEDURE td_add_column(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = p_table
          AND column_name = p_column
    ) THEN
        SET @td_sql = CONCAT(
            'ALTER TABLE ',
            p_table,
            ' ADD COLUMN ',
            p_definition
        );
        PREPARE td_stmt FROM @td_sql;
        EXECUTE td_stmt;
        DEALLOCATE PREPARE td_stmt;
    END IF;
END $$

DROP PROCEDURE IF EXISTS td_add_index $$
CREATE PROCEDURE td_add_index(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = p_table
          AND index_name = p_index
    ) THEN
        SET @td_sql = CONCAT(
            'ALTER TABLE ',
            p_table,
            ' ADD ',
            p_definition
        );
        PREPARE td_stmt FROM @td_sql;
        EXECUTE td_stmt;
        DEALLOCATE PREPARE td_stmt;
    END IF;
END $$

DELIMITER ;

CALL td_add_column(
  'license_keys',
  'duration_seconds',
  'duration_seconds BIGINT UNSIGNED NOT NULL DEFAULT 86400 AFTER label'
);

CALL td_add_column(
  'license_keys',
  'activated_at',
  'activated_at DATETIME NULL AFTER duration_seconds'
);

CALL td_add_column(
  'license_keys',
  'last_used_at',
  'last_used_at DATETIME NULL AFTER expires_at'
);

CALL td_add_column(
  'license_keys',
  'max_devices',
  'max_devices INT UNSIGNED NOT NULL DEFAULT 10 AFTER last_used_at'
);

CALL td_add_column(
  'license_keys',
  'game',
  'game VARCHAR(16) NOT NULL DEFAULT ''PUBG'' AFTER label'
);

CALL td_add_column(
  'license_keys',
  'unlimited_expiry',
  'unlimited_expiry TINYINT(1) NOT NULL DEFAULT 0 AFTER duration_seconds'
);

CALL td_add_column(
  'license_keys',
  'unlimited_devices',
  'unlimited_devices TINYINT(1) NOT NULL DEFAULT 0 AFTER max_devices'
);

ALTER TABLE license_keys
  MODIFY status ENUM('unused','active','expired','disabled','revoked')
  NOT NULL DEFAULT 'unused';

UPDATE license_keys
SET game='PUBG'
WHERE game IS NULL OR game='';

UPDATE license_keys
SET duration_seconds = GREATEST(
        TIMESTAMPDIFF(SECOND, NOW(), expires_at),
        3600
    )
WHERE expires_at IS NOT NULL
  AND activated_at IS NULL
  AND unlimited_expiry = 0
  AND expires_at > NOW();

UPDATE license_keys
SET expires_at = NULL,
    status = 'unused'
WHERE activated_at IS NULL
  AND status = 'active'
  AND unlimited_expiry = 0;

CREATE TABLE IF NOT EXISTS license_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_key_id BIGINT UNSIGNED NOT NULL,
  device_hash CHAR(64) NOT NULL,
  serial VARCHAR(255) NULL,
  device_label VARCHAR(120) NOT NULL DEFAULT '',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_device_key
    FOREIGN KEY (license_key_id)
    REFERENCES license_keys(id)
    ON DELETE CASCADE,
  INDEX idx_device_last_seen(last_seen_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CALL td_add_column(
  'license_devices',
  'serial',
  'serial VARCHAR(255) NULL AFTER device_hash'
);

CALL td_add_column(
  'license_devices',
  'ip_address',
  'ip_address VARCHAR(45) NOT NULL DEFAULT '''' AFTER last_seen_at'
);

CALL td_add_column(
  'license_devices',
  'active',
  'active TINYINT(1) NOT NULL DEFAULT 1 AFTER ip_address'
);

UPDATE license_devices
SET serial = CONCAT('legacy:', id, ':', device_hash),
    active = 0
WHERE serial IS NULL OR serial = '';

ALTER TABLE license_devices
  MODIFY serial VARCHAR(255) NOT NULL;

CALL td_add_index(
  'license_devices',
  'uq_key_serial',
  'UNIQUE KEY uq_key_serial (license_key_id, serial)'
);

CALL td_add_index(
  'license_devices',
  'idx_device_active',
  'INDEX idx_device_active (license_key_id, active)'
);

DROP PROCEDURE IF EXISTS td_add_column;
DROP PROCEDURE IF EXISTS td_add_index;
