-- Team-level permissions and reviewer rules for the MDB backend.
-- Both tables are organization-scoped through their parent team.  The API
-- replaces a team's permission set transactionally, so vault-scoped and
-- global entries remain deterministic even on MariaDB's nullable UNIQUE keys.

CREATE TABLE IF NOT EXISTS team_permissions (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  team_id CHAR(36) NOT NULL,
  resource VARCHAR(200) NOT NULL,
  vault_id CHAR(36) NULL,
  actions JSON NOT NULL,
  granted_by CHAR(36) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_team_permissions_scope (organization_id, team_id, vault_id),
  INDEX idx_team_permissions_resource (team_id, resource),
  CONSTRAINT fk_team_permission_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_permission_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_permission_vault FOREIGN KEY (vault_id) REFERENCES vaults(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_permission_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_reviewers (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  team_id CHAR(36) NOT NULL,
  reviewer_type ENUM('user', 'workflow_role') NOT NULL,
  user_id CHAR(36) NULL,
  workflow_role_id CHAR(36) NULL,
  added_by CHAR(36) NOT NULL,
  added_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_team_reviewers_team (organization_id, team_id),
  INDEX idx_team_reviewers_user (user_id),
  INDEX idx_team_reviewers_workflow_role (workflow_role_id),
  CONSTRAINT fk_team_reviewer_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_reviewer_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_reviewer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_reviewer_workflow_role FOREIGN KEY (workflow_role_id) REFERENCES workflow_roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_reviewer_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
