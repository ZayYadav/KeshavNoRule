-- TEAM DARK referral multi-registration support
-- Safe to run repeatedly on MySQL/MariaDB.

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

-- Preserve historical behavior: all legacy referrals remain one-use.
INSERT IGNORE INTO referral_invite_limits(referral_id,max_registrations,used_count,updated_by)
SELECT id,1,CASE WHEN status='used' THEN 1 ELSE 0 END,NULL
FROM referral_invites;

-- Preserve the latest historical use where the old schema recorded one.
INSERT IGNORE INTO referral_invite_uses(referral_id,user_id,used_at)
SELECT id,used_by,COALESCE(used_at,created_at)
FROM referral_invites
WHERE used_by IS NOT NULL;
