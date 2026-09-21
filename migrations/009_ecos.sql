CREATE TABLE IF NOT EXISTS ecos (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  eco_number VARCHAR(128) NOT NULL,
  title VARCHAR(512) NOT NULL,
  status VARCHAR(64) NOT NULL DEFAULT 'open',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_eco_number (organization_id, eco_number),
  KEY idx_ecos_active (organization_id, status, created_at),
  CONSTRAINT fk_ecos_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_ecos (
  id CHAR(36) NOT NULL PRIMARY KEY,
  file_id CHAR(36) NOT NULL,
  eco_id CHAR(36) NOT NULL,
  created_by CHAR(36) NULL,
  notes TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_file_eco (file_id, eco_id),
  KEY idx_file_ecos_eco (eco_id),
  CONSTRAINT fk_file_ecos_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_ecos_eco FOREIGN KEY (eco_id) REFERENCES ecos(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_ecos_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
