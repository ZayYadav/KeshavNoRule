-- TeamDark native /connect compatibility migration.
-- BACK UP THE DATABASE FIRST.
-- Safe for the current TeamDark panel and MySQL 8 / modern MariaDB.
-- Run this file once on the existing panel database.

ALTER TABLE users
  MODIFY balance BIGINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE balance_ledger
  MODIFY amount BIGINT NOT NULL;

-- Helper pattern: only add a column/index when it is missing.

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='license_keys' AND column_name='duration_seconds'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_keys ADD COLUMN duration_seconds BIGINT UNSIGNED NOT NULL DEFAULT 86400 AFTER label',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='license_keys' AND column_name='activated_at'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_keys ADD COLUMN activated_at DATETIME NULL AFTER duration_seconds',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='license_keys' AND column_name='last_used_at'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_keys ADD COLUMN last_used_at DATETIME NULL AFTER expires_at',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='license_keys' AND column_name='max_devices'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_keys ADD COLUMN max_devices INT UNSIGNED NOT NULL DEFAULT 10 AFTER last_used_at',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='license_keys' AND column_name='game'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_keys ADD COLUMN game VARCHAR(16) NOT NULL DEFAULT ''PUBG'' AFTER label',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='license_keys' AND column_name='unlimited_expiry'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_keys ADD COLUMN unlimited_expiry TINYINT(1) NOT NULL DEFAULT 0 AFTER duration_seconds',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='license_keys' AND column_name='unlimited_devices'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_keys ADD COLUMN unlimited_devices TINYINT(1) NOT NULL DEFAULT 0 AFTER max_devices',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

ALTER TABLE license_keys
  MODIFY status ENUM('unused','active','expired','disabled','revoked')
  NOT NULL DEFAULT 'unused';

-- Preserve old remaining validity before switching unused records to first-use timing.
UPDATE license_keys
SET duration_seconds = GREATEST(TIMESTAMPDIFF(SECOND, NOW(), expires_at), 3600)
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

UPDATE license_keys
SET game='PUBG'
WHERE game IS NULL OR game='';

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=DATABASE() AND table_name='license_keys' AND index_name='idx_keys_expiry'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_keys ADD INDEX idx_keys_expiry (expires_at)',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=DATABASE() AND table_name='license_keys' AND index_name='idx_keys_game'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_keys ADD INDEX idx_keys_game (game)',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

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
    FOREIGN KEY (license_key_id) REFERENCES license_keys(id) ON DELETE CASCADE,
  INDEX idx_device_last_seen(last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='license_devices' AND column_name='serial'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_devices ADD COLUMN serial VARCHAR(255) NULL AFTER device_hash',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='license_devices' AND column_name='ip_address'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_devices ADD COLUMN ip_address VARCHAR(45) NOT NULL DEFAULT '''' AFTER last_seen_at',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='license_devices' AND column_name='active'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_devices ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER ip_address',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

-- Older panel versions stored only a non-reversible device fingerprint.
-- Preserve those rows for history, but mark them reset/inactive because the
-- exact loader serial cannot be reconstructed.
UPDATE license_devices
SET serial = CONCAT('legacy:', id, ':', device_hash),
    active = 0
WHERE serial IS NULL OR serial='';

ALTER TABLE license_devices
  MODIFY serial VARCHAR(255) NOT NULL;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=DATABASE() AND table_name='license_devices' AND index_name='uq_key_serial'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_devices ADD UNIQUE KEY uq_key_serial (license_key_id, serial)',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=DATABASE() AND table_name='license_devices' AND index_name='idx_device_active'
);
SET @sql := IF(
  @exists=0,
  'ALTER TABLE license_devices ADD INDEX idx_device_active (license_key_id, active)',
  'SELECT 1'
);
PREPARE td_stmt FROM @sql; EXECUTE td_stmt; DEALLOCATE PREPARE td_stmt;
