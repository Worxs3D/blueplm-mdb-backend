CREATE TABLE IF NOT EXISTS file_watchers (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  file_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  notify_on_checkin BOOLEAN NOT NULL DEFAULT TRUE,
  notify_on_checkout BOOLEAN NOT NULL DEFAULT FALSE,
  notify_on_state_change BOOLEAN NOT NULL DEFAULT TRUE,
  notify_on_review BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_file_watcher (file_id, user_id),
  KEY idx_file_watchers_user (user_id, organization_id),
  CONSTRAINT fk_file_watchers_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_watchers_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_watchers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
