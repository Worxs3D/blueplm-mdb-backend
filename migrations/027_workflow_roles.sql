-- Workflow roles are organization-owned workflow metadata. They are
-- deliberately separate from organization_memberships.role: an account role
-- must never grant a workflow role implicitly.
CREATE TABLE IF NOT EXISTS workflow_roles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  org_id CHAR(36) NOT NULL,
  name VARCHAR(200) NOT NULL,
  description TEXT NULL,
  color VARCHAR(32) NOT NULL DEFAULT '#6B7280',
  icon VARCHAR(64) NOT NULL DEFAULT 'badge-check',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by CHAR(36) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  updated_by CHAR(36) NULL,
  UNIQUE KEY uq_workflow_role_name (org_id, name),
  INDEX idx_workflow_roles_org (org_id, is_active, sort_order, name),
  CONSTRAINT fk_workflow_role_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_workflow_role_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_workflow_role_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_workflow_roles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  org_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  workflow_role_id CHAR(36) NOT NULL,
  assigned_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  assigned_by CHAR(36) NULL,
  UNIQUE KEY uq_user_workflow_role (user_id, workflow_role_id),
  INDEX idx_user_workflow_roles_org_user (org_id, user_id),
  INDEX idx_user_workflow_roles_role (workflow_role_id),
  CONSTRAINT fk_user_workflow_role_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_workflow_role_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_workflow_role_role FOREIGN KEY (workflow_role_id) REFERENCES workflow_roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_workflow_role_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
