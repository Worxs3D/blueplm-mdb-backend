ALTER TABLE ecos
  ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER title,
  ADD COLUMN IF NOT EXISTS created_by CHAR(36) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS updated_by CHAR(36) NULL AFTER created_by,
  ADD COLUMN IF NOT EXISTS completed_at DATETIME(3) NULL AFTER updated_by,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) AFTER created_at;

-- Foreign keys are created by Migrator::ensureEcoMetadataConstraints().
-- It queries information_schema first because shared-host MariaDB versions
-- differ in whether ADD CONSTRAINT accepts IF NOT EXISTS.
