-- This contribution supports network vaults only. Keep the archive root
-- mandatory and do not add provider-specific configuration columns here.
ALTER TABLE vaults
  MODIFY network_root VARCHAR(1024) NOT NULL;
