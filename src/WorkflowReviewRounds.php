<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use Throwable;

/**
 * Owns the locking and round boundary for workflow review creation/decisions.
 * Public response shapes remain the historical workflow API contracts.
 */
final class WorkflowReviewRounds
{
    /** @return list<string> Reviewer user ids, always constrained to this tenant. */
    private static function reviewers(PDO $db, string $organizationId, ?string $gateId): array
    {
        if ($gateId === null) {
            $q = $db->prepare("SELECT user_id FROM organization_memberships WHERE organization_id = ? AND role IN ('owner', 'admin')");
            $q->execute([$organizationId]);
            return array_values(array_unique(array_map('strval', array_column($q->fetchAll(), 'user_id'))));
        }
        $q = $db->prepare(
            "SELECT DISTINCT m.user_id
             FROM workflow_gate_reviewers r
             JOIN workflow_gates g ON g.id = r.gate_id
             JOIN workflow_transitions t ON t.id = g.transition_id
             JOIN workflow_templates w ON w.id = t.workflow_id AND w.organization_id = ?
             JOIN organization_memberships m ON m.organization_id = w.organization_id
             LEFT JOIN user_workflow_roles uwr ON uwr.org_id = w.organization_id AND uwr.user_id = m.user_id
             LEFT JOIN team_members tm ON tm.user_id = m.user_id
             LEFT JOIN teams team ON team.id = tm.team_id AND team.organization_id = w.organization_id
             WHERE r.gate_id = ? AND (
               (r.reviewer_type = 'user' AND r.user_id = m.user_id)
               OR (r.reviewer_type = 'role' AND r.role = m.role)
               OR (r.reviewer_type = 'workflow_role' AND r.workflow_role_id = uwr.workflow_role_id)
               OR (r.reviewer_type = 'group' AND (r.group_name = team.id OR r.group_name = team.name))
             )"
        );
        $q->execute([$organizationId, $gateId]);
        return array_values(array_unique(array_map('strval', array_column($q->fetchAll(), 'user_id'))));
    }

