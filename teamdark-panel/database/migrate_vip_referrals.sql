-- Run ONCE on an existing TeamDark panel database before deploying the VIP referral build.

ALTER TABLE users
  ADD name VARCHAR(100) NOT NULL DEFAULT '' AFTER id;

UPDATE users
SET name = username
WHERE name = '';

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
