ALTER TABLE organizations
  ADD COLUMN IF NOT EXISTS logo_storage_path VARCHAR(2048) NULL,
  ADD COLUMN IF NOT EXISTS phone VARCHAR(128) NULL,
  ADD COLUMN IF NOT EXISTS website VARCHAR(2048) NULL,
  ADD COLUMN IF NOT EXISTS contact_email VARCHAR(320) NULL;

CREATE TABLE IF NOT EXISTS organization_addresses (
  id CHAR(36) NOT NULL PRIMARY KEY, organization_id CHAR(36) NOT NULL,
  address_type ENUM('billing','shipping') NOT NULL, label VARCHAR(255) NOT NULL, is_default BOOLEAN NOT NULL DEFAULT FALSE,
  company_name VARCHAR(255) NULL, contact_name VARCHAR(255) NULL, address_line1 VARCHAR(255) NOT NULL, address_line2 VARCHAR(255) NULL,
  city VARCHAR(128) NOT NULL, state VARCHAR(128) NULL, postal_code VARCHAR(64) NULL, country VARCHAR(128) NOT NULL,
  attention_to VARCHAR(255) NULL, phone VARCHAR(128) NULL, created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY idx_org_addresses (organization_id,address_type,is_default), CONSTRAINT fk_org_address_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
