SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS panel_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  settings_json JSON NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO panel_settings(id,settings_json) VALUES(1,JSON_OBJECT());

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL DEFAULT '',
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','admin','reseller','user') NOT NULL DEFAULT 'user',
  balance BIGINT UNSIGNED NOT NULL DEFAULT 0,
  referral_code VARCHAR(32) NOT NULL UNIQUE,
  referred_by BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  telegram_chat_id BIGINT NULL,
  telegram_2fa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  telegram_2fa_enabled_at DATETIME NULL,
  auth_version INT UNSIGNED NOT NULL DEFAULT 1,
  login_not_before DATETIME NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_ref FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_user_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_users_telegram_chat(telegram_chat_id),
  INDEX idx_users_role(role),
  INDEX idx_users_ref(referred_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shared-host-safe idempotent migrations below use prepared statements.
-- No stored-routine creation privilege is required.


SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='name'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE users ADD COLUMN name VARCHAR(100) NOT NULL DEFAULT '''' AFTER id', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='telegram_chat_id'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE users ADD COLUMN telegram_chat_id BIGINT NULL AFTER created_by', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='telegram_2fa_enabled'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE users ADD COLUMN telegram_2fa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER telegram_chat_id', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='telegram_2fa_enabled_at'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE users ADD COLUMN telegram_2fa_enabled_at DATETIME NULL AFTER telegram_2fa_enabled', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='auth_version'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER telegram_2fa_enabled_at', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='login_not_before'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE users ADD COLUMN login_not_before DATETIME NULL AFTER auth_version', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;

ALTER TABLE users
  MODIFY balance BIGINT UNSIGNED NOT NULL DEFAULT 0;

UPDATE users SET name=username WHERE name='';

SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='uq_users_telegram_chat'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE users ADD UNIQUE INDEX uq_users_telegram_chat(telegram_chat_id)', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;

CREATE TABLE IF NOT EXISTS app_registry (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_registry(id,name,endpoint_token,notes,status,is_official,created_by)
VALUES(1,'Official','official','Permanent backward-compatible /connect application API.','active',1,NULL)
ON DUPLICATE KEY UPDATE
  name=IF(is_official=1,name,VALUES(name)),
  status=IF(is_official=1,'active',status),
  is_official=IF(id=1,1,is_official);

SET @td_user_app_access_existed := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_app_access'
);

CREATE TABLE IF NOT EXISTS user_app_access (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing accounts keep the historical Official application automatically.
INSERT IGNORE INTO user_app_access(user_id,app_id,granted_by,source)
SELECT id,1,NULL,'migration' FROM users
WHERE @td_user_app_access_existed = 0;

CREATE TABLE IF NOT EXISTS user_uploads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  storage_name VARCHAR(96) NOT NULL UNIQUE,
  extension VARCHAR(8) NOT NULL,
  mime_type VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_upload_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_upload_user(user_id),
  INDEX idx_upload_updated(updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  created_by BIGINT UNSIGNED NOT NULL,
  role ENUM('admin','reseller','user') NOT NULL DEFAULT 'user',
  grant_balance BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','used','revoked') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NULL,
  used_by BIGINT UNSIGNED NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invite_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_invite_used_by FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_invite_creator(created_by),
  INDEX idx_invite_status(status),
  INDEX idx_invite_role(role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='referral_invites' AND COLUMN_NAME='expires_at'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE referral_invites ADD COLUMN expires_at DATETIME NULL AFTER status', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;

SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='referral_invites' AND COLUMN_NAME='grant_balance'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE referral_invites ADD COLUMN grant_balance BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER role', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;

UPDATE referral_invites
SET expires_at=DATE_ADD(created_at,INTERVAL 7 DAY)
WHERE expires_at IS NULL AND status='pending';

SET @td_referral_app_access_existed := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='referral_app_access'
);

CREATE TABLE IF NOT EXISTS referral_app_access (
  referral_id BIGINT UNSIGNED NOT NULL,
  app_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(referral_id,app_id),
  CONSTRAINT fk_ref_app_referral FOREIGN KEY (referral_id) REFERENCES referral_invites(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_app_app FOREIGN KEY (app_id) REFERENCES app_registry(id) ON DELETE CASCADE,
  INDEX idx_ref_app_app(app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing pending/used referrals preserve old behavior by granting Official.
INSERT IGNORE INTO referral_app_access(referral_id,app_id)
SELECT id,1 FROM referral_invites
WHERE @td_referral_app_access_existed = 0;

CREATE TABLE IF NOT EXISTS referral_invite_limits (
  referral_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  max_registrations INT UNSIGNED NOT NULL DEFAULT 1,
  used_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ref_limit_referral FOREIGN KEY (referral_id) REFERENCES referral_invites(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_limit_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ref_limit_max(max_registrations),
  INDEX idx_ref_limit_used(used_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_invite_uses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  referral_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ref_use_referral FOREIGN KEY (referral_id) REFERENCES referral_invites(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_use_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_ref_use_user(referral_id,user_id),
  INDEX idx_ref_use_referral(referral_id),
  INDEX idx_ref_use_user(user_id),
  INDEX idx_ref_use_time(used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legacy referrals keep one-use behavior until Owner explicitly raises a limit.
INSERT IGNORE INTO referral_invite_limits(referral_id,max_registrations,used_count,updated_by)
SELECT id,1,CASE WHEN status='used' THEN 1 ELSE 0 END,NULL
FROM referral_invites;

INSERT IGNORE INTO referral_invite_uses(referral_id,user_id,used_at)
SELECT id,used_by,COALESCE(used_at,created_at)
FROM referral_invites
WHERE used_by IS NOT NULL;

CREATE TABLE IF NOT EXISTS telegram_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT NOT NULL UNIQUE,
  first_name VARCHAR(100) NOT NULL DEFAULT '',
  last_name VARCHAR(100) NOT NULL DEFAULT '',
  username VARCHAR(64) NOT NULL DEFAULT '',
  language_code VARCHAR(16) NOT NULL DEFAULT '',
  linked_user_id BIGINT UNSIGNED NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  guest_last_key_at DATETIME NULL,
  guest_key_count INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_tg_linked_user FOREIGN KEY (linked_user_id) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_tg_linked_user(linked_user_id),
  INDEX idx_tg_last_seen(last_seen_at),
  INDEX idx_tg_guest_last_key(guest_last_key_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  key_hash CHAR(64) NOT NULL UNIQUE,
  key_hash_version TINYINT UNSIGNED NOT NULL DEFAULT 2,
  key_cipher TEXT NOT NULL,
  key_iv VARCHAR(64) NOT NULL,
  key_tag VARCHAR(64) NOT NULL,
  label VARCHAR(100) NOT NULL DEFAULT '',
  game VARCHAR(16) NOT NULL DEFAULT 'PUBG',
  duration_seconds BIGINT UNSIGNED NOT NULL DEFAULT 86400,
  unlimited_expiry TINYINT(1) NOT NULL DEFAULT 0,
  activated_at DATETIME NULL,
  expires_at DATETIME NULL,
  last_used_at DATETIME NULL,
  max_devices INT UNSIGNED NOT NULL DEFAULT 10,
  unlimited_devices TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('unused','active','expired','disabled','revoked') NOT NULL DEFAULT 'unused',
  key_source VARCHAR(24) NOT NULL DEFAULT 'panel',
  telegram_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_key_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_key_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_key_tg FOREIGN KEY (telegram_user_id) REFERENCES telegram_users(id) ON DELETE SET NULL,
  INDEX idx_keys_owner(owner_user_id),
  INDEX idx_keys_creator(created_by),
  INDEX idx_keys_status(status),
  INDEX idx_keys_expiry(expires_at),
  INDEX idx_keys_game(game),
  INDEX idx_keys_tg(telegram_user_id),
  INDEX idx_keys_source(key_source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='key_hash_version'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN key_hash_version TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER key_hash', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='game'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN game VARCHAR(16) NOT NULL DEFAULT ''PUBG'' AFTER label', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='duration_seconds'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN duration_seconds BIGINT UNSIGNED NOT NULL DEFAULT 86400 AFTER game', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='unlimited_expiry'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN unlimited_expiry TINYINT(1) NOT NULL DEFAULT 0 AFTER duration_seconds', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='activated_at'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN activated_at DATETIME NULL AFTER unlimited_expiry', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='expires_at'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN expires_at DATETIME NULL AFTER activated_at', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='last_used_at'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN last_used_at DATETIME NULL AFTER expires_at', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='max_devices'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN max_devices INT UNSIGNED NOT NULL DEFAULT 10 AFTER last_used_at', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='unlimited_devices'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN unlimited_devices TINYINT(1) NOT NULL DEFAULT 0 AFTER max_devices', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='status'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN status ENUM(''unused'',''active'',''expired'',''disabled'',''revoked'') NOT NULL DEFAULT ''unused''', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='key_source'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN key_source VARCHAR(24) NOT NULL DEFAULT ''panel'' AFTER status', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='telegram_user_id'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN telegram_user_id BIGINT UNSIGNED NULL AFTER key_source', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND COLUMN_NAME='app_id'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD COLUMN app_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER telegram_user_id', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;

UPDATE license_keys SET app_id=1 WHERE app_id IS NULL OR app_id=0;


ALTER TABLE license_keys
  MODIFY status ENUM('unused','active','expired','disabled','revoked') NOT NULL DEFAULT 'unused';

UPDATE license_keys
SET game='PUBG'
WHERE game='';

UPDATE license_keys
SET key_source='panel'
WHERE key_source='' OR key_source IS NULL;

SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND INDEX_NAME='idx_keys_expiry'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD INDEX idx_keys_expiry(expires_at)', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND INDEX_NAME='idx_keys_game'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD INDEX idx_keys_game(game)', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND INDEX_NAME='idx_keys_tg'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD INDEX idx_keys_tg(telegram_user_id)', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND INDEX_NAME='idx_keys_source'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD INDEX idx_keys_source(key_source)', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_keys' AND INDEX_NAME='idx_keys_app'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_keys ADD INDEX idx_keys_app(app_id)', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;


CREATE TABLE IF NOT EXISTS license_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_key_id BIGINT UNSIGNED NOT NULL,
  device_hash CHAR(64) NOT NULL,
  serial VARCHAR(255) NOT NULL,
  device_label VARCHAR(120) NOT NULL DEFAULT '',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_device_key FOREIGN KEY (license_key_id) REFERENCES license_keys(id) ON DELETE CASCADE,
  UNIQUE KEY uq_key_serial(license_key_id, serial),
  INDEX idx_device_last_seen(last_seen_at),
  INDEX idx_device_active(license_key_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_devices' AND COLUMN_NAME='serial'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_devices ADD COLUMN serial VARCHAR(255) NOT NULL DEFAULT '''' AFTER device_hash', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_devices' AND COLUMN_NAME='ip_address'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_devices ADD COLUMN ip_address VARCHAR(45) NOT NULL DEFAULT '''' AFTER last_seen_at', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_devices' AND COLUMN_NAME='active'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_devices ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER ip_address', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;

UPDATE license_devices
SET serial=CONCAT('legacy-',id,'-',LEFT(device_hash,16))
WHERE serial='';

-- Remove historical raw device identifiers. Future Loader requests use device_hash.
UPDATE license_devices
SET serial=CONCAT('h:',device_hash)
WHERE serial NOT LIKE 'h:%';

UPDATE license_devices
SET ip_address=''
WHERE ip_address<>'' AND ip_address NOT LIKE 'h:%';

SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_devices' AND INDEX_NAME='uq_key_serial'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_devices ADD UNIQUE INDEX uq_key_serial(license_key_id,serial)', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_devices' AND INDEX_NAME='uq_key_device_hash'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_devices ADD UNIQUE INDEX uq_key_device_hash(license_key_id,device_hash)', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;
SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_devices' AND INDEX_NAME='idx_device_active'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE license_devices ADD INDEX idx_device_active(license_key_id,active)', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;

CREATE TABLE IF NOT EXISTS balance_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  amount BIGINT NOT NULL,
  reason VARCHAR(160) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_balance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_balance_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_balance_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE balance_ledger
  MODIFY amount BIGINT NOT NULL;

CREATE TABLE IF NOT EXISTS api_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_token_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_link_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  chat_id BIGINT NOT NULL,
  code_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tg_link_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_tg_link_user(user_id),
  INDEX idx_tg_link_chat(chat_id),
  INDEX idx_tg_link_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_2fa_activation_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_2fa_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_2fa_activation_user(user_id),
  INDEX idx_2fa_activation_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_2fa_challenges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  continuation_hash CHAR(64) NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_ip VARCHAR(45) NOT NULL DEFAULT '',
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_login_2fa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_login_2fa_user(user_id),
  INDEX idx_login_2fa_expiry(expires_at),
  INDEX idx_login_2fa_used(used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @td_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='login_2fa_challenges' AND COLUMN_NAME='continuation_hash'
);
SET @td_sql := IF(@td_exists=0, 'ALTER TABLE login_2fa_challenges ADD COLUMN continuation_hash CHAR(64) NULL AFTER code_hash', 'SELECT 1');
PREPARE td_stmt FROM @td_sql;
EXECUTE td_stmt;
DEALLOCATE PREPARE td_stmt;

CREATE TABLE IF NOT EXISTS telegram_update_ids (
  update_id BIGINT PRIMARY KEY,
  received_at DATETIME NOT NULL,
  INDEX idx_tg_update_received(received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_broadcasts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_by BIGINT UNSIGNED NOT NULL,
  message VARCHAR(1000) NOT NULL,
  panel_enabled TINYINT(1) NOT NULL DEFAULT 0,
  target_linked TINYINT(1) NOT NULL DEFAULT 0,
  target_guests TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('queued','sending','completed','partial','cancelled') NOT NULL DEFAULT 'queued',
  total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
  sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_announcement_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_announcement_status(status),
  INDEX idx_announcement_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_recipients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  broadcast_id BIGINT UNSIGNED NOT NULL,
  telegram_user_id BIGINT UNSIGNED NULL,
  chat_id BIGINT NOT NULL,
  audience ENUM('linked','guest') NOT NULL,
  status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(160) NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_announcement_recipient_broadcast FOREIGN KEY (broadcast_id) REFERENCES announcement_broadcasts(id) ON DELETE CASCADE,
  CONSTRAINT fk_announcement_recipient_tg FOREIGN KEY (telegram_user_id) REFERENCES telegram_users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_announcement_chat(broadcast_id,chat_id),
  INDEX idx_announcement_recipient_status(broadcast_id,status),
  INDEX idx_announcement_recipient_tg(telegram_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  meta_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_user(user_id),
  INDEX idx_audit_action(action),
  INDEX idx_audit_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_hash CHAR(64) PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 1,
  touched_at DATETIME NOT NULL,
  INDEX idx_rate_touched(touched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE license_keys
SET duration_seconds=GREATEST(TIMESTAMPDIFF(SECOND,NOW(),expires_at),3600)
WHERE expires_at IS NOT NULL
  AND activated_at IS NULL
  AND expires_at>NOW()
  AND duration_seconds=86400;

UPDATE license_keys
SET expires_at=NULL,status='unused'
WHERE activated_at IS NULL
  AND status='active';

