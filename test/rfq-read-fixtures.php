<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Runtime.php';

use BluePlm\Runtime;

$root = dirname(__DIR__);
$env = Runtime::env($root . '/.env');
if (($env['MARIADB_HOST'] ?? null) !== 'mariadb'
    || ($env['MARIADB_DATABASE'] ?? null) !== 'blueplm'
    || ($env['MARIADB_PASSWORD'] ?? null) !== 'blueplm-test-password') {
    throw new RuntimeException('RFQ fixtures require the isolated integration database.');
}

$orgId = getenv('BLUEPLM_RFQ_FIXTURE_ORG_ID');
$ownerId = getenv('BLUEPLM_RFQ_FIXTURE_OWNER_ID');
$memberId = getenv('BLUEPLM_RFQ_FIXTURE_MEMBER_ID');
$guestId = getenv('BLUEPLM_RFQ_FIXTURE_GUEST_ID');
foreach (['organization' => $orgId, 'owner' => $ownerId, 'member' => $memberId, 'guest' => $guestId] as $label => $id) {
    if (!is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id) !== 1) {
        throw new RuntimeException("RFQ fixture {$label} ID is invalid.");
    }
}

$db = Runtime::database($env);
$ids = [
    'foreignOrg' => Runtime::uuid(),
    'foreignSupplier' => Runtime::uuid(),
    'supplier' => Runtime::uuid(),
    'supplierDeclined' => Runtime::uuid(),
    'supplierPending' => Runtime::uuid(),
    'vaultAccessible' => Runtime::uuid(),
    'vaultPrivate' => Runtime::uuid(),
    'vaultForeign' => Runtime::uuid(),
    'fileAccessible' => Runtime::uuid(),
    'filePrivate' => Runtime::uuid(),
    'fileDeleted' => Runtime::uuid(),
    'fileForeign' => Runtime::uuid(),
    'rfqPrimary' => Runtime::uuid(),
    'rfqSecond' => Runtime::uuid(),
    'rfqForeign' => Runtime::uuid(),
    'team' => Runtime::uuid(),
    'itemAccessible' => Runtime::uuid(),
    'itemPrivate' => Runtime::uuid(),
    'itemDeleted' => Runtime::uuid(),
    'itemForeign' => Runtime::uuid(),
    'rfqSupplier' => Runtime::uuid(),
    'rfqSupplierDeclined' => Runtime::uuid(),
    'rfqSupplierPending' => Runtime::uuid(),
    'rfqForeignSupplier' => Runtime::uuid(),
];

