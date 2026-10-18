CREATE TABLE IF NOT EXISTS organization_logos (
  organization_id CHAR(36) NOT NULL PRIMARY KEY,
  asset_ref CHAR(36) NOT NULL UNIQUE,
  content_type ENUM('image/png', 'image/jpeg', 'image/webp', 'image/gif') NOT NULL,
  image_bytes MEDIUMBLOB NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_organization_logos_org FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
