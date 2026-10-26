CREATE TABLE IF NOT EXISTS vault_lifecycle_operations (
  organization_id CHAR(36) NOT NULL,
  operation_id CHAR(36) NOT NULL,
  requested_by CHAR(36) NOT NULL,
  operation_kind ENUM('clear', 'delete') NOT NULL,
  vault_id CHAR(36) NOT NULL,
  request_fingerprint CHAR(64) NOT NULL,
  result_json JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (organization_id, operation_id),
  KEY idx_vault_lifecycle_operations_vault (organization_id, vault_id, created_at),
  CONSTRAINT fk_vault_lifecycle_operations_org
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
