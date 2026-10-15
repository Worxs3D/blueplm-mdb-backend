CREATE TABLE IF NOT EXISTS color_swatches (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  user_id CHAR(36) NULL,
  color CHAR(7) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_color_swatches_org_created (organization_id, created_at, id),
  INDEX idx_color_swatches_org_user_created (organization_id, user_id, created_at, id),
  CONSTRAINT fk_color_swatch_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_color_swatch_membership FOREIGN KEY (organization_id, user_id) REFERENCES organization_memberships(organization_id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
