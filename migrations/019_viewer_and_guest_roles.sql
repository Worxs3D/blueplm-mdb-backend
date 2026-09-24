ALTER TABLE organization_memberships
  MODIFY role ENUM('owner', 'admin', 'member', 'viewer', 'guest') NOT NULL DEFAULT 'member';
