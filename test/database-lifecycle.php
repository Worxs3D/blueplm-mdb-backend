<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Runtime.php';
require dirname(__DIR__) . '/src/FileCheckout.php';
require dirname(__DIR__) . '/src/DatabaseLifecycle.php';
require dirname(__DIR__) . '/src/Migrator.php';

use BluePlm\DatabaseLifecycle;
use BluePlm\FileCheckout;
use BluePlm\Migrator;
use BluePlm\Runtime;

$root = dirname(__DIR__);
$db = Runtime::database(Runtime::env($root . '/.env'));
$migrations = $root . '/migrations';

$managed = DatabaseLifecycle::inspect($db, $migrations);
if ($managed['state'] !== 'managed' || !$managed['bootstrapped'] || $managed['tableCount'] < 1) {
    throw new RuntimeException('Expected the integration database to be a bootstrapped managed database.');
}

try {
    DatabaseLifecycle::reset($db, 'wrong confirmation');
    throw new RuntimeException('An invalid reset confirmation was accepted.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'The database reset confirmation is invalid.') throw $error;
}

DatabaseLifecycle::reset($db, 'DELETE ALL DATABASE DATA');
$empty = DatabaseLifecycle::inspect($db, $migrations);
if ($empty['state'] !== 'empty' || $empty['tableCount'] !== 0) {
    throw new RuntimeException('Expected an empty database after reset.');
}
if (FileCheckout::supportsRequestIds($db)) throw new RuntimeException('The checkout recovery capability must not appear before its migration is installed.');

$db->exec('CREATE TABLE foreign_table (id INT PRIMARY KEY)');
$foreign = DatabaseLifecycle::inspect($db, $migrations);
if ($foreign['state'] !== 'foreign') throw new RuntimeException('Expected a foreign database.');
DatabaseLifecycle::reset($db, 'DELETE ALL DATABASE DATA');

$applied = Migrator::apply($db, $migrations);
if (!in_array('036_checkout_request_idempotency.sql', $applied, true)
    || !in_array('038_checkin_request_retention_indexes.sql', $applied, true)
    || !in_array('039_checkout_request_retention_indexes.sql', $applied, true)
    || !in_array('037_checkout_user_index.sql', $applied, true)
    || !FileCheckout::supportsRequestIds($db)) {
    throw new RuntimeException('Expected the checkout request-id recovery migration to apply to an existing schema.');
}
$checkoutUserIndex = $db->prepare(
    'SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1'
);
$checkoutUserIndex->execute(['checkouts', 'user_id']);
if ((int)$checkoutUserIndex->fetchColumn() < 1) {
    throw new RuntimeException('Expected a leading checkouts.user_id index for membership-removal lease lookup.');
}
$db->prepare('DELETE FROM schema_migrations WHERE migration = ?')->execute(['037_checkout_user_index.sql']);
$upgradeApplied = Migrator::apply($db, $migrations);
if ($upgradeApplied !== ['037_checkout_user_index.sql']) {
    throw new RuntimeException('Expected the user index upgrade to run independently when migration 036 is already applied.');
}
$schemaTable = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_acquisition_requests'")->fetchColumn();
if ((int)$schemaTable !== 1) throw new RuntimeException('Expected the durable checkout request table after migration.');
$legacyOrganizationId = '11111111-1111-4111-8111-111111111111';
$db->prepare('INSERT INTO organizations (id, name, slug) VALUES (?, ?, ?)')
    ->execute([$legacyOrganizationId, 'Legacy BluePLM', 'legacy-blueplm']);
$db->exec('DROP TABLE schema_migrations');
if (FileCheckout::supportsRequestIds($db)) throw new RuntimeException('The checkout recovery capability must not appear without a migration ledger.');
$legacy = DatabaseLifecycle::inspect($db, $migrations);
if ($legacy['state'] !== 'legacy') throw new RuntimeException('Expected a legacy BluePLM database.');
$legacyApplied = Migrator::apply($db, $migrations);
if (!in_array('036_checkout_request_idempotency.sql', $legacyApplied, true)
    || !in_array('038_checkin_request_retention_indexes.sql', $legacyApplied, true)
    || !in_array('039_checkout_request_retention_indexes.sql', $legacyApplied, true)
    || !in_array('037_checkout_user_index.sql', $legacyApplied, true)
    || !FileCheckout::supportsRequestIds($db)) {
    throw new RuntimeException('Expected the checkout request-id migration to apply during legacy adoption.');
}
$secondApply = Migrator::apply($db, $migrations);
if ($secondApply !== [] || !FileCheckout::supportsRequestIds($db)) {
    throw new RuntimeException('Expected the checkout request-id migration to remain idempotent.');
}
$retentionIndexes = $db->prepare(
    'SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME IN (?, ?, ?)'
);
$retentionIndexes->execute([
    'file_checkin_requests',
    'idx_file_checkin_requests_user_scope',
    'idx_file_checkin_requests_org_scope',
    'idx_file_checkin_requests_created',
]);
if ((int)$retentionIndexes->fetchColumn() !== 3) {
    throw new RuntimeException('Expected indexed global user, organization, and expiry receipt lookups.');
}
$checkoutRetentionIndexes = $db->prepare(
    'SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME IN (?, ?, ?, ?, ?)'
);
$checkoutRetentionIndexes->execute([
    'checkout_acquisition_requests',
    'idx_checkout_request_user_retention',
    'idx_checkout_request_org_retention',
    'idx_checkout_request_file_retention',
    'idx_checkout_request_terminal_retention',
    'idx_checkout_request_active_expiry',
]);
if ((int)$checkoutRetentionIndexes->fetchColumn() !== 5) {
    throw new RuntimeException('Expected indexed global user, organization, file, terminal, and active-expiry checkout request lookups.');
}
$adopted = DatabaseLifecycle::inspect($db, $migrations);
if ($adopted['state'] !== 'managed' || $adopted['pendingMigrations'] !== 0) {
    throw new RuntimeException('Expected the legacy BluePLM database to be adopted and fully migrated.');
}
$sentinel = $db->prepare('SELECT COUNT(*) FROM organizations WHERE id = ?');
$sentinel->execute([$legacyOrganizationId]);
if ((int)$sentinel->fetchColumn() !== 1) {
    throw new RuntimeException('Legacy BluePLM data was not preserved during migration.');
}
DatabaseLifecycle::reset($db, 'DELETE ALL DATABASE DATA');

echo "Database lifecycle classification, legacy migration, and guarded reset passed.\n";
