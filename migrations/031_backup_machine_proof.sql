-- 030 already includes this column in fresh installs; retain this migration for
-- older databases without making an installation fail on the newer baseline.
ALTER TABLE backup_config ADD COLUMN IF NOT EXISTS designated_machine_proof_hash CHAR(64) NULL AFTER designated_machine_last_seen;
