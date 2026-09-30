-- Organization-owned backup control plane. Values are client-side encrypted and opaque here.
CREATE TABLE IF NOT EXISTS backup_config (
  organization_id CHAR(36) NOT NULL PRIMARY KEY,
  provider ENUM('backblaze_b2', 'aws_s3', 'google_cloud') NOT NULL DEFAULT 'backblaze_b2',
  bucket VARCHAR(512) NULL, region VARCHAR(128) NULL, endpoint VARCHAR(2048) NULL,
  access_key_encrypted TEXT NULL, secret_key_encrypted TEXT NULL, restic_password_encrypted TEXT NULL,
  retention_daily SMALLINT UNSIGNED NOT NULL DEFAULT 7, retention_weekly SMALLINT UNSIGNED NOT NULL DEFAULT 4,
  retention_monthly SMALLINT UNSIGNED NOT NULL DEFAULT 12, retention_yearly SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  schedule_enabled BOOLEAN NOT NULL DEFAULT FALSE, schedule_hour TINYINT UNSIGNED NOT NULL DEFAULT 0,
  schedule_minute TINYINT UNSIGNED NOT NULL DEFAULT 0, schedule_timezone VARCHAR(128) NOT NULL DEFAULT 'UTC',
  designated_machine_id VARCHAR(255) NULL, designated_machine_name VARCHAR(255) NULL,
  designated_machine_platform VARCHAR(128) NULL, designated_machine_user_email VARCHAR(320) NULL,
  designated_machine_last_seen DATETIME(3) NULL, backup_requested_at DATETIME(3) NULL,
  backup_requested_by VARCHAR(320) NULL, backup_running_since DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_backup_config_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  INDEX idx_backup_designated_machine (organization_id, designated_machine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
