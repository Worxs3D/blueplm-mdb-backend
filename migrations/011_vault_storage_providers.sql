-- A vault can keep immutable BluePLM objects on a shared network location or
-- in an organization-owned Google Drive folder. Credentials are deliberately
-- not stored here; provider configuration only identifies the public root.
ALTER TABLE vaults
  MODIFY network_root VARCHAR(1024) NULL,
  ADD COLUMN IF NOT EXISTS storage_provider ENUM('network', 'google_drive') NOT NULL DEFAULT 'network' AFTER name,
  ADD COLUMN IF NOT EXISTS provider_config JSON NULL AFTER storage_provider;
