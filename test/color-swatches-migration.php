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
    throw new RuntimeException('Color swatch migration test requires the isolated integration database.');
}

$db = Runtime::database($env);
$migration = '040_color_swatches.sql';
$recorded = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
$recorded->execute([$migration]);
if ((int)$recorded->fetchColumn() !== 1) {
    throw new RuntimeException('Expected the fresh installation to have recorded migration 040.');
}
$swatchCount = (int)$db->query('SELECT COUNT(*) FROM color_swatches')->fetchColumn();
if ($swatchCount !== 0) {
    throw new RuntimeException('Refusing to recreate a swatch table that contains test data.');
}
$organizationCount = (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$membershipCount = (int)$db->query('SELECT COUNT(*) FROM organization_memberships')->fetchColumn();

// Model an installation at migration 039, before color swatches existed.
$db->exec('DROP TABLE color_swatches');
$db->prepare('DELETE FROM schema_migrations WHERE migration = ?')->execute([$migration]);
$applied = Migrator::apply($db, $root . '/migrations');
if ($applied !== [$migration]) {
    throw new RuntimeException('Expected an existing installation to apply only migration 040.');
}
$tableCount = $db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'color_swatches'"
);
if ((int)$tableCount->fetchColumn() !== 1) {
    throw new RuntimeException('Migration 040 did not create the color swatch table.');
}
if ((int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn() !== $organizationCount
    || (int)$db->query('SELECT COUNT(*) FROM organization_memberships')->fetchColumn() !== $membershipCount) {
    throw new RuntimeException('Migration 040 changed existing organization or membership records.');
}
if (Migrator::apply($db, $root . '/migrations') !== []) {
    throw new RuntimeException('Migration 040 was not idempotently recorded after upgrade.');
}

echo "Color swatch migration 039-to-040 upgrade and idempotence passed.\n";
