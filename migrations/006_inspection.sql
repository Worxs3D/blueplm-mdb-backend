-- BluePLM inspection table: live drawing characteristics, immutable revision
-- snapshots, and organization-defined method names.

CREATE TABLE IF NOT EXISTS inspection_characteristics (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  file_id CHAR(36) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  balloon_number TEXT NULL,
  char_id TEXT NULL,
  zone TEXT NULL,
  char_type TEXT NULL,
  sub_type TEXT NULL,
  nominal_value TEXT NULL,
  unit VARCHAR(64) NULL,
  plus_tolerance TEXT NULL,
  minus_tolerance TEXT NULL,
  upper_limit TEXT NULL,
  lower_limit TEXT NULL,
  classification VARCHAR(128) NULL,
  inspection_method VARCHAR(255) NULL,
  operation VARCHAR(255) NULL,
  aql VARCHAR(128) NULL,
  sample_size INT NULL,
  supplier_inspection_rate DECIMAL(7,3) NULL,
  internal_inspection_rate DECIMAL(7,3) NULL,
  reference TEXT NULL,
  comments TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by CHAR(36) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  updated_by CHAR(36) NULL,
  KEY idx_inspection_characteristics_file (file_id, sort_order),
  KEY idx_inspection_characteristics_organization (organization_id),
  CONSTRAINT fk_inspection_characteristic_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inspection_characteristic_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  CONSTRAINT fk_inspection_characteristic_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_inspection_characteristic_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inspection_characteristic_versions (
  id CHAR(36) NOT NULL PRIMARY KEY,
  file_revision_id CHAR(36) NOT NULL,
  organization_id CHAR(36) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  balloon_number TEXT NULL, char_id TEXT NULL, zone TEXT NULL,
  char_type TEXT NULL, sub_type TEXT NULL, nominal_value TEXT NULL, unit VARCHAR(64) NULL,
  plus_tolerance TEXT NULL, minus_tolerance TEXT NULL, upper_limit TEXT NULL, lower_limit TEXT NULL,
  classification VARCHAR(128) NULL, inspection_method VARCHAR(255) NULL, operation VARCHAR(255) NULL,
  aql VARCHAR(128) NULL, sample_size INT NULL, supplier_inspection_rate DECIMAL(7,3) NULL,
  internal_inspection_rate DECIMAL(7,3) NULL, reference TEXT NULL, comments TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_inspection_characteristic_versions_revision (file_revision_id, sort_order),
  KEY idx_inspection_characteristic_versions_organization (organization_id),
  CONSTRAINT fk_inspection_characteristic_version_revision FOREIGN KEY (file_revision_id) REFERENCES file_revisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_inspection_characteristic_version_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inspection_methods (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  name VARCHAR(255) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by CHAR(36) NULL,
  UNIQUE KEY uq_inspection_method_name (organization_id, name),
  KEY idx_inspection_methods_organization (organization_id),
  CONSTRAINT fk_inspection_method_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inspection_method_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
