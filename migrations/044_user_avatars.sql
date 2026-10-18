CREATE TABLE IF NOT EXISTS user_avatars (
  organization_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  content_type ENUM('image/png', 'image/jpeg', 'image/webp', 'image/gif') NOT NULL,
  image_bytes MEDIUMBLOB NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (organization_id, user_id),
  CONSTRAINT fk_user_avatars_membership FOREIGN KEY (organization_id, user_id)
    REFERENCES organization_memberships (organization_id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
