-- Threaded file comments and PDF annotations.  The rows belong to a file;
-- authorization is always derived from that file's vault, never from a
-- client-provided organization id.
CREATE TABLE IF NOT EXISTS file_comments (
  id CHAR(36) NOT NULL PRIMARY KEY,
  file_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  comment TEXT NOT NULL,
  page_number INT NULL,
  position JSON NULL,
  annotation_type ENUM('area', 'text', 'highlight', 'file') NOT NULL DEFAULT 'text',
  parent_id CHAR(36) NULL,
  resolved BOOLEAN NOT NULL DEFAULT FALSE,
  resolved_by CHAR(36) NULL,
  resolved_at DATETIME(3) NULL,
  file_version INT UNSIGNED NULL,
  edited_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_file_comments_file_version (file_id, file_version, created_at),
  INDEX idx_file_comments_parent (parent_id),
  CONSTRAINT fk_file_comment_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_comment_author FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_file_comment_parent FOREIGN KEY (parent_id) REFERENCES file_comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_comment_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
