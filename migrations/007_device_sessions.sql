-- Per-device presence is distinct from authentication sessions. It allows a
-- user to sign out another workstation without revoking every browser token.

CREATE TABLE IF NOT EXISTS device_sessions (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  organization_id CHAR(36) NOT NULL,
  machine_id VARCHAR(512) NOT NULL,
  machine_name VARCHAR(512) NULL,
  os_version VARCHAR(512) NULL,
  app_version VARCHAR(128) NULL,
  platform VARCHAR(128) NULL,
  last_active DATETIME(3) NULL,
  last_seen DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_device_session_user_machine (user_id, machine_id),
  KEY idx_device_sessions_organization_online (organization_id, is_active, last_seen),
  CONSTRAINT fk_device_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_device_sessions_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
