CREATE TABLE IF NOT EXISTS job_titles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  name VARCHAR(200) NOT NULL,
  color CHAR(7) NOT NULL,
  icon VARCHAR(64) NOT NULL,
  created_by CHAR(36) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_job_titles_org_id (organization_id, id),
  UNIQUE KEY uq_job_titles_org_name (organization_id, name),
  CONSTRAINT fk_job_titles_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_job_titles_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_job_titles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  organization_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  title_id CHAR(36) NOT NULL,
  assigned_by CHAR(36) NOT NULL,
  assigned_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_user_job_titles_org_user (organization_id, user_id),
  KEY idx_user_job_titles_org_title (organization_id, title_id),
  CONSTRAINT fk_user_job_titles_membership FOREIGN KEY (organization_id, user_id)
    REFERENCES organization_memberships(organization_id, user_id) ON DELETE CASCADE,
  CONSTRAINT fk_user_job_titles_title FOREIGN KEY (organization_id, title_id)
    REFERENCES job_titles(organization_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_user_job_titles_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
