<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Runtime.php';

use BluePlm\Runtime;

$root = dirname(__DIR__);
$env = Runtime::env($root . '/.env');
if (($env['MARIADB_HOST'] ?? null) !== 'mariadb'
    || ($env['MARIADB_DATABASE'] ?? null) !== 'blueplm'
    || ($env['MARIADB_PASSWORD'] ?? null) !== 'blueplm-test-password') {
    throw new RuntimeException('RFQ write fixtures require the isolated integration database.');
}

$organizationId = getenv('BLUEPLM_RFQ_WRITE_FIXTURE_ORG_ID');
$ownerId = getenv('BLUEPLM_RFQ_WRITE_FIXTURE_OWNER_ID');
$memberId = getenv('BLUEPLM_RFQ_WRITE_FIXTURE_MEMBER_ID');
foreach (['organization' => $organizationId, 'owner' => $ownerId, 'member' => $memberId] as $label => $id) {
    if (!is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id) !== 1) {
        throw new RuntimeException("RFQ write fixture {$label} ID is invalid.");
    }
}

$db = Runtime::database($env);
$ids = [
    'foreignOrg' => Runtime::uuid(), 'vaultAccessible' => Runtime::uuid(), 'vaultPrivate' => Runtime::uuid(), 'vaultForeign' => Runtime::uuid(),
    'fileAccessible' => Runtime::uuid(), 'filePrivate' => Runtime::uuid(), 'fileForeign' => Runtime::uuid(), 'fileDeleted' => Runtime::uuid(),
    'foreignAddress' => Runtime::uuid(), 'billingAddress' => Runtime::uuid(), 'shippingAddress' => Runtime::uuid(),
    'draftRfq' => Runtime::uuid(), 'sentRfq' => Runtime::uuid(), 'foreignRfq' => Runtime::uuid(), 'existingItem' => Runtime::uuid(),
    'activeUnapprovedSupplier' => Runtime::uuid(), 'inactiveSupplier' => Runtime::uuid(), 'foreignSupplier' => Runtime::uuid(),
    'sentAssociation' => Runtime::uuid(), 'releasedEmptyRfq' => Runtime::uuid(), 'releasedHasSupplierRfq' => Runtime::uuid(),
    'unreleasedRfq' => Runtime::uuid(), 'teamRfq' => Runtime::uuid(), 'parallelRfq' => Runtime::uuid(),
    'preexistingSupplier' => Runtime::uuid(), 'parallelSupplier' => Runtime::uuid(),
    'foreignSupplierAssociation' => Runtime::uuid(),
];
$db->beginTransaction();
try {
    $db->prepare('INSERT INTO organizations (id, name, slug) VALUES (?, ?, ?)')->execute([
        $ids['foreignOrg'], 'RFQ Write Foreign Fixture', 'rfqw-' . substr(str_replace('-', '', $ids['foreignOrg']), 0, 20),
    ]);
    $db->prepare('INSERT INTO organization_memberships (organization_id, user_id, role) VALUES (?, ?, \'owner\')')
        ->execute([$ids['foreignOrg'], $ownerId]);
    $vault = $db->prepare('INSERT INTO vaults (id, organization_id, name, network_root) VALUES (?, ?, ?, ?)');
    $vault->execute([$ids['vaultAccessible'], $organizationId, 'RFQ Write Accessible Vault', 'Z:\\rfq-write-accessible']);
    $vault->execute([$ids['vaultPrivate'], $organizationId, 'RFQ Write Private Vault', 'Z:\\rfq-write-private']);
    $vault->execute([$ids['vaultForeign'], $ids['foreignOrg'], 'RFQ Write Foreign Vault', 'Z:\\rfq-write-foreign']);
    $db->prepare('INSERT INTO vault_access (vault_id, user_id, granted_by) VALUES (?, ?, ?)')->execute([$ids['vaultAccessible'], $memberId, $ownerId]);
    $file = $db->prepare('INSERT INTO files (id, organization_id, vault_id, canonical_path, file_name, storage_relative_path, current_revision, state, part_number, description, revision_label, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)');
    $file->execute([$ids['fileAccessible'], $organizationId, $ids['vaultAccessible'], 'Parts/RFQ-WRITE-A.sldprt', 'RFQ-WRITE-A.sldprt', 'fixture/rfq-write-a.sldprt', 2, 'released', 'PART-A', 'Persisted part A', 'B']);
    $file->execute([$ids['filePrivate'], $organizationId, $ids['vaultPrivate'], 'Parts/RFQ-WRITE-PRIVATE.sldprt', 'RFQ-WRITE-PRIVATE.sldprt', 'fixture/rfq-write-private.sldprt', 1, 'released', 'PRIVATE-PART', 'Private vault part', 'A']);
    $file->execute([$ids['fileForeign'], $ids['foreignOrg'], $ids['vaultForeign'], 'Parts/RFQ-WRITE-FOREIGN.sldprt', 'RFQ-WRITE-FOREIGN.sldprt', 'fixture/rfq-write-foreign.sldprt', 1, 'released', 'FOREIGN-PART', 'Foreign organization part', 'A']);
    $db->prepare('INSERT INTO files (id, organization_id, vault_id, canonical_path, file_name, storage_relative_path, current_revision, state, part_number, description, revision_label, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3))')
        ->execute([$ids['fileDeleted'], $organizationId, $ids['vaultAccessible'], 'Parts/RFQ-WRITE-DELETED.sldprt', 'RFQ-WRITE-DELETED.sldprt', 'fixture/rfq-write-deleted.sldprt', 1, 'released', 'DELETED-PART', 'Deleted source part', 'A']);

    $address = $db->prepare('INSERT INTO organization_addresses (id, organization_id, address_type, label, address_line1, city, country) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $address->execute([$ids['billingAddress'], $organizationId, 'billing', 'RFQ Write Billing Fixture', '1 Test Road', 'Test City', 'DE']);
    $address->execute([$ids['shippingAddress'], $organizationId, 'shipping', 'RFQ Write Shipping Fixture', '2 Test Road', 'Test City', 'DE']);
    $address->execute([$ids['foreignAddress'], $ids['foreignOrg'], 'billing', 'RFQ Write Foreign Fixture', '3 Test Road', 'Other City', 'DE']);

    $rfq = $db->prepare('INSERT INTO rfqs (id, organization_id, rfq_number, title, status, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $rfq->execute([$ids['draftRfq'], $organizationId, 'RFQ-WRITE-DRAFT', 'RFQ Write Draft Fixture', 'draft', $ownerId, $ownerId]);
    $rfq->execute([$ids['sentRfq'], $organizationId, 'RFQ-WRITE-SENT', 'RFQ Write Sent Fixture', 'sent', $ownerId, $ownerId]);
    $rfq->execute([$ids['foreignRfq'], $ids['foreignOrg'], 'RFQ-WRITE-FOREIGN', 'RFQ Write Foreign Fixture', 'draft', null, null]);
    $db->prepare('INSERT INTO rfqs (id, organization_id, rfq_number, title, status, release_files_generated, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ids['releasedEmptyRfq'], $organizationId, 'RFQ-WRITE-RELEASED-EMPTY', 'RFQ Write Released Empty Fixture', 'draft', 1, $ownerId, $ownerId]);
    $db->prepare('INSERT INTO rfqs (id, organization_id, rfq_number, title, status, release_files_generated, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ids['releasedHasSupplierRfq'], $organizationId, 'RFQ-WRITE-RELEASED-HAS', 'RFQ Write Released Existing Fixture', 'sent', 1, $ownerId, $ownerId]);
    $db->prepare('INSERT INTO rfqs (id, organization_id, rfq_number, title, status, release_files_generated, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ids['unreleasedRfq'], $organizationId, 'RFQ-WRITE-UNRELEASED', 'RFQ Write Unreleased Fixture', 'draft', 0, $ownerId, $ownerId]);
    $db->prepare('INSERT INTO rfqs (id, organization_id, rfq_number, title, status, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ids['teamRfq'], $organizationId, 'RFQ-WRITE-TEAM', 'RFQ Write Team Fixture', 'draft', $ownerId, $ownerId]);
    $db->prepare('INSERT INTO rfqs (id, organization_id, rfq_number, title, status, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ids['parallelRfq'], $organizationId, 'RFQ-WRITE-PARALLEL', 'RFQ Write Parallel Fixture', 'draft', $ownerId, $ownerId]);
    $supplier = $db->prepare('INSERT INTO suppliers (id, organization_id, name, code, contact_name, contact_email, is_active, is_approved, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $supplier->execute([$ids['activeUnapprovedSupplier'], $organizationId, 'Active Unapproved Supplier', 'RFQ-WRITE-ACTIVE', 'Active Contact', 'active@example.test', 1, 0, $ownerId]);
    $supplier->execute([$ids['inactiveSupplier'], $organizationId, 'RFQ-WRITE-INACTIVE', 'RFQ-WRITE-INACTIVE', 'Inactive Contact', 'inactive@example.test', 0, 1, $ownerId]);
    $supplier->execute([$ids['foreignSupplier'], $ids['foreignOrg'], 'Foreign Secret Supplier Name', 'RFQ-WRITE-FOREIGN', 'Foreign Contact', 'foreign@example.test', 1, 1, $ownerId]);
    $supplier->execute([$ids['preexistingSupplier'], $organizationId, 'Preexisting Supplier', 'RFQ-WRITE-PREEXISTING', 'Existing Contact', 'existing@example.test', 1, 1, $ownerId]);
    $supplier->execute([$ids['parallelSupplier'], $organizationId, 'Parallel Supplier', 'RFQ-WRITE-PARALLEL-SUPPLIER', 'Parallel Contact', 'parallel@example.test', 1, 1, $ownerId]);
    $db->prepare('INSERT INTO rfq_suppliers (id, organization_id, rfq_id, supplier_id, sent_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3))')
        ->execute([$ids['sentAssociation'], $organizationId, $ids['sentRfq'], $ids['activeUnapprovedSupplier']]);
    $db->prepare('INSERT INTO rfq_suppliers (id, organization_id, rfq_id, supplier_id) VALUES (?, ?, ?, ?)')
        ->execute([Runtime::uuid(), $organizationId, $ids['releasedHasSupplierRfq'], $ids['preexistingSupplier']]);
    $db->prepare('INSERT INTO rfq_suppliers (id, organization_id, rfq_id, supplier_id) VALUES (?, ?, ?, ?)')
        ->execute([$ids['foreignSupplierAssociation'], $ids['foreignOrg'], $ids['foreignRfq'], $ids['foreignSupplier']]);
    $year = (int)$db->query('SELECT YEAR(NOW())')->fetchColumn();
    $rfq->execute([Runtime::uuid(), $organizationId, 'RFQ-' . $year . '-0007', 'RFQ Write Number History', 'completed', $ownerId, $ownerId]);
    $rfq->execute([Runtime::uuid(), $ids['foreignOrg'], 'RFQ-' . $year . '-0007', 'RFQ Write Foreign Number History', 'completed', $ownerId, $ownerId]);
    $db->prepare('INSERT INTO rfq_items (id, organization_id, rfq_id, line_number, file_id, part_number, description, revision, quantity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ids['existingItem'], $organizationId, $ids['draftRfq'], 1, $ids['fileAccessible'], 'OLD-SNAPSHOT', 'Old snapshot', 'A', 2]);
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}

$membership = $db->prepare('SELECT role FROM organization_memberships WHERE organization_id = ? AND user_id = ?');
$membership->execute([$ids['foreignOrg'], $ownerId]);
if ($membership->fetchColumn() !== 'owner') throw new RuntimeException('Foreign RFQ fixture owner membership was not created.');
$ids['foreignOwnerToken'] = Runtime::issueSession($db, $env, $ownerId, $ids['foreignOrg']);

fwrite(STDOUT, json_encode($ids, JSON_THROW_ON_ERROR) . "\n");
