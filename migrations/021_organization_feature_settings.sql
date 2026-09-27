ALTER TABLE organization_settings
  ADD COLUMN IF NOT EXISTS serialization_settings JSON NULL AFTER document_manager_license_key,
  ADD COLUMN IF NOT EXISTS serialization_counter BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER serialization_settings,
  ADD COLUMN IF NOT EXISTS export_settings JSON NULL AFTER serialization_counter,
  ADD COLUMN IF NOT EXISTS rfq_settings JSON NULL AFTER export_settings,
  ADD COLUMN IF NOT EXISTS auth_provider_settings JSON NULL AFTER rfq_settings;
