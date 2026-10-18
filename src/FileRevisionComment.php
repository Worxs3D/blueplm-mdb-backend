<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use Throwable;

final class FileRevisionCommentFailure extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

final class FileRevisionComment
{
    /** @param array<string, string> $env @param array<string, mixed> $principal @param array<string, mixed> $body */
    public static function update(PDO $db, array $env, array $principal, string $fileId, string $revisionId, array $body): array
    {
        if (!array_key_exists('comment', $body) || ($body['comment'] !== null && !is_string($body['comment']))) {
            throw new FileRevisionCommentFailure(400, 'INVALID_REQUEST', 'comment must be a string or null.');
        }
        if (!is_string($body['checkoutToken'] ?? null) || strlen($body['checkoutToken']) < 20) {
            throw new FileRevisionCommentFailure(400, 'INVALID_REQUEST', 'checkoutToken is required.');
        }
        $comment = is_string($body['comment']) ? trim($body['comment']) : null;
        if ($comment === '') $comment = null;
        if ($comment !== null && strlen($comment) > 4000) {
            throw new FileRevisionCommentFailure(400, 'INVALID_REQUEST', 'comment is too long.');
        }

        try {
            $lifecycleLock = FileCheckout::acquireUserLifecycleLock($db, (string)$principal['userId']);
        } catch (Throwable) {
            throw new FileRevisionCommentFailure(503, 'CHECKOUT_BUSY', 'The user checkout lifecycle is temporarily busy.');
        }
        if ($lifecycleLock === null) throw new FileRevisionCommentFailure(503, 'CHECKOUT_BUSY', 'The user checkout lifecycle is temporarily busy.');

        $committed = false;
        $operationFailure = null;
        $result = null;
        try {
            try {
                FileCheckout::assertCheckoutPrincipalActive($db, $principal);
            } catch (CheckoutLeaseFailure $error) {
                throw new FileRevisionCommentFailure($error->status, $error->errorCode, $error->getMessage());
            }

            $db->beginTransaction();
            $fileQuery = $db->prepare('SELECT id, vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL FOR UPDATE');
            $fileQuery->execute([$fileId, $principal['organizationId']]);
            $file = $fileQuery->fetch(PDO::FETCH_ASSOC);
            if (!$file) throw new FileRevisionCommentFailure(404, 'NOT_FOUND', 'File not found.');
            Runtime::requireVault($db, $principal, (string)$file['vault_id']);

            // Keep the established file -> checkout -> revision lock order.
            $leaseQuery = $db->prepare('SELECT user_id, token_hash, (expires_at > UTC_TIMESTAMP(3)) AS lease_active FROM checkouts WHERE file_id = ? FOR UPDATE');
            $leaseQuery->execute([$fileId]);
            $lease = $leaseQuery->fetch(PDO::FETCH_ASSOC);
            $tokenHash = Runtime::tokenHash($body['checkoutToken'], $env);
            if (!$lease || $lease['user_id'] !== $principal['userId'] || (int)$lease['lease_active'] !== 1 || !hash_equals($lease['token_hash'], $tokenHash)) {
                throw new FileRevisionCommentFailure(409, 'INVALID_CHECKOUT', 'A valid, unexpired checkout from this client is required.');
            }

            $revisionQuery = $db->prepare('SELECT id, revision_number FROM file_revisions WHERE id = ? AND file_id = ? FOR UPDATE');
            $revisionQuery->execute([$revisionId, $fileId]);
            $revision = $revisionQuery->fetch(PDO::FETCH_ASSOC);
            if (!$revision) throw new FileRevisionCommentFailure(404, 'NOT_FOUND', 'File revision not found.');

            $db->prepare('UPDATE file_revisions SET comment = ? WHERE id = ? AND file_id = ?')->execute([$comment, $revisionId, $fileId]);
            Runtime::emitEvent($db, (string)$principal['organizationId'], 'file.revision_comment_updated', $fileId, [
                'revisionId' => $revisionId,
                'revisionNumber' => (int)$revision['revision_number'],
                'userId' => (string)$principal['userId'],
            ]);
            $db->commit();
            $committed = true;
            $result = ['success' => true, 'comment' => $comment];
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            $operationFailure = $error;
        } finally {
            FileCheckout::finishUserLifecycleLock($db, $lifecycleLock, $committed, $operationFailure);
        }

        if ($operationFailure !== null) throw $operationFailure;
        return $result ?? throw new \LogicException('Revision comment update completed without a result.');
    }
}
