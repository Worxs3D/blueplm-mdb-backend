ALTER TABLE files
  ADD COLUMN part_number VARCHAR(512) NULL AFTER file_name,
  ADD UNIQUE KEY uq_files_org_part_number (organization_id, part_number);
