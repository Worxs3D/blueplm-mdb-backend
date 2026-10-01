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
$migration = '029_module_defaults.sql';
$failures = [];

/** @param callable(): void $test */
function scenario(string $name, callable $test, array &$failures): void
{
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failures[] = "{$name}: {$error->getMessage()}";
        echo "FAIL {$name}: {$error->getMessage()}\n";
    }
}

function requireModuleDefaultsColumns(PDO $db): void
{
    $expected = [
        ['teams', 'module_defaults'],
        ['teams', 'module_defaults_forced_at'],
        ['organization_settings', 'module_defaults'],
        ['organization_settings', 'module_defaults_forced_at'],
    ];
    $query = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    foreach ($expected as [$table, $column]) {
        $query->execute([$table, $column]);
        if ((int)$query->fetchColumn() !== 1) {
            throw new RuntimeException("Expected {$table}.{$column} to exist.");
        }
    }
}

function requireMigrationRecorded(PDO $db, string $migration): void
{
    $query = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
    $query->execute([$migration]);
    if ((int)$query->fetchColumn() !== 1) {
        throw new RuntimeException("Expected {$migration} to be recorded exactly once.");
    }
}

scenario('fresh schema', function () use ($db, $migrations, $migration): void {
    DatabaseLifecycle::reset($db, 'DELETE ALL DATABASE DATA');
    $applied = Migrator::apply($db, $migrations);
    if (!in_array($migration, $applied, true)) {
        throw new RuntimeException('Fresh migration did not apply module defaults.');
    }
    requireModuleDefaultsColumns($db);
    requireMigrationRecorded($db, $migration);
}, $failures);

scenario('second run', function () use ($db, $migrations, $migration): void {
    DatabaseLifecycle::reset($db, 'DELETE ALL DATABASE DATA');
    Migrator::apply($db, $migrations);
    if (Migrator::apply($db, $migrations) !== []) {
        throw new RuntimeException('A second migration run was not a no-op.');
    }
    requireModuleDefaultsColumns($db);
    requireMigrationRecorded($db, $migration);
}, $failures);

scenario('full legacy schema without ledger', function () use ($db, $migrations, $migration): void {
    DatabaseLifecycle::reset($db, 'DELETE ALL DATABASE DATA');
    Migrator::apply($db, $migrations);
    $organizationId = '11111111-1111-4111-8111-111111111111';
    $db->prepare('INSERT INTO organizations (id, name, slug) VALUES (?, ?, ?)')
        ->execute([$organizationId, 'Legacy BluePLM', 'legacy-module-defaults']);
    $db->exec('DROP TABLE schema_migrations');

    $legacy = DatabaseLifecycle::inspect($db, $migrations);
    if ($legacy['state'] !== 'legacy') {
        throw new RuntimeException('Expected the ledger-free BluePLM schema to be classified as legacy.');
    }
    Migrator::apply($db, $migrations);

    requireModuleDefaultsColumns($db);
    requireMigrationRecorded($db, $migration);
    $sentinel = $db->prepare('SELECT COUNT(*) FROM organizations WHERE id = ?');
    $sentinel->execute([$organizationId]);
    if ((int)$sentinel->fetchColumn() !== 1) {
        throw new RuntimeException('Legacy organization data was not preserved.');
    }
}, $failures);

scenario('partial legacy schema without ledger', function () use ($db, $migrations, $migration): void {
    DatabaseLifecycle::reset($db, 'DELETE ALL DATABASE DATA');
    Migrator::apply($db, $migrations);

    $organizationId = '22222222-2222-4222-8222-222222222222';
    $teamId = '33333333-3333-4333-8333-333333333333';
    $db->prepare('INSERT INTO organizations (id, name, slug) VALUES (?, ?, ?)')
        ->execute([$organizationId, 'Partial Legacy BluePLM', 'partial-module-defaults']);
    $db->prepare('INSERT INTO teams (id, organization_id, name, module_defaults) VALUES (?, ?, ?, ?)')
        ->execute([$teamId, $organizationId, 'Legacy Team', '{"files":"hidden"}']);
    $db->prepare(
        'INSERT INTO organization_settings (organization_id, module_defaults_forced_at)
         VALUES (?, ?)'
    )->execute([$organizationId, '2026-01-02 03:04:05.678']);

    $db->exec('ALTER TABLE teams DROP COLUMN module_defaults_forced_at');
    $db->exec('ALTER TABLE organization_settings DROP COLUMN module_defaults');
    $db->exec('DROP TABLE schema_migrations');
    Migrator::apply($db, $migrations);

    requireModuleDefaultsColumns($db);
    requireMigrationRecorded($db, $migration);
    $teamDefaults = $db->prepare('SELECT module_defaults FROM teams WHERE id = ?');
    $teamDefaults->execute([$teamId]);
    if ($teamDefaults->fetchColumn() !== '{"files":"hidden"}') {
        throw new RuntimeException('Existing team module defaults were not preserved.');
    }
    $organizationForcedAt = $db->prepare(
        'SELECT module_defaults_forced_at FROM organization_settings WHERE organization_id = ?'
    );
    $organizationForcedAt->execute([$organizationId]);
    if ($organizationForcedAt->fetchColumn() !== '2026-01-02 03:04:05.678') {
        throw new RuntimeException('Existing organization module-default timestamp was not preserved.');
    }
}, $failures);

DatabaseLifecycle::reset($db, 'DELETE ALL DATABASE DATA');
if ($failures !== []) {
    throw new RuntimeException("Module-default migration scenarios failed:\n- " . implode("\n- ", $failures));
}

echo "Module-default migration fresh, repeat, full legacy, and partial legacy scenarios passed.\n";
