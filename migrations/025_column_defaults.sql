ALTER TABLE organization_settings
  ADD COLUMN IF NOT EXISTS column_defaults JSON NULL AFTER auth_provider_settings;

CREATE TABLE IF NOT EXISTS user_settings (
  user_id CHAR(36) PRIMARY KEY,
  column_defaults JSON NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
