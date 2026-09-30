-- A review raised solely by triggers_review has no workflow_gate.  The
-- original nullable gate contract is required so that such reviews can be
-- assigned and completed instead of becoming a permanent 202 response.
ALTER TABLE pending_reviews MODIFY gate_id CHAR(36) NULL;

-- MariaDB allows multiple NULLs in the existing composite key; direct reviews
-- are therefore de-duplicated transactionally by the API.  The existing
-- transition index already covers completion lookups without a duplicate DDL
-- operation on upgraded installations.
