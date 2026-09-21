CREATE TABLE IF NOT EXISTS item_images (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  vault_id CHAR(36) NOT NULL,
  part_number VARCHAR(256) NOT NULL,
  image_type ENUM('icon', 'image') NOT NULL,
  icon_name VARCHAR(128) NULL,
  icon_color VARCHAR(64) NULL,
  storage_relative_path VARCHAR(2048) NULL,
  created_by CHAR(36) NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_item_images_org_part (organization_id, part_number),
  INDEX idx_item_images_vault (vault_id),
  CONSTRAINT fk_item_image_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_image_vault FOREIGN KEY (vault_id) REFERENCES vaults(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_image_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_share_links (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  file_id CHAR(36) NOT NULL,
  token CHAR(32) NOT NULL,
  created_by CHAR(36) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  revoked_at DATETIME(3) NULL,
  revoked_by CHAR(36) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_file_share_token (token),
  INDEX idx_file_share_file (file_id, expires_at),
  CONSTRAINT fk_file_share_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_share_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_share_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_file_share_revoker FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
