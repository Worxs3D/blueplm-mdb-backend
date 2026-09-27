ALTER TABLE files
  ADD COLUMN IF NOT EXISTS part_number VARCHAR(512) NULL AFTER file_name;

-- The unique key is added idempotently by Migrator after legacy schemas have
-- been adopted. This keeps a retry safe while still rejecting duplicate data.
