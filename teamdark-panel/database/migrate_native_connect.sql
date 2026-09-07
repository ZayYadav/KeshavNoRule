-- TeamDark native /connect compatibility migration.
-- Back up the database before running this file.
-- Designed for the existing TeamDark panel schema.

ALTER TABLE users
  MODIFY balance BIGINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE balance_ledger
  MODIFY amount BIGINT NOT NULL;

-- If the earlier lifecycle migration has not been applied yet, add its fields.
ALTER TABLE license_keys
  ADD COLUMN IF NOT EXISTS duration_seconds BIGINT UNSIGNED NOT NULL DEFAULT 86400 AFTER label,
  ADD COLUMN IF NOT EXISTS activated_at DATETIME NULL AFTER duration_seconds,
  ADD COLUMN IF NOT EXISTS last_used_at DATETIME NULL AFTER expires_at,
  ADD COLUMN IF NOT EXISTS max_devices INT UNSIGNED NOT NULL DEFAULT 10 AFTER last_used_at;

-- Native loader fields.
ALTER TABLE license_keys
  ADD COLUMN IF NOT EXISTS game VARCHAR(16) NOT NULL DEFAULT 'PUBG' AFTER label,
  ADD COLUMN IF NOT EXISTS unlimited_expiry TINYINT(1) NOT NULL DEFAULT 0 AFTER duration_seconds,
  ADD COLUMN IF NOT EXISTS unlimited_devices TINYINT(1) NOT NULL DEFAULT 0 AFTER max_devices,
  MODIFY status ENUM('unused','active','expired','disabled','revoked') NOT NULL DEFAULT 'unused';

-- Preserve remaining lifetime for old pre-first-use records.
UPDATE license_keys
SET duration_seconds = GREATEST(TIMESTAMPDIFF(SECOND, NOW(), expires_at), 3600)
WHERE expires_at IS NOT NULL
  AND activated_at IS NULL
  AND unlimited_expiry = 0
  AND expires_at > NOW();

-- Old keys that were generated but never activated become unused.
UPDATE license_keys
SET expires_at = NULL,
    status = 'unused'
WHERE activated_at IS NULL
  AND status = 'active'
  AND unlimited_expiry = 0;

-- Create the device table if it did not exist.
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
  CONSTRAINT fk_device_key FOREIGN KEY (license_key_id) REFERENCES license_keys(id) ON DELETE CASCADE,
  INDEX idx_device_last_seen(last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade an older license_devices table in place.
ALTER TABLE license_devices
  ADD COLUMN IF NOT EXISTS serial VARCHAR(255) NULL AFTER device_hash,
  ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NOT NULL DEFAULT '' AFTER last_seen_at,
  ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1 AFTER ip_address;

-- Old fingerprint-only rows cannot be mapped back to the loader serial.
-- Keep them for audit/history but do not let them consume active slots.
UPDATE license_devices
SET serial = CONCAT('legacy:', id, ':', device_hash),
    active = 0
WHERE serial IS NULL OR serial = '';

ALTER TABLE license_devices
  MODIFY serial VARCHAR(255) NOT NULL;

-- Add unique (key, serial) only if it does not already exist.
SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'license_devices'
    AND index_name = 'uq_key_serial'
);

SET @idx_sql := IF(
  @idx_exists = 0,
  'ALTER TABLE license_devices ADD UNIQUE KEY uq_key_serial (license_key_id, serial)',
  'SELECT 1'
);

PREPARE td_stmt FROM @idx_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;

SET @idx_active_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'license_devices'
    AND index_name = 'idx_device_active'
);

SET @idx_active_sql := IF(
  @idx_active_exists = 0,
  'ALTER TABLE license_devices ADD INDEX idx_device_active (license_key_id, active)',
  'SELECT 1'
);

PREPARE td_stmt2 FROM @idx_active_sql;
EXECUTE td_stmt2;
DEALLOCATE PREPARE td_stmt2;

UPDATE license_keys SET game='PUBG' WHERE game IS NULL OR game='';
