-- Cryptographic possession records are deliberately separate from backup_config:
-- a backup configuration is organization-owned, while a device key is also bound
-- to one authenticated user and has an independently revocable lifecycle.
CREATE TABLE IF NOT EXISTS backup_device_credentials (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  designated_user_id CHAR(36) NOT NULL,
  device_id VARCHAR(255) NOT NULL,
  public_key BINARY(32) NOT NULL,
  key_version INT UNSIGNED NOT NULL,
  status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
  rotated_from_id CHAR(36) NULL,
  revoked_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_backup_device_active_version (organization_id, device_id, key_version),
  INDEX idx_backup_device_live (organization_id, device_id, status),
  INDEX idx_backup_device_principal (organization_id, designated_user_id, status),
  CONSTRAINT fk_backup_device_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_backup_device_user FOREIGN KEY (designated_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_backup_device_rotated_from FOREIGN KEY (rotated_from_id) REFERENCES backup_device_credentials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A challenge may be redeemed once only. The row lock in the runtime route
-- serializes redemption, which prevents a captured assertion being replayed.
CREATE TABLE IF NOT EXISTS backup_device_challenges (
  id CHAR(36) NOT NULL PRIMARY KEY,
  credential_id CHAR(36) NOT NULL,
  organization_id CHAR(36) NOT NULL,
  designated_user_id CHAR(36) NOT NULL,
  device_id VARCHAR(255) NOT NULL,
  key_version INT UNSIGNED NOT NULL,
  endpoint VARCHAR(96) NOT NULL,
  nonce BINARY(32) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  consumed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_backup_challenge_expiry (expires_at),
  INDEX idx_backup_challenge_credential (credential_id, consumed_at),
  CONSTRAINT fk_backup_challenge_credential FOREIGN KEY (credential_id) REFERENCES backup_device_credentials(id) ON DELETE CASCADE,
  CONSTRAINT fk_backup_challenge_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_backup_challenge_user FOREIGN KEY (designated_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
