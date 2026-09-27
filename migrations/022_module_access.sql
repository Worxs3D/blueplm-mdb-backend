CREATE TABLE IF NOT EXISTS module_access (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  module_id VARCHAR(128) NOT NULL,
  team_id CHAR(36) NULL,
  user_id CHAR(36) NULL,
  granted_by CHAR(36) NOT NULL,
  granted_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_module_access_team (organization_id, module_id, team_id),
  UNIQUE KEY uq_module_access_user (organization_id, module_id, user_id),
  KEY idx_module_access_scope (organization_id, module_id),
  CONSTRAINT fk_module_access_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_module_access_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_module_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_module_access_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