$db->beginTransaction();
try {
    $db->prepare('INSERT INTO organizations (id, name, slug) VALUES (?, ?, ?)')
        ->execute([$ids['foreignOrg'], 'RFQ Foreign Fixture', 'rfq-' . substr(str_replace('-', '', $ids['foreignOrg']), 0, 20)]);
    $supplierInsert = $db->prepare('INSERT INTO suppliers (id, organization_id, name, code, contact_email, contact_name, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $supplierInsert->execute([$ids['supplier'], $orgId, 'RFQ Local Supplier', 'RFQ-LOCAL', 'quotes@rfq-local.example.test', 'Avery Quoter', $ownerId]);
    $supplierInsert->execute([$ids['supplierDeclined'], $orgId, 'RFQ Declined Supplier', 'RFQ-DECLINED', null, null, $ownerId]);
    $supplierInsert->execute([$ids['supplierPending'], $orgId, 'RFQ Pending Supplier', 'RFQ-PENDING', null, null, $ownerId]);
    $supplierInsert->execute([$ids['foreignSupplier'], $ids['foreignOrg'], 'RFQ FOREIGN SUPPLIER SECRET', 'RFQ-FOREIGN', 'secret@foreign.example.test', 'Foreign Contact Secret', $ownerId]);

    $db->prepare('INSERT INTO teams (id, organization_id, name) VALUES (?, ?, ?)')
        ->execute([$ids['team'], $orgId, 'RFQ Fixture Team']);
    $db->prepare('INSERT INTO team_members (team_id, user_id) VALUES (?, ?)')
        ->execute([$ids['team'], $memberId]);

    $vault = $db->prepare('INSERT INTO vaults (id, organization_id, name, network_root) VALUES (?, ?, ?, ?)');
    $vault->execute([$ids['vaultAccessible'], $orgId, 'RFQ Accessible Fixture Vault', 'Z:\\rfq-accessible']);
    $vault->execute([$ids['vaultPrivate'], $orgId, 'RFQ Private Fixture Vault', 'Z:\\rfq-private']);
    $vault->execute([$ids['vaultForeign'], $ids['foreignOrg'], 'RFQ Foreign Fixture Vault', 'Z:\\rfq-foreign']);
    $db->prepare('INSERT INTO vault_access (vault_id, user_id, granted_by) VALUES (?, ?, ?)')
        ->execute([$ids['vaultAccessible'], $memberId, $ownerId]);
    $db->prepare('INSERT INTO vault_access (vault_id, user_id, granted_by) VALUES (?, ?, ?)')
        ->execute([$ids['vaultAccessible'], $guestId, $ownerId]);
    $db->prepare('INSERT INTO team_vault_access (team_id, vault_id, granted_by) VALUES (?, ?, ?)')
        ->execute([$ids['team'], $ids['vaultPrivate'], $ownerId]);

    $file = $db->prepare(
        'INSERT INTO files (id, organization_id, vault_id, canonical_path, file_name, storage_relative_path, current_revision, state, part_number, description, revision_label, deleted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $file->execute([$ids['fileAccessible'], $orgId, $ids['vaultAccessible'], 'Parts/RFQ-ACCESS.sldprt', 'RFQ-ACCESS.sldprt', 'fixture/rfq-access.sldprt', 3, 'released', 'RFQ-ACCESS', 'Accessible file metadata', 'C', null]);
    $file->execute([$ids['filePrivate'], $orgId, $ids['vaultPrivate'], 'Parts/RFQ-PRIVATE.sldprt', 'RFQ-PRIVATE.sldprt', 'fixture/rfq-private.sldprt', 8, 'released', 'RFQ-PRIVATE-SECRET', 'Private vault metadata', 'R8', null]);
    $file->execute([$ids['fileDeleted'], $orgId, $ids['vaultAccessible'], 'Parts/RFQ-DELETED.sldprt', 'RFQ-DELETED.sldprt', 'fixture/rfq-deleted.sldprt', 1, 'released', 'RFQ-DELETED', 'Deleted file metadata', 'A', gmdate('Y-m-d H:i:s')]);
    $file->execute([$ids['fileForeign'], $ids['foreignOrg'], $ids['vaultForeign'], 'Parts/RFQ-FOREIGN.sldprt', 'RFQ-FOREIGN.sldprt', 'fixture/rfq-foreign.sldprt', 4, 'released', 'RFQ-FOREIGN-SECRET', 'Foreign organization metadata', 'D', null]);

    $rfq = $db->prepare(
        'INSERT INTO rfqs (id, organization_id, rfq_number, title, description, status, due_date, created_by, sent_at, sent_by, completed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $rfq->execute([$ids['rfqPrimary'], $orgId, 'RFQ-TEST-001', 'RFQ Fixture Primary', 'Normal RFQ details', 'awaiting_quote', '2026-11-01', $ownerId, '2026-10-01 10:11:12.123', $ownerId, null]);
    $rfq->execute([$ids['rfqSecond'], $orgId, 'RFQ-TEST-002', 'RFQ Fixture Second', null, 'completed', null, $ownerId, null, null, '2026-10-02 11:12:13.456']);
    $rfq->execute([$ids['rfqForeign'], $ids['foreignOrg'], 'RFQ-FOREIGN-001', 'RFQ FOREIGN SECRET', null, 'draft', null, null, null, null, null]);

    $item = $db->prepare(
        'INSERT INTO rfq_items (id, organization_id, rfq_id, line_number, file_id, part_number, description, revision, quantity, sw_configuration, step_storage_path, pdf_storage_path, attachments, created_at, updated_at, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $item->execute([$ids['itemAccessible'], $orgId, $ids['rfqPrimary'], 1, $ids['fileAccessible'], 'RFQ-ACCESS', 'Accessible part snapshot', 'C', 4, '  Mirror Left - 2x  ', 'rfq-release/step/access.step', null, json_encode([['name' => 'Spec.pdf', 'path' => 'Attachments/Spec.pdf', 'size' => 1234, 'storage_path' => 'rfq-attachments/spec.pdf']], JSON_THROW_ON_ERROR), '2026-10-03 08:09:10.111', '2026-10-04 09:10:11.222', 1]);
    $item->execute([$ids['itemPrivate'], $orgId, $ids['rfqPrimary'], 2, $ids['filePrivate'], 'RFQ-PRIVATE', 'Private part snapshot', 'R8', 2, 'Alternate Fixture Config', null, 'rfq-release/pdf/private.pdf', json_encode([['name' => 'No storage key.txt', 'path' => 'Attachments/No storage key.txt', 'size' => 17]], JSON_THROW_ON_ERROR), '2026-10-05 10:11:12.333', '2026-10-06 11:12:13.444', 2]);
    $item->execute([$ids['itemDeleted'], $orgId, $ids['rfqPrimary'], 3, $ids['fileDeleted'], 'RFQ-DELETED', 'Deleted part snapshot', 'A', 1, null, null, null, '[]', '2026-10-07 12:13:14.555', '2026-10-08 13:14:15.666', 3]);
    $item->execute([$ids['itemForeign'], $orgId, $ids['rfqPrimary'], 4, $ids['fileForeign'], 'RFQ-FOREIGN', 'Foreign file snapshot', 'D', 1, null, null, null, null, '2026-10-09 14:15:16.777', '2026-10-10 15:16:17.888', 4]);

    $association = $db->prepare(
        'INSERT INTO rfq_suppliers (id, organization_id, rfq_id, supplier_id, sent_at, viewed_at, responded_at, quoted_at, declined_at, declined_reason, response_status, total_quoted_amount, currency, lead_time_days, is_selected, selected_at, selected_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $association->execute([$ids['rfqSupplier'], $orgId, $ids['rfqPrimary'], $ids['supplier'], '2026-10-11 10:11:12.123', '2026-10-11 11:12:13.234', '2026-10-12 12:13:14.345', '2026-10-13 13:14:15.456', null, null, 'quoted', '123456789012.340005', 'EUR', 37, 1, '2026-10-14 14:15:16.567', $ownerId, 'Fixture quote notes']);
    $association->execute([$ids['rfqSupplierDeclined'], $orgId, $ids['rfqPrimary'], $ids['supplierDeclined'], '2026-10-11 10:11:12.123', null, '2026-10-15 15:16:17.678', null, '2026-10-15 15:16:17.678', 'Capacity unavailable', 'declined', null, 'USD', null, 0, null, null, null]);
    $association->execute([$ids['rfqSupplierPending'], $orgId, $ids['rfqPrimary'], $ids['supplierPending'], null, null, null, null, null, null, 'pending', null, 'USD', null, 0, null, null, null]);
    $association->execute([$ids['rfqForeignSupplier'], $orgId, $ids['rfqPrimary'], $ids['foreignSupplier'], null, null, null, null, null, null, 'pending', null, 'USD', null, 0, null, null, null]);

    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}

fwrite(STDOUT, json_encode($ids, JSON_THROW_ON_ERROR) . "\n");
