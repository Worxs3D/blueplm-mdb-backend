-- Persist the per-organization, per-year RFQ sequence used by the standard UI.
-- Existing numbers are read when a counter is first created by the write path.
CREATE TABLE IF NOT EXISTS rfq_number_counters (
  organization_id CHAR(36) NOT NULL,
  sequence_year SMALLINT UNSIGNED NOT NULL,
  last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (organization_id, sequence_year),
  CONSTRAINT fk_rfq_number_counter_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
