-- Administrative multi-factor authentication and one-time installation state.
-- TOTP secrets are encrypted by the PHP runtime before they reach this table.

CREATE TABLE IF NOT EXISTS admin_totp_credentials (
  user_id CHAR(36) NOT NULL PRIMARY KEY,
  secret_ciphertext TEXT NOT NULL,
  enabled_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_admin_totp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A single durable row makes the installation state explicit. It prevents the
-- browser bootstrap flow from being reopened after the first owner is created.
CREATE TABLE IF NOT EXISTS installation_state (
  singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  bootstrapped_at DATETIME(3) NULL,
  bootstrapped_by CHAR(36) NULL,
  CONSTRAINT chk_installation_state_singleton CHECK (singleton_id = 1),
  CONSTRAINT fk_installation_state_owner FOREIGN KEY (bootstrapped_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
