CREATE TABLE IF NOT EXISTS registration_requests (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  email VARCHAR(320) NOT NULL,
  display_name VARCHAR(200) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  reviewed_by CHAR(36) NULL,
  reviewed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_registration_request_status (organization_id, email, status),
  INDEX idx_registration_requests_org_status (organization_id, status, created_at),
  CONSTRAINT fk_registration_request_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_registration_request_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
