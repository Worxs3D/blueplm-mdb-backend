ALTER TABLE organization_settings
  ADD COLUMN IF NOT EXISTS item_definition JSON NULL;

CREATE TABLE IF NOT EXISTS item_designations (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_item_designation_name (organization_id, name),
  KEY idx_item_designations_org_order (organization_id, sort_order),
  CONSTRAINT fk_item_designations_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_designation_assignments (
  organization_id CHAR(36) NOT NULL,
  vault_id CHAR(36) NOT NULL,
  part_number VARCHAR(512) NOT NULL,
  designation_id CHAR(36) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (organization_id, vault_id, part_number),
  KEY idx_item_designation_assignments_vault (vault_id),
  CONSTRAINT fk_item_designation_assignments_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_designation_assignments_vault FOREIGN KEY (vault_id) REFERENCES vaults(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_designation_assignments_designation FOREIGN KEY (designation_id) REFERENCES item_designations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
