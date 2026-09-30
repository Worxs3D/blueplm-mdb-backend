ALTER TABLE teams
  ADD COLUMN module_defaults JSON NULL,
  ADD COLUMN module_defaults_forced_at DATETIME(3) NULL;

ALTER TABLE organization_settings
  ADD COLUMN module_defaults JSON NULL,
  ADD COLUMN module_defaults_forced_at DATETIME(3) NULL;
