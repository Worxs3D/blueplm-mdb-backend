<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use PDOException;

final class RFQWrites
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';
    private const MAX_JSON_BODY_BYTES = 262144;

    /** @param array<string, mixed> $principal */
    public static function dispatch(PDO $db, array $principal, string $method, string $path): void
    {
        if ($path === '/rfqs' && $method === 'POST') {
            self::create($db, $principal);
        }
        if (preg_match('#^/rfqs/([^/]+)/suppliers(?:/([^/]+))?$#D', $path, $matches) === 1
            && in_array($method, ['POST', 'DELETE'], true)) {
            $rfqId = self::pathUuid($matches[1], 'RFQ');
            $associationId = isset($matches[2]) ? self::pathUuid($matches[2], 'RFQ supplier') : null;
            if ($method === 'POST' && $associationId === null) self::addSupplier($db, $principal, $rfqId);
            if ($method === 'DELETE' && $associationId !== null) self::removeSupplier($db, $principal, $rfqId, $associationId);
            self::respondError($db, 404, 'NOT_FOUND', 'RFQ supplier route not found.');
        }
        if (preg_match('#^/rfqs/([^/]+)/items(?:/([^/]+))?$#D', $path, $matches) === 1
            && in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
            $rfqId = self::pathUuid($matches[1], 'RFQ');
            $itemId = isset($matches[2]) ? self::pathUuid($matches[2], 'RFQ item') : null;
            if ($method === 'POST' && $itemId === null) self::createItem($db, $principal, $rfqId);
            if ($method === 'PATCH' && $itemId !== null) self::updateItem($db, $principal, $rfqId, $itemId);
            if ($method === 'DELETE' && $itemId !== null) self::deleteItem($db, $principal, $rfqId, $itemId);
            self::respondError($db, 404, 'NOT_FOUND', 'RFQ route not found.');
        }
        if (preg_match('#^/rfqs/([^/]+)$#D', $path, $matches) === 1 && $method === 'PATCH') {
            self::update($db, $principal, self::pathUuid($matches[1], 'RFQ'));
        }
    }

    /** @param array<string, mixed> $principal */
    private static function create(PDO $db, array $principal): never
    {
        self::requireModuleAccess($db, $principal);
        $body = self::body(self::createFields());
        if (!isset($body['title'])) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'title is required.']);
        }
        $fields = self::validateHeader($body);
        $db->beginTransaction();
        try {
            self::validateAddressIds($db, $principal['organizationId'], $fields);
            $rfqNumber = self::nextRfqNumber($db, $principal['organizationId']);
            $id = Runtime::uuid();
            $columns = ['id', 'organization_id', 'rfq_number', 'title', 'created_by', 'updated_by'];
            $values = [$id, $principal['organizationId'], $rfqNumber, $fields['title'], $principal['userId'], $principal['userId']];
            foreach ($fields as $column => $value) {
                if ($column === 'title') continue;
                $columns[] = $column;
                $values[] = $value;
            }
            $sql = 'INSERT INTO rfqs (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $db->prepare($sql)->execute($values);
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'RFQ_NUMBER_CONFLICT', 'message' => 'RFQ number is already in use.']);
            throw $error;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(201, ['rfq' => ['id' => $id, 'rfqNumber' => $rfqNumber, 'title' => $fields['title'], 'description' => $fields['description'] ?? null, 'status' => 'draft']]);
    }

    /** @param array<string, mixed> $principal */
    private static function update(PDO $db, array $principal, string $rfqId): never
    {
        self::requireModuleAccess($db, $principal);
        $body = self::body(self::draftUpdateFields());
        if ($body === []) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'At least one RFQ field is required.']);
        $fields = self::validateHeader($body);
        $db->beginTransaction();
        try {
            self::lockDraft($db, $principal['organizationId'], $rfqId);
            self::validateAddressIds($db, $principal['organizationId'], $fields);
            $assignments = [];
            $values = [];
            foreach ($fields as $column => $value) {
                $assignments[] = $column . ' = ?';
                $values[] = $value;
            }
            $assignments[] = 'updated_by = ?';
            $values[] = $principal['userId'];
            $values[] = $rfqId;
            $values[] = $principal['organizationId'];
            $db->prepare('UPDATE rfqs SET ' . implode(', ', $assignments) . ' WHERE id = ? AND organization_id = ?')->execute($values);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(200, ['rfq' => ['id' => $rfqId, 'status' => 'draft', 'updated' => array_keys($fields)]]);
    }

    /** @param array<string, mixed> $principal */
    private static function createItem(PDO $db, array $principal, string $rfqId): never
    {
        self::requireModuleAccess($db, $principal);
        $body = self::body(self::itemCreateFields());
        $fileId = self::requiredUuid($body, 'fileId');
        $fields = self::validateItem($body);
        $db->beginTransaction();
        try {
            self::lockDraft($db, $principal['organizationId'], $rfqId);
            $source = self::sourceFile($db, $principal, $fileId);
            $next = $db->prepare('SELECT COALESCE(MAX(line_number), 0) + 1 FROM rfq_items WHERE organization_id = ? AND rfq_id = ?');
            $next->execute([$principal['organizationId'], $rfqId]);
            $lineNumber = (int)$next->fetchColumn();
            if ($lineNumber < 1 || $lineNumber > 4294967295) throw new \RuntimeException('RFQ line number is outside the supported range.');
            $id = Runtime::uuid();
            unset($fields['file_id']);
            $columns = ['id', 'organization_id', 'rfq_id', 'line_number', 'file_id', 'part_number', 'description', 'revision', 'sort_order'];
            $values = [$id, $principal['organizationId'], $rfqId, $lineNumber, $fileId, $source['part_number'], $source['description'], $source['revision_label'], $lineNumber];
            foreach ($fields as $column => $value) { $columns[] = $column; $values[] = $value; }
            $sql = 'INSERT INTO rfq_items (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $db->prepare($sql)->execute($values);
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'RFQ_ITEM_CONFLICT', 'message' => 'RFQ item conflicts with an existing line.']);
            throw $error;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        $itemQuery = $db->prepare('SELECT id, rfq_id, line_number, file_id, part_number, description, revision, quantity, uom, material, finish, notes, tolerance_class, special_requirements, sw_configuration, sort_order FROM rfq_items WHERE id = ? AND rfq_id = ? AND organization_id = ?');
        $itemQuery->execute([$id, $rfqId, $principal['organizationId']]);
        $row = $itemQuery->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('The inserted RFQ item could not be read back.');
        Runtime::respond(201, ['item' => self::itemResponse($row)]);
    }

    /** @param array<string, mixed> $principal */
    private static function updateItem(PDO $db, array $principal, string $rfqId, string $itemId): never
    {
        self::requireModuleAccess($db, $principal);
        $body = self::body(self::itemUpdateFields());
        if ($body === []) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'At least one RFQ item field is required.']);
        $fields = self::validateItem($body);
        $db->beginTransaction();
        try {
            self::lockDraft($db, $principal['organizationId'], $rfqId);
            $lock = $db->prepare('SELECT id, file_id FROM rfq_items WHERE id = ? AND rfq_id = ? AND organization_id = ? FOR UPDATE');
            $lock->execute([$itemId, $rfqId, $principal['organizationId']]);
            $existing = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$existing) self::respondError($db, 404, 'NOT_FOUND', 'RFQ item not found.');
            $sourceFileId = $existing['file_id'];
            if (!is_string($sourceFileId)) self::respondError($db, 409, 'SOURCE_FILE_REQUIRED', 'This RFQ item has no persisted source file.');
            self::sourceFile($db, $principal, $sourceFileId);
            $assignments = [];
            $values = [];
            foreach ($fields as $column => $value) { $assignments[] = $column . ' = ?'; $values[] = $value; }
            $values[] = $itemId; $values[] = $rfqId; $values[] = $principal['organizationId'];
            $db->prepare('UPDATE rfq_items SET ' . implode(', ', $assignments) . ' WHERE id = ? AND rfq_id = ? AND organization_id = ?')->execute($values);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        $itemQuery = $db->prepare('SELECT id, rfq_id, line_number, file_id, part_number, description, revision, quantity, uom, material, finish, notes, tolerance_class, special_requirements, sw_configuration, sort_order FROM rfq_items WHERE id = ? AND rfq_id = ? AND organization_id = ?');
        $itemQuery->execute([$itemId, $rfqId, $principal['organizationId']]);
        $row = $itemQuery->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('The updated RFQ item could not be read back.');
        Runtime::respond(200, ['item' => self::itemResponse($row)]);
    }

    /** @param array<string, mixed> $principal */
    private static function deleteItem(PDO $db, array $principal, string $rfqId, string $itemId): never
    {
        self::requireModuleAccess($db, $principal);
        $db->beginTransaction();
        try {
            self::lockDraft($db, $principal['organizationId'], $rfqId);
            $lock = $db->prepare('SELECT id FROM rfq_items WHERE id = ? AND rfq_id = ? AND organization_id = ? FOR UPDATE');
            $lock->execute([$itemId, $rfqId, $principal['organizationId']]);
            if (!$lock->fetchColumn()) self::respondError($db, 404, 'NOT_FOUND', 'RFQ item not found.');
            $db->prepare('DELETE FROM rfq_items WHERE id = ? AND rfq_id = ? AND organization_id = ?')->execute([$itemId, $rfqId, $principal['organizationId']]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(204);
    }

    /** @param array<string, mixed> $principal */
    private static function addSupplier(PDO $db, array $principal, string $rfqId): never
    {
        self::requireModuleAccess($db, $principal);
        $body = self::body(['supplierId']);
        $supplierId = self::requiredUuid($body, 'supplierId');
        $db->beginTransaction();
        try {
            $rfqLock = $db->prepare('SELECT id, status, release_files_generated FROM rfqs WHERE id = ? AND organization_id = ? FOR UPDATE');
            $rfqLock->execute([$rfqId, $principal['organizationId']]);
            $rfq = $rfqLock->fetch(PDO::FETCH_ASSOC);
            if (!$rfq) self::respondError($db, 404, 'NOT_FOUND', 'RFQ not found.');

            $supplierQuery = $db->prepare('SELECT id FROM suppliers WHERE id = ? AND organization_id = ? AND is_active = TRUE FOR UPDATE');
            $supplierQuery->execute([$supplierId, $principal['organizationId']]);
            if (!$supplierQuery->fetchColumn()) self::respondError($db, 404, 'NOT_FOUND', 'Supplier not found.');

            $countQuery = $db->prepare('SELECT COUNT(*) FROM rfq_suppliers WHERE organization_id = ? AND rfq_id = ?');
            $countQuery->execute([$principal['organizationId'], $rfqId]);
            $previousCount = (int)$countQuery->fetchColumn();
            $associationId = Runtime::uuid();
            $db->prepare('INSERT INTO rfq_suppliers (id, organization_id, rfq_id, supplier_id) VALUES (?, ?, ?, ?)')
                ->execute([$associationId, $principal['organizationId'], $rfqId, $supplierId]);

            $status = (string)$rfq['status'];
            if ((bool)$rfq['release_files_generated'] && $previousCount === 0) {
                $db->prepare('UPDATE rfqs SET status = \'ready\' WHERE id = ? AND organization_id = ?')
                    ->execute([$rfqId, $principal['organizationId']]);
                $status = 'ready';
            }
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error->getCode() === '23000') self::respondError($db, 409, 'RFQ_SUPPLIER_CONFLICT', 'This supplier is already assigned to the RFQ.');
            throw $error;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        $readback = self::rfqSupplierQuery($db, $principal['organizationId'], $rfqId, $associationId);
        if (!$readback) throw new \RuntimeException('The inserted RFQ supplier could not be read back.');
        Runtime::respond(201, ['rfqSupplier' => self::rfqSupplierResponse($readback), 'rfq' => ['id' => $rfqId, 'status' => $status]]);
    }

    /** @param array<string, mixed> $principal */
    private static function removeSupplier(PDO $db, array $principal, string $rfqId, string $associationId): never
    {
        self::requireModuleAccess($db, $principal);
        $db->beginTransaction();
        try {
            $rfqLock = $db->prepare('SELECT id FROM rfqs WHERE id = ? AND organization_id = ? FOR UPDATE');
            $rfqLock->execute([$rfqId, $principal['organizationId']]);
            if (!$rfqLock->fetchColumn()) self::respondError($db, 404, 'NOT_FOUND', 'RFQ not found.');

            $association = $db->prepare('SELECT id, sent_at FROM rfq_suppliers WHERE id = ? AND rfq_id = ? AND organization_id = ? FOR UPDATE');
            $association->execute([$associationId, $rfqId, $principal['organizationId']]);
            $row = $association->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['sent_at'] !== null) self::respondError($db, 409, 'RFQ_SUPPLIER_ALREADY_SENT', 'A sent RFQ supplier cannot be removed.');
            if ($row) {
                $db->prepare('DELETE FROM rfq_suppliers WHERE id = ? AND rfq_id = ? AND organization_id = ?')
                    ->execute([$associationId, $rfqId, $principal['organizationId']]);
            }
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(204);
    }

    /** @return array<string, mixed>|false */
    private static function rfqSupplierQuery(PDO $db, string $organizationId, string $rfqId, string $associationId): array|false
    {
        $query = $db->prepare(
            'SELECT rs.id, rs.rfq_id, rs.supplier_id, rs.sent_at, rs.sent_by, rs.sent_via,
                    rs.viewed_at, rs.responded_at, rs.quoted_at, rs.declined_at, rs.declined_reason,
                    rs.response_status, rs.total_quoted_amount, rs.currency, rs.lead_time_days,
                    rs.is_selected, rs.selected_at, rs.selected_by, rs.notes, rs.created_at,
                    s.id AS linked_supplier_id, s.name AS linked_supplier_name,
                    s.code AS linked_supplier_code, s.contact_email AS linked_supplier_contact_email,
                    s.contact_name AS linked_supplier_contact_name
             FROM rfq_suppliers rs
             JOIN suppliers s ON s.id = rs.supplier_id AND s.organization_id = rs.organization_id
             WHERE rs.organization_id = ? AND rs.rfq_id = ? AND rs.id = ?'
        );
        $query->execute([$organizationId, $rfqId, $associationId]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function rfqSupplierResponse(array $row): array
    {
        return [
            'id' => (string)$row['id'], 'rfqId' => (string)$row['rfq_id'], 'supplierId' => (string)$row['supplier_id'],
            'sentAt' => $row['sent_at'] !== null ? (string)$row['sent_at'] : null,
            'sentBy' => $row['sent_by'] !== null ? (string)$row['sent_by'] : null,
            'sentVia' => $row['sent_via'] !== null ? (string)$row['sent_via'] : null,
            'viewedAt' => $row['viewed_at'] !== null ? (string)$row['viewed_at'] : null,
            'respondedAt' => $row['responded_at'] !== null ? (string)$row['responded_at'] : null,
            'quotedAt' => $row['quoted_at'] !== null ? (string)$row['quoted_at'] : null,
            'declinedAt' => $row['declined_at'] !== null ? (string)$row['declined_at'] : null,
            'declinedReason' => $row['declined_reason'] !== null ? (string)$row['declined_reason'] : null,
            'responseStatus' => (string)$row['response_status'],
            'totalQuotedAmount' => $row['total_quoted_amount'] !== null ? (string)$row['total_quoted_amount'] : null,
            'currency' => (string)$row['currency'],
            'leadTimeDays' => $row['lead_time_days'] !== null ? (int)$row['lead_time_days'] : null,
            'isSelected' => (bool)$row['is_selected'],
            'selectedAt' => $row['selected_at'] !== null ? (string)$row['selected_at'] : null,
            'selectedBy' => $row['selected_by'] !== null ? (string)$row['selected_by'] : null,
            'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
            'createdAt' => (string)$row['created_at'],
            'supplier' => [
                'id' => (string)$row['linked_supplier_id'], 'name' => (string)$row['linked_supplier_name'],
                'code' => $row['linked_supplier_code'] !== null ? (string)$row['linked_supplier_code'] : null,
                'contactEmail' => $row['linked_supplier_contact_email'] !== null ? (string)$row['linked_supplier_contact_email'] : null,
                'contactName' => $row['linked_supplier_contact_name'] !== null ? (string)$row['linked_supplier_contact_name'] : null,
            ],
        ];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    private static function sourceFile(PDO $db, array $principal, string $fileId): array
    {
        $query = $db->prepare('SELECT id, vault_id, part_number, description, revision_label FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
        $query->execute([$fileId, $principal['organizationId']]);
        $file = $query->fetch(PDO::FETCH_ASSOC);
        if (!$file) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Source file not found.']);
        Runtime::requireVault($db, $principal, (string)$file['vault_id']);
        return $file;
    }

    private static function nextRfqNumber(PDO $db, string $organizationId): string
    {
        $year = (int)$db->query('SELECT YEAR(NOW())')->fetchColumn();
        $organizationLock = $db->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE');
        $organizationLock->execute([$organizationId]);
        if (!$organizationLock->fetchColumn()) throw new \RuntimeException('RFQ organization could not be locked.');
        $seed = $db->prepare(
            'INSERT INTO rfq_number_counters (organization_id, sequence_year, last_number)
             VALUES (?, ?, (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(rfq_number, \'-\', -1) AS UNSIGNED)), 0)
                            FROM rfqs WHERE organization_id = ? AND rfq_number REGEXP CONCAT(\'^RFQ-\', ?, \'-[0-9]+$\')))
             ON DUPLICATE KEY UPDATE last_number = last_number'
        );
        $seed->execute([$organizationId, $year, $organizationId, $year]);

        $lock = $db->prepare('SELECT last_number FROM rfq_number_counters WHERE organization_id = ? AND sequence_year = ? FOR UPDATE');
        $lock->execute([$organizationId, $year]);
        $current = $lock->fetchColumn();
        if (!is_string($current) && !is_int($current)) throw new \RuntimeException('RFQ number counter could not be read.');
        $next = self::incrementCounter((string)$current);
        if ($next === null) self::respondError($db, 409, 'RFQ_NUMBER_EXHAUSTED', 'RFQ number sequence is exhausted for this year.');
        $update = $db->prepare('UPDATE rfq_number_counters SET last_number = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE organization_id = ? AND sequence_year = ?');
        $update->execute([$next, $organizationId, $year]);
        return 'RFQ-' . $year . '-' . (strlen($next) < 4 ? str_pad($next, 4, '0', STR_PAD_LEFT) : $next);
    }

    private static function incrementCounter(string $value): ?string
    {
        if (preg_match('/^[0-9]+$/D', $value) !== 1) throw new \RuntimeException('RFQ number counter is invalid.');
        $maximum = '18446744073709551615';
        $comparison = strlen($value) < strlen($maximum) ? -1 : (strlen($value) > strlen($maximum) ? 1 : strcmp($value, $maximum));
        if ($comparison >= 0) return null;
        $digits = str_split($value);
        for ($index = count($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string)((int)$digits[$index] + 1);
                return ltrim(implode('', $digits), '0') ?: '0';
            }
            $digits[$index] = '0';
        }
        return '1' . implode('', $digits);
    }

    /** @param array<string, mixed> $fields */
    private static function validateAddressIds(PDO $db, string $organizationId, array $fields): void
    {
        foreach (['billing_address_id' => 'billing', 'shipping_address_id' => 'shipping'] as $column => $type) {
            if (!array_key_exists($column, $fields) || $fields[$column] === null) continue;
            $query = $db->prepare('SELECT 1 FROM organization_addresses WHERE id = ? AND organization_id = ? AND address_type = ? LIMIT 1');
            $query->execute([$fields[$column], $organizationId, $type]);
            if (!$query->fetchColumn()) self::respondError($db, 400, 'INVALID_REQUEST', $type . ' address must belong to this organization and address type.');
        }
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    private static function lockDraft(PDO $db, string $organizationId, string $rfqId): array
    {
        $query = $db->prepare('SELECT id, status FROM rfqs WHERE id = ? AND organization_id = ? FOR UPDATE');
        $query->execute([$rfqId, $organizationId]);
        $rfq = $query->fetch(PDO::FETCH_ASSOC);
        if (!$rfq) self::respondError($db, 404, 'NOT_FOUND', 'RFQ not found.');
        if ($rfq['status'] !== 'draft') self::respondError($db, 409, 'RFQ_NOT_DRAFT', 'Only draft RFQs can be changed.');
        return $rfq;
    }

    /** @param array<string, mixed> $principal */
    private static function requireModuleAccess(PDO $db, array $principal): void
    {
        if (in_array($principal['role'], ['owner', 'admin'], true)) return;
        $restricted = $db->prepare('SELECT 1 FROM module_access WHERE organization_id = ? AND module_id = ? LIMIT 1');
        $restricted->execute([$principal['organizationId'], 'rfq']);
        if (!$restricted->fetchColumn()) return;
        $grant = $db->prepare(
            'SELECT 1 FROM module_access ma WHERE ma.organization_id = ? AND ma.module_id = ?
             AND (ma.user_id = ? OR EXISTS (SELECT 1 FROM team_members tm JOIN teams t ON t.id = tm.team_id AND t.organization_id = ma.organization_id
             WHERE tm.user_id = ? AND tm.team_id = ma.team_id)) LIMIT 1'
        );
        $grant->execute([$principal['organizationId'], 'rfq', $principal['userId'], $principal['userId']]);
        if (!$grant->fetchColumn()) Runtime::respond(403, ['error' => 'MODULE_ACCESS_DENIED', 'message' => 'RFQ module access is restricted for this account.']);
    }

    /** @return list<string> */
    private static function createFields(): array
    {
        return ['title', 'description'];
    }

    /** @return list<string> */
    private static function draftUpdateFields(): array
    {
        return ['billingAddressId', 'shippingAddressId', 'requiresSamples', 'requiresFirstArticle', 'requiresQualityReport'];
    }

    /** @return list<string> */
    private static function itemCreateFields(): array
    {
        return ['fileId', 'quantity'];
    }

    /** @return list<string> */
    private static function itemUpdateFields(): array
    {
        return ['quantity', 'material', 'finish', 'notes', 'toleranceClass', 'swConfiguration'];
    }

    /** @param list<string> $allowed @return array<string, mixed> */
    private static function body(array $allowed): array
    {
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (is_string($contentLength) && ctype_digit($contentLength) && (int)$contentLength > self::MAX_JSON_BODY_BYTES) {
            Runtime::respond(413, ['error' => 'REQUEST_TOO_LARGE', 'message' => 'JSON request body exceeds the supported size.']);
        }
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_JSON_BODY_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_JSON_BODY_BYTES) {
            Runtime::respond(413, ['error' => 'REQUEST_TOO_LARGE', 'message' => 'JSON request body exceeds the supported size.']);
        }
        try {
            if (trim($raw) === '') $body = [];
            else {
                $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($body)) throw new \InvalidArgumentException('A JSON object is required.');
            }
        }
        catch (\JsonException|\InvalidArgumentException) { Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A JSON object body is required.']); }
        if ($body === [] || array_is_list($body)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A non-empty JSON object body is required.']);
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown !== []) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Unsupported field: ' . (string)reset($unknown) . '.']);
        return $body;
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function validateHeader(array $body): array
    {
        $map = ['title' => 'title', 'description' => 'description', 'requiresSamples' => 'requires_samples',
            'requiresFirstArticle' => 'requires_first_article', 'requiresQualityReport' => 'requires_quality_report',
            'billingAddressId' => 'billing_address_id', 'shippingAddressId' => 'shipping_address_id'];
        $stringLimits = ['title' => 255, 'description' => 65535];
        $boolFields = ['requiresSamples', 'requiresFirstArticle', 'requiresQualityReport'];
        $result = [];
        foreach ($body as $key => $value) {
            if (in_array($key, $boolFields, true)) {
                if (!is_bool($value)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => $key . ' must be a boolean.']);
                $result[$map[$key]] = $value ? 1 : 0;
            } elseif (str_ends_with($key, 'AddressId')) {
                if ($value !== null && (!is_string($value) || preg_match(self::UUID, $value) !== 1)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => $key . ' must be a UUID or null.']);
                $result[$map[$key]] = $value !== null ? strtolower($value) : null;
            } else {
                $limit = $stringLimits[$key];
                if ($key === 'title') {
                    if (!is_string($value) || trim($value) === '' || strlen($value) > $limit || str_contains($value, "\0")) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'title is required and must fit its supported length.']);
                    $result[$map[$key]] = trim($value);
                } else {
                    if ($value !== null && (!is_string($value) || strlen($value) > $limit || str_contains($value, "\0"))) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => $key . ' must be a string within its supported length or null.']);
                    $normalized = is_string($value) ? trim($value) : $value;
                    $result[$map[$key]] = $normalized === '' ? null : $normalized;
                }
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function validateItem(array $body): array
    {
        $map = ['fileId' => 'file_id', 'quantity' => 'quantity', 'material' => 'material', 'finish' => 'finish', 'notes' => 'notes',
            'toleranceClass' => 'tolerance_class', 'swConfiguration' => 'sw_configuration'];
        $limits = ['material' => 512, 'finish' => 512, 'notes' => 65535, 'toleranceClass' => 128, 'swConfiguration' => 65535];
        $result = [];
        foreach ($body as $key => $value) {
            if ($key === 'fileId') {
                if (!is_string($value) || preg_match(self::UUID, $value) !== 1) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'fileId must be a UUID.']);
                $result[$map[$key]] = strtolower($value);
            } elseif ($key === 'quantity') {
                if (!is_int($value) || $value < 1 || $value > 4294967295) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'quantity must be a positive supported integer.']);
                $result[$map[$key]] = $value;
            } else {
                if ($value !== null && (!is_string($value) || strlen($value) > $limits[$key] || str_contains($value, "\0"))) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => $key . ' must be a string within its supported length or null.']);
                $result[$map[$key]] = $value === '' ? null : $value;
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $body */
    private static function requiredUuid(array $body, string $key): string
    {
        if (!isset($body[$key]) || !is_string($body[$key]) || preg_match(self::UUID, $body[$key]) !== 1) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => $key . ' must be a UUID.']);
        return strtolower($body[$key]);
    }

    private static function pathUuid(string $value, string $label): string
    {
        $id = rawurldecode($value);
        if (preg_match(self::UUID, $id) !== 1) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => $label . ' ID must be a UUID.']);
        return strtolower($id);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function itemResponse(array $row): array
    {
        return ['id' => (string)$row['id'], 'rfqId' => (string)$row['rfq_id'], 'lineNumber' => (int)$row['line_number'], 'fileId' => (string)$row['file_id'],
            'partNumber' => $row['part_number'] !== null ? (string)$row['part_number'] : null, 'description' => $row['description'] !== null ? (string)$row['description'] : null,
            'revision' => $row['revision'] !== null ? (string)$row['revision'] : null, 'quantity' => (int)$row['quantity'], 'uom' => (string)$row['uom'],
            'material' => $row['material'] !== null ? (string)$row['material'] : null, 'finish' => $row['finish'] !== null ? (string)$row['finish'] : null,
            'notes' => $row['notes'] !== null ? (string)$row['notes'] : null, 'toleranceClass' => $row['tolerance_class'] !== null ? (string)$row['tolerance_class'] : null,
            'specialRequirements' => $row['special_requirements'] !== null ? (string)$row['special_requirements'] : null, 'swConfiguration' => $row['sw_configuration'] !== null ? (string)$row['sw_configuration'] : null,
            'sortOrder' => (int)$row['sort_order']];
    }

    private static function respondError(PDO $db, int $status, string $error, string $message): never
    {
        if ($db->inTransaction()) $db->rollBack();
        Runtime::respond($status, ['error' => $error, 'message' => $message]);
    }
}
