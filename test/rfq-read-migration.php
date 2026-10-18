<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Runtime.php';
require dirname(__DIR__) . '/src/Migrator.php';

use BluePlm\Migrator;
use BluePlm\Runtime;

$root = dirname(__DIR__);
$env = Runtime::env($root . '/.env');
if (($env['MARIADB_HOST'] ?? null) !== 'mariadb'
    || ($env['MARIADB_DATABASE'] ?? null) !== 'blueplm'
    || ($env['MARIADB_PASSWORD'] ?? null) !== 'blueplm-test-password') {
    throw new RuntimeException('RFQ migration test requires the isolated integration database.');
}

$db = Runtime::database($env);
$migration = '045_rfq_read_schema.sql';
$recorded = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
$recorded->execute([$migration]);
if ((int)$recorded->fetchColumn() !== 1) {
    throw new RuntimeException('Expected a fresh installation to record migration 045.');
}

$requiredTables = ['rfqs', 'rfq_items', 'rfq_suppliers'];
$tableQuery = $db->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
);
foreach ($requiredTables as $table) {
    $tableQuery->execute([$table]);
    if ((int)$tableQuery->fetchColumn() !== 1) throw new RuntimeException("RFQ migration did not create {$table}.");
}

$columnQuery = $db->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);
$requiredColumns = [
    'rfqs' => ['sent_at', 'sent_by', 'completed_at'],
    'rfq_items' => ['created_at', 'updated_at', 'attachments', 'step_storage_path', 'pdf_storage_path'],
    'rfq_suppliers' => ['quoted_at', 'declined_at', 'declined_reason', 'total_quoted_amount', 'currency', 'lead_time_days', 'is_selected', 'selected_at', 'selected_by', 'notes'],
    'suppliers' => ['contact_name'],
];
foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
        $columnQuery->execute([$table, $column]);
        if ((int)$columnQuery->fetchColumn() !== 1) {
            throw new RuntimeException("RFQ migration is missing {$table}.{$column}.");
        }
    }
}

if (Migrator::apply($db, $root . '/migrations') !== []) {
    throw new RuntimeException('RFQ migration was not idempotently recorded.');
}

fwrite(STDOUT, "RFQ migration 045 schema creation, display fields, and repeat-apply passed.\n");
