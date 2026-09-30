-- A review raised solely by triggers_review has no workflow_gate.  The
-- original nullable gate contract is required so that such reviews can be
-- assigned and completed instead of becoming a permanent 202 response.
ALTER TABLE pending_reviews MODIFY gate_id CHAR(36) NULL;

-- One active decision per reviewer is sufficient for both gated and direct
-- review requests.  MariaDB allows multiple NULLs in the old composite key,
-- so direct reviews are additionally de-duplicated by the API transaction.
CREATE INDEX idx_pending_reviews_direct ON pending_reviews (file_id, transition_id, gate_id, status);
