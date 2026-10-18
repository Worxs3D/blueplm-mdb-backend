<?php
declare(strict_types=1);

$entrypoint = file_get_contents(__DIR__ . '/../public/index.php');
$handler = file_get_contents(__DIR__ . '/../src/RFQReads.php');
$migration = file_get_contents(__DIR__ . '/../migrations/045_rfq_read_schema.sql');
foreach (['entrypoint' => $entrypoint, 'handler' => $handler, 'migration' => $migration] as $name => $source) {
    if (!is_string($source)) throw new RuntimeException("Could not read RFQ {$name} contract.");
}

foreach ([
    "require dirname(__DIR__) . '/src/RFQReads.php';",
    'RFQReads::dispatch($db, $principal, $method, $path);',
] as $contract) {
    if (!str_contains($entrypoint, $contract)) throw new RuntimeException("RFQ entrypoint contract missing: {$contract}");
}
foreach ([
    'if ($method !== \'GET\') return;',
    'r.organization_id = ?',
    'i.organization_id = ? AND i.rfq_id = ?',
    'rs.organization_id = ? AND rs.rfq_id = ?',
    's.organization_id = rs.organization_id',
    'i.attachments',
    'i.step_storage_path',
    'i.pdf_storage_path',
    'i.created_at, i.updated_at',
    'rs.quoted_at',
    'rs.declined_at',
    'rs.total_quoted_amount',
    'rs.is_selected',
    's.contact_name AS linked_supplier_contact_name',
    'self::decodeAttachments($item[\'attachments\'])',
    'f.organization_id = r.organization_id AND f.deleted_at IS NULL',
    'creator_membership.organization_id = r.organization_id',
    "'MODULE_ACCESS_DENIED'",
    "'INVALID_REQUEST'",
    "'NOT_FOUND'",
] as $contract) {
    if (!str_contains($handler, $contract)) throw new RuntimeException("RFQ handler contract missing: {$contract}");
}
foreach ([
    'CREATE TABLE IF NOT EXISTS rfqs',
    'CREATE TABLE IF NOT EXISTS rfq_items',
    'CREATE TABLE IF NOT EXISTS rfq_suppliers',
    'sw_configuration TEXT NULL',
    'attachments JSON NULL',
    'total_quoted_amount DECIMAL(18,6) NULL',
    'currency CHAR(3) NOT NULL DEFAULT \'USD\'',
    'ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS contact_name VARCHAR(255) NULL',
    'organization_id CHAR(36) NOT NULL',
    "ENUM('draft', 'pending_files', 'generating', 'ready', 'sent', 'awaiting_quote', 'quoted', 'awarded', 'cancelled', 'completed')",
] as $contract) {
    if (!str_contains($migration, $contract)) throw new RuntimeException("RFQ schema contract missing: {$contract}");
}

if (!str_contains($handler, "'swConfiguration' => is_string(\$item['sw_configuration']) ? \$item['sw_configuration'] : null,")) {
    throw new RuntimeException('RFQ configuration name must remain an unchanged string or null.');
}

fwrite(STDOUT, "RFQ read route and tenant contract: ok\n");
