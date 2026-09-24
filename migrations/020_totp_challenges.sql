CREATE TABLE IF NOT EXISTS auth_totp_challenges (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  organization_id CHAR(36) NOT NULL,
  attempts_remaining TINYINT UNSIGNED NOT NULL DEFAULT 5,
  expires_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_auth_totp_challenges_expiry (expires_at),
  CONSTRAINT fk_auth_totp_challenge_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_auth_totp_challenge_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS totp_enrollments (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL UNIQUE,
  secret_ciphertext TEXT NOT NULL,
  attempts_remaining TINYINT UNSIGNED NOT NULL DEFAULT 5,
  expires_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_totp_enrollments_expiry (expires_at),
  CONSTRAINT fk_totp_enrollment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
