<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Runtime.php';
require dirname(__DIR__) . '/src/DatabaseLifecycle.php';
require dirname(__DIR__) . '/src/Migrator.php';

use BluePlm\DatabaseLifecycle;
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

$db->exec('CREATE TABLE foreign_table (id INT PRIMARY KEY)');
$foreign = DatabaseLifecycle::inspect($db, $migrations);
if ($foreign['state'] !== 'foreign') throw new RuntimeException('Expected a foreign database.');
DatabaseLifecycle::reset($db, 'DELETE ALL DATABASE DATA');

Migrator::apply($db, $migrations);
$legacyOrganizationId = '11111111-1111-4111-8111-111111111111';
$db->prepare('INSERT INTO organizations (id, name, slug) VALUES (?, ?, ?)')
    ->execute([$legacyOrganizationId, 'Legacy BluePLM', 'legacy-blueplm']);
$db->exec('DROP TABLE schema_migrations');
$legacy = DatabaseLifecycle::inspect($db, $migrations);
if ($legacy['state'] !== 'legacy') throw new RuntimeException('Expected a legacy BluePLM database.');
Migrator::apply($db, $migrations);
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
