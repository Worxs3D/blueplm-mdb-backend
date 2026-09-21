ALTER TABLE organization_settings
  ADD COLUMN document_manager_license_key TEXT NULL AFTER default_new_user_team_id;