    /** @param array<string, mixed> $principal
     *  @param list<array{id:?string}> $gates
     */
    private static function createReviews(PDO $db, array $principal, string $fileId, string $transitionId, array $gates, string $roundId): void
    {
        if ($gates === []) $gates = [['id' => null]];
        $insert = $db->prepare("INSERT INTO pending_reviews (id, organization_id, file_id, transition_id, gate_id, review_round_id, requested_by, assigned_to, status)
          SELECT ?, ?, ?, ?, ?, ?, ?, ?, 'pending' FROM DUAL
          WHERE NOT EXISTS (SELECT 1 FROM pending_reviews WHERE file_id = ? AND transition_id = ? AND gate_id <=> ? AND review_round_id = ? AND assigned_to = ? AND status = 'pending')");
        foreach ($gates as $gate) {
            $gateId = $gate['id'];
            $reviewers = self::reviewers($db, $principal['organizationId'], $gateId);
            if ($reviewers === []) $reviewers = [$principal['userId']];
            foreach ($reviewers as $reviewerId) {
                $insert->execute([Runtime::uuid(), $principal['organizationId'], $fileId, $transitionId, $gateId, $roundId, $principal['userId'], $reviewerId, $fileId, $transitionId, $gateId, $roundId, $reviewerId]);
            }
        }
    }

    private static function hasOrphanedPendingReviewer(PDO $db, string $organizationId, string $fileId, string $transitionId, string $roundId): bool
    {
        $query = $db->prepare(
            'SELECT 1
             FROM pending_reviews pr
             WHERE pr.organization_id = ? AND pr.file_id = ? AND pr.transition_id = ?
               AND pr.review_round_id = ? AND pr.status = \'pending\'
               AND NOT EXISTS (
                   SELECT 1 FROM organization_memberships m
                   WHERE m.organization_id = pr.organization_id AND m.user_id = pr.assigned_to
               )
             LIMIT 1'
        );
        $query->execute([$organizationId, $fileId, $transitionId, $roundId]);
        return $query->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $principal @param array<int, string> $matches */
    public static function executeTransition(PDO $db, array $principal, array $matches): void
    {
        $body = Runtime::jsonBody();
        $comment = is_string($body['comment'] ?? null) ? trim($body['comment']) : null;
        if ($comment !== null && strlen($comment) > 20000) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'comment is too long.']);
        $db->beginTransaction();
        try {
            $assignment = $db->prepare(
                'SELECT a.workflow_id, a.current_state_id, f.vault_id
                 FROM file_workflow_assignments a
                 JOIN files f ON f.id = a.file_id AND f.organization_id = ? AND f.deleted_at IS NULL
                 WHERE a.file_id = ? FOR UPDATE'
            );
            $assignment->execute([$principal['organizationId'], $matches[1]]);
            $assignmentRow = $assignment->fetch();
            if (!$assignmentRow) { $db->rollBack(); Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File workflow assignment not found.']); }
            Runtime::requireVault($db, $principal, $assignmentRow['vault_id']);
            $transition = $db->prepare(
                'SELECT t.id, t.workflow_id, t.from_state_id, t.to_state_id, t.name,
                        t.allowed_workflow_roles, t.auto_conditions,
                        s.name AS to_state_name, s.triggers_review, s.auto_increment_revision,
                        (SELECT COUNT(*) FROM workflow_gates g WHERE g.transition_id = t.id AND g.is_blocking = TRUE) AS gate_count
                 FROM workflow_transitions t
                 JOIN workflow_states s ON s.id = t.to_state_id
                 WHERE t.id = ? AND t.workflow_id = ? AND t.from_state_id = ?'
            );
            $transition->execute([$matches[2], $assignmentRow['workflow_id'], $assignmentRow['current_state_id']]);
            $transitionRow = $transition->fetch();
            if (!$transitionRow) { $db->rollBack(); Runtime::respond(409, ['error' => 'TRANSITION_UNAVAILABLE', 'message' => 'The workflow transition is no longer available.']); }
            $allowedRoles = json_decode((string)($transitionRow['allowed_workflow_roles'] ?? 'null'), true);
            if (!in_array($principal['role'], ['owner', 'admin'], true) && is_array($allowedRoles) && $allowedRoles !== []) {
                $roles = $db->prepare('SELECT uwr.workflow_role_id FROM user_workflow_roles uwr JOIN workflow_roles wr ON wr.id = uwr.workflow_role_id AND wr.org_id = uwr.org_id AND wr.is_active = TRUE WHERE uwr.user_id = ? AND uwr.org_id = ?');
                $roles->execute([$principal['userId'], $principal['organizationId']]);
                $ownedRoles = array_map('strval', array_column($roles->fetchAll(), 'workflow_role_id'));
                if (array_intersect($ownedRoles, array_map('strval', $allowedRoles)) === []) {
                    $db->rollBack();
                    Runtime::respond(403, ['error' => 'WORKFLOW_ROLE_REQUIRED', 'message' => 'A workflow role is required for this transition.']);
                }
            }
            $gate = $db->prepare('SELECT id FROM workflow_gates WHERE transition_id = ? AND is_blocking = TRUE ORDER BY sort_order, id');
            $gate->execute([$transitionRow['id']]);
            $gates = $gate->fetchAll();
            if ($gates || (bool)$transitionRow['triggers_review']) {
                $activeRounds = $db->prepare(
                    "SELECT review_round_id, MAX(legacy_round_ambiguous) AS legacy_round_ambiguous
                     FROM pending_reviews
                     WHERE file_id = ? AND transition_id = ? AND status = 'pending'
                     GROUP BY review_round_id"
                );
                $activeRounds->execute([$matches[1], $transitionRow['id']]);
                $rounds = $activeRounds->fetchAll();
                if (count($rounds) > 1 || ($rounds !== [] && (bool)$rounds[0]['legacy_round_ambiguous'])) {
                    $db->rollBack();
                    Runtime::respond(409, ['error' => 'REVIEW_ROUND_STATE_AMBIGUOUS', 'message' => 'The active review round requires administrator recovery.']);
                }
                if ($rounds === []) {
                    self::createReviews($db, $principal, $matches[1], $transitionRow['id'], $gates, Runtime::uuid());
                } elseif (self::hasOrphanedPendingReviewer($db, $principal['organizationId'], $matches[1], $transitionRow['id'], $rounds[0]['review_round_id'])) {
                    $oldRoundId = (string)$rounds[0]['review_round_id'];
                    $db->prepare("UPDATE pending_reviews SET status = 'cancelled' WHERE organization_id = ? AND file_id = ? AND transition_id = ? AND review_round_id = ? AND status = 'pending'")
                        ->execute([$principal['organizationId'], $matches[1], $transitionRow['id'], $oldRoundId]);
                    self::createReviews($db, $principal, $matches[1], $transitionRow['id'], $gates, Runtime::uuid());
                }
                Runtime::emitEvent($db, $principal['organizationId'], 'file.workflow_review_requested', $matches[1], [
                    'fileId' => $matches[1], 'transitionId' => $transitionRow['id'], 'userId' => $principal['userId'],
                ]);
                $db->commit();
                Runtime::respond(200, ['result' => [
                    'success' => true, 'requires_review' => true, 'new_state_id' => null,
                    'new_state_name' => null, 'new_revision' => null, 'error_code' => null, 'error_message' => null,
                ]]);
            }
            $revision = $db->prepare('SELECT current_revision FROM files WHERE id = ? AND organization_id = ? FOR UPDATE');
            $revision->execute([$matches[1], $principal['organizationId']]);
            $revisionBefore = (int)$revision->fetchColumn();
            $revisionAfter = $revisionBefore;
            if ((bool)$transitionRow['auto_increment_revision']) {
                $revisionAfter++;
                $db->prepare('UPDATE files SET current_revision = ? WHERE id = ? AND organization_id = ?')->execute([$revisionAfter, $matches[1], $principal['organizationId']]);
            }
            $db->prepare('UPDATE file_workflow_assignments SET current_state_id = ?, assigned_by = ?, assigned_at = UTC_TIMESTAMP(3) WHERE file_id = ?')
                ->execute([$transitionRow['to_state_id'], $principal['userId'], $matches[1]]);
            $db->prepare('INSERT INTO workflow_history (id, organization_id, file_id, workflow_id, transition_id, from_state_id, to_state_id, performed_by, comment, revision_before, revision_after) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([Runtime::uuid(), $principal['organizationId'], $matches[1], $transitionRow['workflow_id'], $transitionRow['id'], $assignmentRow['current_state_id'], $transitionRow['to_state_id'], $principal['userId'], $comment, $revisionBefore, $revisionAfter]);
            Runtime::emitEvent($db, $principal['organizationId'], 'file.workflow_transition_executed', $matches[1], [
                'fileId' => $matches[1], 'transitionId' => $matches[2], 'comment' => $comment,
                'userId' => $principal['userId'],
            ]);
            $db->commit();
            $state = $db->prepare('SELECT name FROM workflow_states WHERE id = ?');
            $state->execute([$transitionRow['to_state_id']]);
            $stateName = $state->fetchColumn();
            Runtime::respond(200, ['result' => [
                'success' => true, 'requires_review' => false,
                'new_state_id' => $transitionRow['to_state_id'], 'new_state_name' => $stateName,
                'new_revision' => $revisionAfter === $revisionBefore ? null : (string)$revisionAfter, 'error_code' => null, 'error_message' => null,
            ]]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    /** @param array<string, mixed> $principal */
    public static function listMine(PDO $db, array $principal): void
    {
        $query = $db->prepare("SELECT pr.id AS review_id, pr.file_id, pr.transition_id, pr.gate_id, pr.status, pr.requested_at, pr.expires_at,
          f.file_name AS file_name, f.storage_relative_path AS file_path, f.vault_id, g.name AS gate_name, g.gate_type,
          t.name AS transition_name, src.name AS from_state_name, dst.name AS to_state_name,
          pr.requested_by, u.email AS requested_by_email, COALESCE(g.checklist_items, JSON_ARRAY()) AS checklist_items
          FROM pending_reviews pr JOIN files f ON f.id = pr.file_id AND f.organization_id = pr.organization_id
          JOIN workflow_transitions t ON t.id = pr.transition_id
          JOIN workflow_states src ON src.id = t.from_state_id JOIN workflow_states dst ON dst.id = t.to_state_id
          LEFT JOIN workflow_gates g ON g.id = pr.gate_id LEFT JOIN users u ON u.id = pr.requested_by
          WHERE pr.organization_id = ? AND pr.assigned_to = ? AND pr.status = 'pending' AND (pr.expires_at IS NULL OR pr.expires_at > UTC_TIMESTAMP(3)) ORDER BY pr.requested_at");
        $query->execute([$principal['organizationId'], $principal['userId']]);
        $reviews = [];
        foreach ($query->fetchAll() as $review) {
            try { Runtime::requireVault($db, $principal, $review['vault_id']); $reviews[] = $review; }
            catch (Throwable) { /* Do not disclose reviews in inaccessible vaults. */ }
        }
        Runtime::respond(200, ['reviews' => $reviews]);
    }

    /** @param array<string, mixed> $principal @param array<int, string> $matches */
    public static function decide(PDO $db, array $principal, array $matches): void
    {
        $body = Runtime::jsonBody();
        $decision = $body['decision'] ?? null;
        if (!in_array($decision, ['approved', 'rejected', 'kicked_back'], true)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid review decision is required.']);
        $comment = is_string($body['comment'] ?? null) ? trim($body['comment']) : null;
        if ($comment !== null && strlen($comment) > 20000) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'comment is too long.']);
        // Resolve the tenant-bound assignment target before opening the
        // transaction. Under InnoDB REPEATABLE READ, a consistent read here
        // would otherwise establish a snapshot before we wait for the
        // assignment lock, leaving later quorum reads stale after that wait.
        $targetQuery = $db->prepare('SELECT file_id FROM pending_reviews WHERE id = ? AND organization_id = ?');
        $targetQuery->execute([$matches[1], $principal['organizationId']]);
        $targetFileId = $targetQuery->fetchColumn();
        if ($targetFileId === false) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Review not found.']);

        $db->beginTransaction();
        try {
            $assignmentLock = $db->prepare(
                'SELECT a.workflow_id, a.current_state_id
                 FROM file_workflow_assignments a
                 JOIN files f ON f.id = a.file_id AND f.organization_id = ? AND f.deleted_at IS NULL
                 WHERE a.file_id = ? FOR UPDATE'
            );
            $assignmentLock->execute([$principal['organizationId'], $targetFileId]);
            $lockedAssignment = $assignmentLock->fetch();
            if (!$lockedAssignment) { $db->rollBack(); Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Review not found.']); }
            $reviewQuery = $db->prepare('SELECT pr.id, pr.file_id, pr.transition_id, pr.gate_id, pr.review_round_id, pr.legacy_round_ambiguous, pr.assigned_to, pr.status, pr.expires_at,
              f.vault_id, a.workflow_id, a.current_state_id, t.from_state_id, t.to_state_id, t.name AS transition_name, s.name AS state_name, s.auto_increment_revision
              FROM pending_reviews pr JOIN files f ON f.id = pr.file_id AND f.organization_id = pr.organization_id
              JOIN file_workflow_assignments a ON a.file_id = pr.file_id
              JOIN workflow_transitions t ON t.id = pr.transition_id AND t.workflow_id = a.workflow_id
              JOIN workflow_states s ON s.id = t.to_state_id
              WHERE pr.id = ? AND pr.organization_id = ? AND pr.file_id = ? FOR UPDATE');
            $reviewQuery->execute([$matches[1], $principal['organizationId'], $targetFileId]); $review = $reviewQuery->fetch();
            if (!$review) { $db->rollBack(); Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Review not found.']); }
            Runtime::requireVault($db, $principal, $review['vault_id']);
            if ($review['assigned_to'] !== $principal['userId']) { $db->rollBack(); Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'You are not assigned to this review.']); }
            if ($review['status'] !== 'pending') { $db->rollBack(); Runtime::respond(409, ['error' => 'ALREADY_DECIDED', 'message' => 'This review was already decided.']); }
            if ((bool)$review['legacy_round_ambiguous'] && $decision === 'approved') { $db->rollBack(); Runtime::respond(409, ['error' => 'REVIEW_ROUND_STATE_AMBIGUOUS', 'message' => 'This ambiguous legacy review cannot approve a workflow transition. Reject or return the pending review to close the legacy task.']); }
            if ($review['expires_at'] !== null && strtotime((string)$review['expires_at']) <= time()) { $db->rollBack(); Runtime::respond(409, ['error' => 'REVIEW_EXPIRED', 'message' => 'This review has expired.']); }
            if ($review['current_state_id'] !== $review['from_state_id']) { self::cancelPending($db, $review); $db->commit(); Runtime::respond(409, ['error' => 'STALE_REVIEW', 'message' => 'The workflow state changed before this review was completed.']); }
            $db->prepare('UPDATE pending_reviews SET status = ?, review_comment = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(3), checklist_responses = ? WHERE id = ? AND review_round_id = ? AND status = \'pending\'')->execute([$decision, $comment, $principal['userId'], isset($body['checklistResponses']) ? json_encode($body['checklistResponses'], JSON_THROW_ON_ERROR) : null, $review['id'], $review['review_round_id']]);
            if ($decision !== 'approved') {
                self::cancelPending($db, $review);
                Runtime::emitEvent($db, $principal['organizationId'], 'review.rejected', $review['id'], ['fileId' => $review['file_id'], 'transitionId' => $review['transition_id'], 'userId' => $principal['userId']]);
                $db->commit(); Runtime::respond(200, ['result' => ['success' => true, 'requires_review' => false, 'new_state_id' => null, 'new_state_name' => null, 'new_revision' => null, 'error_code' => null, 'error_message' => null]]);
            }
            $isDirectReview = $review['gate_id'] === null || $review['gate_id'] === '';
            $gates = $db->prepare('SELECT id, approval_mode, required_approvals FROM workflow_gates WHERE transition_id = ? AND is_blocking = TRUE'); $gates->execute([$review['transition_id']]);
            $complete = self::hasQuorum($db, $review, $isDirectReview ? [] : $gates->fetchAll());
            if ($isDirectReview && self::hasOtherDirectPending($db, $review)) $complete = false;
            if (!$complete) { $db->commit(); Runtime::respond(200, ['result' => ['success' => true, 'requires_review' => true, 'new_state_id' => null, 'new_state_name' => null, 'new_revision' => null, 'error_code' => null, 'error_message' => null]]); }
            self::cancelPending($db, $review);
            $revision = $db->prepare('SELECT current_revision FROM files WHERE id = ? AND organization_id = ? FOR UPDATE'); $revision->execute([$review['file_id'], $principal['organizationId']]); $before = (int)$revision->fetchColumn(); $after = $before + ((bool)$review['auto_increment_revision'] ? 1 : 0);
            if ($after !== $before) $db->prepare('UPDATE files SET current_revision = ? WHERE id = ? AND organization_id = ?')->execute([$after, $review['file_id'], $principal['organizationId']]);
            $db->prepare('UPDATE file_workflow_assignments SET current_state_id = ?, assigned_by = ?, assigned_at = UTC_TIMESTAMP(3) WHERE file_id = ? AND current_state_id = ?')->execute([$review['to_state_id'], $principal['userId'], $review['file_id'], $review['from_state_id']]);
            $db->prepare('INSERT INTO workflow_history (id, organization_id, file_id, workflow_id, transition_id, from_state_id, to_state_id, performed_by, comment, approvals_data, revision_before, revision_after) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([Runtime::uuid(), $principal['organizationId'], $review['file_id'], $review['workflow_id'], $review['transition_id'], $review['from_state_id'], $review['to_state_id'], $principal['userId'], $comment, json_encode(['completedReviewId' => $review['id']], JSON_THROW_ON_ERROR), $before, $after]);
            Runtime::emitEvent($db, $principal['organizationId'], 'file.workflow_transition_executed', $review['file_id'], ['fileId' => $review['file_id'], 'transitionId' => $review['transition_id'], 'reviewId' => $review['id'], 'userId' => $principal['userId']]);
            $db->commit(); Runtime::respond(200, ['result' => ['success' => true, 'requires_review' => false, 'new_state_id' => $review['to_state_id'], 'new_state_name' => $review['state_name'], 'new_revision' => $after === $before ? null : (string)$after, 'error_code' => null, 'error_message' => null]]);
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    }

    /** @param array<string, mixed> $review */
    private static function cancelPending(PDO $db, array $review): void
    {
        $db->prepare("UPDATE pending_reviews SET status = 'cancelled' WHERE file_id = ? AND transition_id = ? AND review_round_id = ? AND status = 'pending'")
            ->execute([$review['file_id'], $review['transition_id'], $review['review_round_id']]);
    }

    /** @param array<string, mixed> $review @param list<array<string, mixed>> $gates */
    private static function hasQuorum(PDO $db, array $review, array $gates): bool
    {
        foreach ($gates as $gate) {
            $counts = $db->prepare("SELECT COUNT(*) AS total, SUM(status = 'approved') AS approved, SUM(status IN ('rejected','kicked_back')) AS rejected FROM pending_reviews WHERE file_id = ? AND transition_id = ? AND gate_id = ? AND review_round_id = ?");
            $counts->execute([$review['file_id'], $review['transition_id'], $gate['id'], $review['review_round_id']]);
            $count = $counts->fetch();
            if ((int)$count['rejected'] > 0) return false;
            $needed = match ($gate['approval_mode']) { 'all' => (int)$count['total'], 'majority' => intdiv((int)$count['total'], 2) + 1, default => max(1, (int)$gate['required_approvals']) };
            if ((int)$count['total'] === 0 || (int)$count['approved'] < $needed) return false;
        }
        return true;
    }

    /** @param array<string, mixed> $review */
    private static function hasOtherDirectPending(PDO $db, array $review): bool
    {
        $direct = $db->prepare("SELECT id FROM pending_reviews WHERE file_id = ? AND transition_id = ? AND review_round_id = ? AND gate_id IS NULL AND status = 'pending' AND id <> ? FOR UPDATE");
        $direct->execute([$review['file_id'], $review['transition_id'], $review['review_round_id'], $review['id']]);
        return (bool)$direct->fetch();
    }
}
