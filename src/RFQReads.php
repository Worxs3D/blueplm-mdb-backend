<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;

final class RFQReads
{
    private const STATUSES = [
        'draft', 'pending_files', 'generating', 'ready', 'sent',
        'awaiting_quote', 'quoted', 'awarded', 'cancelled', 'completed',
    ];
    private const LIST_LIMIT = 100;
    private const DETAIL_LIMIT = 200;

    /** @param array<string, mixed> $principal */
    public static function dispatch(PDO $db, array $principal, string $method, string $path): void
    {
        if ($method !== 'GET') return;
        if ($path === '/rfqs') {
            self::list($db, $principal);
        }
        if (preg_match('#^/rfqs/([^/]+)$#D', $path, $matches) === 1) {
            $id = rawurldecode($matches[1]);
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id) !== 1) {
                Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'RFQ ID must be a UUID.']);
            }
            self::detail($db, $principal, strtolower($id));
        }
    }

    /** @param array<string, mixed> $principal */
    private static function list(PDO $db, array $principal): never
    {
        self::requireModuleAccess($db, $principal);
        $page = self::queryInteger('page', 1, 1, 100000);
        $limit = self::queryInteger('limit', 50, 1, self::LIST_LIMIT);
        $status = $_GET['status'] ?? null;
        if ($status !== null && (!is_string($status) || !in_array($status, self::STATUSES, true))) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'status is not a supported RFQ status.']);
        }
        $search = $_GET['q'] ?? '';
        if (!is_string($search) || strlen($search) > 200) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'q must be at most 200 bytes.']);
        }

        $where = ['r.organization_id = ?'];
        $params = [$principal['organizationId']];
        if ($status !== null) {
            $where[] = 'r.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(r.rfq_number LIKE ? OR r.title LIKE ?)';
            $needle = '%' . $search . '%';
            $params[] = $needle;
            $params[] = $needle;
        }
        $offset = ($page - 1) * $limit;
        if ($offset > 1000000) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'page is outside the supported range.']);
        $sql = 'SELECT r.id, r.organization_id, r.rfq_number, r.title, r.description, r.status,
                       r.due_date, r.required_date, r.valid_until, r.release_files_generated,
                       r.release_files_generated_at, r.created_at, r.created_by,
                       u.display_name AS creator_display_name,
                       (SELECT COUNT(*) FROM rfq_items i WHERE i.organization_id = r.organization_id AND i.rfq_id = r.id) AS item_count,
                       (SELECT COUNT(*) FROM rfq_suppliers rs JOIN suppliers s ON s.id = rs.supplier_id AND s.organization_id = rs.organization_id
                        WHERE rs.organization_id = r.organization_id AND rs.rfq_id = r.id) AS supplier_count
                FROM rfqs r
                LEFT JOIN organization_memberships creator_membership
                  ON creator_membership.organization_id = r.organization_id AND creator_membership.user_id = r.created_by
                LEFT JOIN users u ON u.id = creator_membership.user_id AND u.disabled_at IS NULL
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY r.created_at DESC, r.id DESC LIMIT ? OFFSET ?';
        $query = $db->prepare($sql);
        foreach ($params as $index => $value) $query->bindValue($index + 1, $value, PDO::PARAM_STR);
        $query->bindValue(count($params) + 1, $limit + 1, PDO::PARAM_INT);
        $query->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $query->execute();
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $rfqs = array_map(static fn(array $row): array => [
            'id' => (string)$row['id'],
            'organizationId' => (string)$row['organization_id'],
            'rfqNumber' => (string)$row['rfq_number'],
            'title' => (string)$row['title'],
            'description' => $row['description'] !== null ? (string)$row['description'] : null,
            'status' => (string)$row['status'],
            'dueDate' => $row['due_date'] !== null ? (string)$row['due_date'] : null,
            'requiredDate' => $row['required_date'] !== null ? (string)$row['required_date'] : null,
            'validUntil' => $row['valid_until'] !== null ? (string)$row['valid_until'] : null,
            'releaseFilesGenerated' => (bool)$row['release_files_generated'],
            'releaseFilesGeneratedAt' => $row['release_files_generated_at'] !== null ? (string)$row['release_files_generated_at'] : null,
            'createdAt' => (string)$row['created_at'],
            'createdBy' => $row['created_by'] !== null ? (string)$row['created_by'] : null,
            'createdByUser' => $row['creator_display_name'] !== null ? ['displayName' => (string)$row['creator_display_name']] : null,
            'itemCount' => (int)$row['item_count'],
            'supplierCount' => (int)$row['supplier_count'],
        ], $rows);

        Runtime::respond(200, ['rfqs' => $rfqs, 'page' => $page, 'limit' => $limit, 'hasMore' => $hasMore]);
    }

    /** @param array<string, mixed> $principal */
    private static function detail(PDO $db, array $principal, string $id): never
    {
        self::requireModuleAccess($db, $principal);
        $query = $db->prepare(
            'SELECT r.id, r.organization_id, r.rfq_number, r.title, r.description, r.status,
                    r.due_date, r.required_date, r.valid_until, r.requires_samples,
                    r.requires_first_article, r.requires_quality_report, r.allow_partial_quotes,
                    r.release_files_generated, r.release_files_generated_at, r.release_folder_path,
                    r.billing_address_id, r.shipping_address_id, r.shipping_address, r.shipping_notes,
                    r.incoterms, r.internal_notes, r.supplier_notes, r.pdf_url, r.pdf_generated_at,
                    r.awarded_supplier_id, r.awarded_at, r.awarded_by, r.award_notes,
                    r.created_at, r.created_by, r.updated_at, r.updated_by,
                    r.sent_at, r.sent_by, r.completed_at,
                    u.display_name AS creator_display_name,
                    (SELECT COUNT(*) FROM rfq_items i WHERE i.organization_id = r.organization_id AND i.rfq_id = r.id) AS item_count,
                    (SELECT COUNT(*) FROM rfq_suppliers rs JOIN suppliers s ON s.id = rs.supplier_id AND s.organization_id = rs.organization_id
                     WHERE rs.organization_id = r.organization_id AND rs.rfq_id = r.id) AS supplier_count
             FROM rfqs r
             LEFT JOIN organization_memberships creator_membership
               ON creator_membership.organization_id = r.organization_id AND creator_membership.user_id = r.created_by
             LEFT JOIN users u ON u.id = creator_membership.user_id AND u.disabled_at IS NULL
             WHERE r.id = ? AND r.organization_id = ?'
        );
        $query->execute([$id, $principal['organizationId']]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'RFQ not found.']);

        $itemPage = self::queryInteger('itemPage', 1, 1, 100000);
        $itemLimit = self::queryInteger('itemLimit', 100, 1, self::DETAIL_LIMIT);
        $supplierPage = self::queryInteger('supplierPage', 1, 1, 100000);
        $supplierLimit = self::queryInteger('supplierLimit', 100, 1, self::DETAIL_LIMIT);
        $itemOffset = ($itemPage - 1) * $itemLimit;
        $supplierOffset = ($supplierPage - 1) * $supplierLimit;
        if ($itemOffset > 1000000 || $supplierOffset > 1000000) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Detail page is outside the supported range.']);

        $itemQuery = $db->prepare(
            'SELECT i.id, i.rfq_id, i.line_number, i.file_id, i.part_number, i.description,
                    i.revision, i.quantity, i.uom, i.material, i.finish, i.notes,
                    i.tolerance_class, i.special_requirements, i.sw_configuration,
                    i.release_files_status, i.release_files_error, i.step_file_generated,
                    i.step_file_path, i.step_storage_path, i.step_file_size,
                    i.pdf_file_generated, i.pdf_file_path, i.pdf_storage_path,
                    i.pdf_file_size, i.attachments, i.created_at, i.updated_at, i.sort_order,
                    f.id AS linked_file_id, f.file_name AS linked_file_name,
                    f.canonical_path AS linked_file_path, f.part_number AS linked_file_part_number,
                    f.description AS linked_file_description, f.revision_label AS linked_file_revision,
                    f.current_revision AS linked_file_version
             FROM rfq_items i
             JOIN rfqs r ON r.id = i.rfq_id AND r.organization_id = i.organization_id
             LEFT JOIN files f ON f.id = i.file_id AND f.organization_id = r.organization_id AND f.deleted_at IS NULL
               AND (r.organization_id = ?)
               AND (
                    ? IN (\'owner\', \'admin\')
                    OR EXISTS (SELECT 1 FROM vault_access va WHERE va.vault_id = f.vault_id AND va.user_id = ?)
                    OR (? <> \'guest\' AND EXISTS (
                        SELECT 1 FROM team_vault_access tva
                        JOIN team_members tm ON tm.team_id = tva.team_id AND tm.user_id = ?
                        JOIN teams t ON t.id = tm.team_id AND t.organization_id = r.organization_id
                        WHERE tva.vault_id = f.vault_id
                    ))
               )
             WHERE i.organization_id = ? AND i.rfq_id = ?
             ORDER BY i.sort_order, i.line_number, i.id LIMIT ? OFFSET ?'
        );
        $itemQuery->bindValue(1, $principal['organizationId'], PDO::PARAM_STR);
        $itemQuery->bindValue(2, $principal['role'], PDO::PARAM_STR);
        $itemQuery->bindValue(3, $principal['userId'], PDO::PARAM_STR);
        $itemQuery->bindValue(4, $principal['role'], PDO::PARAM_STR);
        $itemQuery->bindValue(5, $principal['userId'], PDO::PARAM_STR);
        $itemQuery->bindValue(6, $principal['organizationId'], PDO::PARAM_STR);
        $itemQuery->bindValue(7, $id, PDO::PARAM_STR);
        $itemQuery->bindValue(8, $itemLimit + 1, PDO::PARAM_INT);
        $itemQuery->bindValue(9, $itemOffset, PDO::PARAM_INT);
        $itemQuery->execute();
        $items = $itemQuery->fetchAll(PDO::FETCH_ASSOC);
        $itemsHasMore = count($items) > $itemLimit;
        if ($itemsHasMore) array_pop($items);
        $items = array_map(static function (array $item): array {
            $file = $item['linked_file_id'] !== null ? [
                'id' => (string)$item['linked_file_id'],
                'fileName' => (string)$item['linked_file_name'],
                'filePath' => (string)$item['linked_file_path'],
                'partNumber' => $item['linked_file_part_number'] !== null ? (string)$item['linked_file_part_number'] : null,
                'description' => $item['linked_file_description'] !== null ? (string)$item['linked_file_description'] : null,
                'revision' => $item['linked_file_revision'] !== null ? (string)$item['linked_file_revision'] : null,
                'version' => (int)$item['linked_file_version'],
                'extension' => strtolower(pathinfo((string)$item['linked_file_name'], PATHINFO_EXTENSION)),
            ] : null;
            return [
                'id' => (string)$item['id'],
                'rfqId' => (string)$item['rfq_id'],
                'lineNumber' => (int)$item['line_number'],
                'fileId' => $item['file_id'] !== null ? (string)$item['file_id'] : null,
                'partNumber' => $item['part_number'] !== null ? (string)$item['part_number'] : null,
                'description' => $item['description'] !== null ? (string)$item['description'] : null,
                'revision' => $item['revision'] !== null ? (string)$item['revision'] : null,
                'quantity' => (int)$item['quantity'],
                'uom' => (string)$item['uom'],
                'material' => $item['material'] !== null ? (string)$item['material'] : null,
                'finish' => $item['finish'] !== null ? (string)$item['finish'] : null,
                'notes' => $item['notes'] !== null ? (string)$item['notes'] : null,
                'toleranceClass' => $item['tolerance_class'] !== null ? (string)$item['tolerance_class'] : null,
                'specialRequirements' => $item['special_requirements'] !== null ? (string)$item['special_requirements'] : null,
                'swConfiguration' => is_string($item['sw_configuration']) ? $item['sw_configuration'] : null,
                'releaseFilesStatus' => (string)$item['release_files_status'],
                'releaseFilesError' => $item['release_files_error'] !== null ? (string)$item['release_files_error'] : null,
                'stepFileGenerated' => (bool)$item['step_file_generated'],
                'stepFilePath' => $item['step_file_path'] !== null ? (string)$item['step_file_path'] : null,
                'stepStoragePath' => $item['step_storage_path'] !== null ? (string)$item['step_storage_path'] : null,
                'stepFileSize' => $item['step_file_size'] !== null ? (int)$item['step_file_size'] : null,
                'pdfFileGenerated' => (bool)$item['pdf_file_generated'],
                'pdfFilePath' => $item['pdf_file_path'] !== null ? (string)$item['pdf_file_path'] : null,
                'pdfStoragePath' => $item['pdf_storage_path'] !== null ? (string)$item['pdf_storage_path'] : null,
                'pdfFileSize' => $item['pdf_file_size'] !== null ? (int)$item['pdf_file_size'] : null,
                'attachments' => self::decodeAttachments($item['attachments']),
                'createdAt' => (string)$item['created_at'],
                'updatedAt' => (string)$item['updated_at'],
                'sortOrder' => (int)$item['sort_order'],
                'file' => $file,
            ];
        }, $items);

        $supplierQuery = $db->prepare(
            'SELECT rs.id, rs.rfq_id, rs.supplier_id, rs.sent_at, rs.sent_by, rs.sent_via,
                    rs.viewed_at, rs.responded_at, rs.quoted_at, rs.declined_at, rs.declined_reason,
                    rs.response_status, rs.total_quoted_amount, rs.currency, rs.lead_time_days,
                    rs.is_selected, rs.selected_at, rs.selected_by, rs.notes, rs.created_at,
                    s.id AS linked_supplier_id, s.name AS linked_supplier_name,
                    s.code AS linked_supplier_code, s.contact_email AS linked_supplier_contact_email,
                    s.contact_name AS linked_supplier_contact_name
             FROM rfq_suppliers rs
             JOIN rfqs r ON r.id = rs.rfq_id AND r.organization_id = rs.organization_id
             JOIN suppliers s ON s.id = rs.supplier_id AND s.organization_id = rs.organization_id
             WHERE rs.organization_id = ? AND rs.rfq_id = ?
             ORDER BY s.name, rs.id LIMIT ? OFFSET ?'
        );
        $supplierQuery->bindValue(1, $principal['organizationId'], PDO::PARAM_STR);
        $supplierQuery->bindValue(2, $id, PDO::PARAM_STR);
        $supplierQuery->bindValue(3, $supplierLimit + 1, PDO::PARAM_INT);
        $supplierQuery->bindValue(4, $supplierOffset, PDO::PARAM_INT);
        $supplierQuery->execute();
        $suppliers = $supplierQuery->fetchAll(PDO::FETCH_ASSOC);
        $suppliersHasMore = count($suppliers) > $supplierLimit;
        if ($suppliersHasMore) array_pop($suppliers);
        $suppliers = array_map(static fn(array $supplier): array => [
            'id' => (string)$supplier['id'],
            'rfqId' => (string)$supplier['rfq_id'],
            'supplierId' => (string)$supplier['supplier_id'],
            'sentAt' => $supplier['sent_at'] !== null ? (string)$supplier['sent_at'] : null,
            'sentBy' => $supplier['sent_by'] !== null ? (string)$supplier['sent_by'] : null,
            'sentVia' => $supplier['sent_via'] !== null ? (string)$supplier['sent_via'] : null,
            'viewedAt' => $supplier['viewed_at'] !== null ? (string)$supplier['viewed_at'] : null,
            'respondedAt' => $supplier['responded_at'] !== null ? (string)$supplier['responded_at'] : null,
            'quotedAt' => $supplier['quoted_at'] !== null ? (string)$supplier['quoted_at'] : null,
            'declinedAt' => $supplier['declined_at'] !== null ? (string)$supplier['declined_at'] : null,
            'declinedReason' => $supplier['declined_reason'] !== null ? (string)$supplier['declined_reason'] : null,
            'responseStatus' => (string)$supplier['response_status'],
            'totalQuotedAmount' => $supplier['total_quoted_amount'] !== null ? (string)$supplier['total_quoted_amount'] : null,
            'currency' => (string)$supplier['currency'],
            'leadTimeDays' => $supplier['lead_time_days'] !== null ? (int)$supplier['lead_time_days'] : null,
            'isSelected' => (bool)$supplier['is_selected'],
            'selectedAt' => $supplier['selected_at'] !== null ? (string)$supplier['selected_at'] : null,
            'selectedBy' => $supplier['selected_by'] !== null ? (string)$supplier['selected_by'] : null,
            'notes' => $supplier['notes'] !== null ? (string)$supplier['notes'] : null,
            'createdAt' => (string)$supplier['created_at'],
            'supplier' => [
                'id' => (string)$supplier['linked_supplier_id'],
                'name' => (string)$supplier['linked_supplier_name'],
                'code' => $supplier['linked_supplier_code'] !== null ? (string)$supplier['linked_supplier_code'] : null,
                'contactEmail' => $supplier['linked_supplier_contact_email'] !== null ? (string)$supplier['linked_supplier_contact_email'] : null,
                'contactName' => $supplier['linked_supplier_contact_name'] !== null ? (string)$supplier['linked_supplier_contact_name'] : null,
            ],
        ], $suppliers);

        Runtime::respond(200, [
            'rfq' => [
                'id' => (string)$row['id'],
                'organizationId' => (string)$row['organization_id'],
                'rfqNumber' => (string)$row['rfq_number'],
                'title' => (string)$row['title'],
                'description' => $row['description'] !== null ? (string)$row['description'] : null,
                'status' => (string)$row['status'],
                'dueDate' => $row['due_date'] !== null ? (string)$row['due_date'] : null,
                'requiredDate' => $row['required_date'] !== null ? (string)$row['required_date'] : null,
                'validUntil' => $row['valid_until'] !== null ? (string)$row['valid_until'] : null,
                'requiresSamples' => (bool)$row['requires_samples'],
                'requiresFirstArticle' => (bool)$row['requires_first_article'],
                'requiresQualityReport' => (bool)$row['requires_quality_report'],
                'allowPartialQuotes' => (bool)$row['allow_partial_quotes'],
                'releaseFilesGenerated' => (bool)$row['release_files_generated'],
                'releaseFilesGeneratedAt' => $row['release_files_generated_at'] !== null ? (string)$row['release_files_generated_at'] : null,
                'releaseFolderPath' => $row['release_folder_path'] !== null ? (string)$row['release_folder_path'] : null,
                'billingAddressId' => $row['billing_address_id'] !== null ? (string)$row['billing_address_id'] : null,
                'shippingAddressId' => $row['shipping_address_id'] !== null ? (string)$row['shipping_address_id'] : null,
                'shippingAddress' => $row['shipping_address'] !== null ? (string)$row['shipping_address'] : null,
                'shippingNotes' => $row['shipping_notes'] !== null ? (string)$row['shipping_notes'] : null,
                'incoterms' => $row['incoterms'] !== null ? (string)$row['incoterms'] : null,
                'internalNotes' => $row['internal_notes'] !== null ? (string)$row['internal_notes'] : null,
                'supplierNotes' => $row['supplier_notes'] !== null ? (string)$row['supplier_notes'] : null,
                'pdfUrl' => $row['pdf_url'] !== null ? (string)$row['pdf_url'] : null,
                'pdfGeneratedAt' => $row['pdf_generated_at'] !== null ? (string)$row['pdf_generated_at'] : null,
                'awardedSupplierId' => $row['awarded_supplier_id'] !== null ? (string)$row['awarded_supplier_id'] : null,
                'awardedAt' => $row['awarded_at'] !== null ? (string)$row['awarded_at'] : null,
                'awardedBy' => $row['awarded_by'] !== null ? (string)$row['awarded_by'] : null,
                'awardNotes' => $row['award_notes'] !== null ? (string)$row['award_notes'] : null,
                'createdAt' => (string)$row['created_at'],
                'createdBy' => $row['created_by'] !== null ? (string)$row['created_by'] : null,
                'createdByUser' => $row['creator_display_name'] !== null ? ['displayName' => (string)$row['creator_display_name']] : null,
                'updatedAt' => (string)$row['updated_at'],
                'updatedBy' => $row['updated_by'] !== null ? (string)$row['updated_by'] : null,
                'sentAt' => $row['sent_at'] !== null ? (string)$row['sent_at'] : null,
                'sentBy' => $row['sent_by'] !== null ? (string)$row['sent_by'] : null,
                'completedAt' => $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
                'itemCount' => (int)$row['item_count'],
                'supplierCount' => (int)$row['supplier_count'],
            ],
            'items' => $items,
            'suppliers' => $suppliers,
            'itemsPage' => $itemPage,
            'itemsLimit' => $itemLimit,
            'itemsHasMore' => $itemsHasMore,
            'suppliersPage' => $supplierPage,
            'suppliersLimit' => $supplierLimit,
            'suppliersHasMore' => $suppliersHasMore,
        ]);
    }

    /** @param array<string, mixed> $principal */
    private static function requireModuleAccess(PDO $db, array $principal): void
    {
        if (in_array($principal['role'], ['owner', 'admin'], true)) return;
        $restricted = $db->prepare('SELECT 1 FROM module_access WHERE organization_id = ? AND module_id = ? LIMIT 1');
        $restricted->execute([$principal['organizationId'], 'rfq']);
        if (!$restricted->fetchColumn()) return;

        $grant = $db->prepare(
            'SELECT 1 FROM module_access ma
             WHERE ma.organization_id = ? AND ma.module_id = ?
               AND (ma.user_id = ? OR EXISTS (
                    SELECT 1 FROM team_members tm
                    JOIN teams t ON t.id = tm.team_id AND t.organization_id = ma.organization_id
                    WHERE tm.user_id = ? AND tm.team_id = ma.team_id
               )) LIMIT 1'
        );
        $grant->execute([$principal['organizationId'], 'rfq', $principal['userId'], $principal['userId']]);
        if (!$grant->fetchColumn()) Runtime::respond(403, ['error' => 'MODULE_ACCESS_DENIED', 'message' => 'RFQ module access is restricted for this account.']);
    }

    private static function queryInteger(string $name, int $default, int $minimum, int $maximum): int
    {
        if (!array_key_exists($name, $_GET)) return $default;
        $value = $_GET[$name];
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => $name . ' must be an integer.']);
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        if (!is_int($parsed)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => $name . ' is outside the supported range.']);
        return $parsed;
    }

    /** @return list<array{name: string, path: string, size: int, storagePath?: string}> */
    private static function decodeAttachments(mixed $stored): array
    {
        if ($stored === null) return [];
        if (!is_string($stored)) throw new \UnexpectedValueException('RFQ attachment data is invalid.');

        try {
            $attachments = json_decode($stored, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \UnexpectedValueException('RFQ attachment data is invalid.');
        }
        if (!is_array($attachments) || !array_is_list($attachments)) {
            throw new \UnexpectedValueException('RFQ attachment data is invalid.');
        }

        $result = [];
        foreach ($attachments as $attachment) {
            if (!is_object($attachment)
                || !isset($attachment->name, $attachment->path, $attachment->size)
                || !is_string($attachment->name) || !is_string($attachment->path)
                || !is_int($attachment->size) || $attachment->size < 0) {
                throw new \UnexpectedValueException('RFQ attachment data is invalid.');
            }
            $value = ['name' => $attachment->name, 'path' => $attachment->path, 'size' => $attachment->size];
            if (property_exists($attachment, 'storage_path')) {
                if (!is_string($attachment->storage_path)) {
                    throw new \UnexpectedValueException('RFQ attachment data is invalid.');
                }
                $value['storagePath'] = $attachment->storage_path;
            }
            $result[] = $value;
        }
        return $result;
    }

}
