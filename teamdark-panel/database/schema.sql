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

DROP PROCEDURE IF EXISTS td_add_column;
CREATE TABLE IF NOT EXISTS announcements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  body TEXT NOT NULL,
  kind ENUM('info','success','warning','critical') NOT NULL DEFAULT 'info',
  audience ENUM('all','owner','admin','reseller','user') NOT NULL DEFAULT 'all',
  state ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  dismissible TINYINT(1) NOT NULL DEFAULT 1,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_announcements_visibility(state,audience,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_dismissals (
  announcement_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  dismissed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (announcement_id,user_id,version),
  CONSTRAINT fk_announcement_dismissal FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
  CONSTRAINT fk_announcement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS td_add_index;

DELIMITER $$

CREATE PROCEDURE td_add_column(
  IN p_table VARCHAR(64),
  IN p_column VARCHAR(64),
  IN p_ddl TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME=p_table
      AND COLUMN_NAME=p_column
  ) THEN
    SET @td_sql=p_ddl;
    PREPARE td_stmt FROM @td_sql;
    EXECUTE td_stmt;
    DEALLOCATE PREPARE td_stmt;
  END IF;
END$$

CREATE PROCEDURE td_add_index(
  IN p_table VARCHAR(64),
  IN p_index VARCHAR(64),
  IN p_ddl TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME=p_table
      AND INDEX_NAME=p_index
  ) THEN
    SET @td_sql=p_ddl;
    PREPARE td_stmt FROM @td_sql;
    EXECUTE td_stmt;
    DEALLOCATE PREPARE td_stmt;
  END IF;
END$$

DELIMITER ;

CALL td_add_column(
  'users','name',
  'ALTER TABLE users ADD COLUMN name VARCHAR(100) NOT NULL DEFAULT '''' AFTER id'
);
CALL td_add_column(
  'users','telegram_chat_id',
  'ALTER TABLE users ADD COLUMN telegram_chat_id BIGINT NULL AFTER created_by'
);

ALTER TABLE users
  MODIFY balance BIGINT UNSIGNED NOT NULL DEFAULT 0;

UPDATE users SET name=username WHERE name='';

CALL td_add_index(
  'users','uq_users_telegram_chat',
  'ALTER TABLE users ADD UNIQUE INDEX uq_users_telegram_chat(telegram_chat_id)'
);

CREATE TABLE IF NOT EXISTS referral_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  created_by BIGINT UNSIGNED NOT NULL,
  role ENUM('admin','reseller','user') NOT NULL DEFAULT 'user',
  status ENUM('pending','used','revoked') NOT NULL DEFAULT 'pending',
  used_by BIGINT UNSIGNED NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invite_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_invite_used_by FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_invite_creator(created_by),
  INDEX idx_invite_status(status),
  INDEX idx_invite_role(role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CALL td_add_column(
  'license_keys','game',
  'ALTER TABLE license_keys ADD COLUMN game VARCHAR(16) NOT NULL DEFAULT ''PUBG'' AFTER label'
);
CALL td_add_column(
  'license_keys','duration_seconds',
  'ALTER TABLE license_keys ADD COLUMN duration_seconds BIGINT UNSIGNED NOT NULL DEFAULT 86400 AFTER game'
);
CALL td_add_column(
  'license_keys','unlimited_expiry',
  'ALTER TABLE license_keys ADD COLUMN unlimited_expiry TINYINT(1) NOT NULL DEFAULT 0 AFTER duration_seconds'
);
CALL td_add_column(
  'license_keys','activated_at',
  'ALTER TABLE license_keys ADD COLUMN activated_at DATETIME NULL AFTER unlimited_expiry'
);
CALL td_add_column(
  'license_keys','expires_at',
  'ALTER TABLE license_keys ADD COLUMN expires_at DATETIME NULL AFTER activated_at'
);
CALL td_add_column(
  'license_keys','last_used_at',
  'ALTER TABLE license_keys ADD COLUMN last_used_at DATETIME NULL AFTER expires_at'
);
CALL td_add_column(
  'license_keys','max_devices',
  'ALTER TABLE license_keys ADD COLUMN max_devices INT UNSIGNED NOT NULL DEFAULT 10 AFTER last_used_at'
);
CALL td_add_column(
  'license_keys','unlimited_devices',
  'ALTER TABLE license_keys ADD COLUMN unlimited_devices TINYINT(1) NOT NULL DEFAULT 0 AFTER max_devices'
);
CALL td_add_column(
  'license_keys','status',
  'ALTER TABLE license_keys ADD COLUMN status ENUM(''unused'',''active'',''expired'',''disabled'',''revoked'') NOT NULL DEFAULT ''unused'''
);
CALL td_add_column(
  'license_keys','key_source',
  'ALTER TABLE license_keys ADD COLUMN key_source VARCHAR(24) NOT NULL DEFAULT ''panel'' AFTER status'
);
CALL td_add_column(
  'license_keys','telegram_user_id',
  'ALTER TABLE license_keys ADD COLUMN telegram_user_id BIGINT UNSIGNED NULL AFTER key_source'
);

ALTER TABLE license_keys
  MODIFY status ENUM('unused','active','expired','disabled','revoked') NOT NULL DEFAULT 'unused';

UPDATE license_keys
SET game='PUBG'
WHERE game='';

UPDATE license_keys
SET key_source='panel'
WHERE key_source='' OR key_source IS NULL;

CALL td_add_index(
  'license_keys','idx_keys_expiry',
  'ALTER TABLE license_keys ADD INDEX idx_keys_expiry(expires_at)'
);
CALL td_add_index(
  'license_keys','idx_keys_game',
  'ALTER TABLE license_keys ADD INDEX idx_keys_game(game)'
);
CALL td_add_index(
  'license_keys','idx_keys_tg',
  'ALTER TABLE license_keys ADD INDEX idx_keys_tg(telegram_user_id)'
);
CALL td_add_index(
  'license_keys','idx_keys_source',
  'ALTER TABLE license_keys ADD INDEX idx_keys_source(key_source)'
);

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

CALL td_add_column(
  'license_devices','serial',
  'ALTER TABLE license_devices ADD COLUMN serial VARCHAR(255) NOT NULL DEFAULT '''' AFTER device_hash'
);
CALL td_add_column(
  'license_devices','ip_address',
  'ALTER TABLE license_devices ADD COLUMN ip_address VARCHAR(45) NOT NULL DEFAULT '''' AFTER last_seen_at'
);
CALL td_add_column(
  'license_devices','active',
  'ALTER TABLE license_devices ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER ip_address'
);

UPDATE license_devices
SET serial=CONCAT('legacy-',id,'-',LEFT(device_hash,16))
WHERE serial='';

CALL td_add_index(
  'license_devices','uq_key_serial',
  'ALTER TABLE license_devices ADD UNIQUE INDEX uq_key_serial(license_key_id,serial)'
);
CALL td_add_index(
  'license_devices','idx_device_active',
  'ALTER TABLE license_devices ADD INDEX idx_device_active(license_key_id,active)'
);

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

CREATE TABLE IF NOT EXISTS telegram_update_ids (
  update_id BIGINT PRIMARY KEY,
  received_at DATETIME NOT NULL,
  INDEX idx_tg_update_received(received_at)
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

DROP PROCEDURE IF EXISTS td_add_column;
DROP PROCEDURE IF EXISTS td_add_index;
