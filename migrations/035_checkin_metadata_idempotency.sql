CREATE TABLE IF NOT EXISTS file_checkin_requests (
  request_id CHAR(36) NOT NULL,
  organization_id CHAR(36) NOT NULL,
  file_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  request_fingerprint CHAR(64) NOT NULL,
  response JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (organization_id, request_id),
  KEY idx_file_checkin_requests_file (file_id, created_at),
  CONSTRAINT fk_file_checkin_request_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_checkin_request_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_checkin_request_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
