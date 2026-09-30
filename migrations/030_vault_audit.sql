-- Server-side, tenant-scoped audit receipts and metadata written by the MDB client.
CREATE TABLE IF NOT EXISTS vault_audit_runs (
  id CHAR(36) NOT NULL PRIMARY KEY, organization_id CHAR(36) NOT NULL, vault_id CHAR(36) NOT NULL,
  requested_by CHAR(36) NOT NULL, page_count INT UNSIGNED NOT NULL DEFAULT 0,
  finding_count INT UNSIGNED NOT NULL DEFAULT 0, summary JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_vault_audit_runs_scope (organization_id, vault_id, created_at),
  CONSTRAINT fk_vault_audit_run_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_vault_audit_run_vault FOREIGN KEY (vault_id) REFERENCES vaults(id) ON DELETE CASCADE,
  CONSTRAINT fk_vault_audit_run_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vault_audit_file_metadata (
  file_id CHAR(36) NOT NULL PRIMARY KEY, organization_id CHAR(36) NOT NULL, vault_id CHAR(36) NOT NULL,
  metadata JSON NOT NULL, updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_vault_audit_meta_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  CONSTRAINT fk_vault_audit_meta_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_vault_audit_meta_vault FOREIGN KEY (vault_id) REFERENCES vaults(id) ON DELETE CASCADE,
  INDEX idx_vault_audit_meta_scope (organization_id, vault_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
