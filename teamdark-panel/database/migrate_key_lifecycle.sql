-- Run ONCE on an existing TeamDark panel database before uploading the upgraded PHP files.

ALTER TABLE users
  MODIFY balance BIGINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE balance_ledger
  MODIFY amount BIGINT NOT NULL;

ALTER TABLE license_keys
  MODIFY status ENUM('unused','active','disabled','expired') NOT NULL DEFAULT 'unused',
  ADD duration_seconds BIGINT UNSIGNED NOT NULL DEFAULT 86400 AFTER label,
  ADD activated_at DATETIME NULL AFTER duration_seconds,
  ADD last_used_at DATETIME NULL AFTER expires_at,
  ADD max_devices INT UNSIGNED NOT NULL DEFAULT 10 AFTER last_used_at,
  ADD INDEX idx_keys_expiry(expires_at);

-- Preserve roughly the remaining life of old pre-upgrade keys, then switch them to first-use timing.
UPDATE license_keys
SET duration_seconds = GREATEST(TIMESTAMPDIFF(SECOND, NOW(), expires_at), 3600)
WHERE expires_at IS NOT NULL
  AND activated_at IS NULL
  AND expires_at > NOW();

UPDATE license_keys
SET expires_at = NULL,
    status = 'unused'
WHERE activated_at IS NULL
  AND status = 'active';

CREATE TABLE IF NOT EXISTS license_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_key_id BIGINT UNSIGNED NOT NULL,
  device_hash CHAR(64) NOT NULL,
  device_label VARCHAR(120) NOT NULL DEFAULT '',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  CONSTRAINT fk_device_key FOREIGN KEY (license_key_id) REFERENCES license_keys(id) ON DELETE CASCADE,
  UNIQUE KEY uq_key_device(license_key_id, device_hash),
  INDEX idx_device_last_seen(last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
