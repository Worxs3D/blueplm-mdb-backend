<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/VaultRelativePath.php';
require dirname(__DIR__) . '/src/AdminOnlyFolders.php';

use BluePlm\AdminOnlyFolders;

function expectSame(mixed $expected, mixed $actual, string $case): void
{
    if ($expected !== $actual) throw new RuntimeException("{$case}: values differ.");
}

function expectInvalid(callable $operation, string $case, string $class = \InvalidArgumentException::class): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $class) return;
        throw new RuntimeException("{$case}: unexpected exception " . $error::class, previous: $error);
    }
    throw new RuntimeException("{$case}: expected rejection.");
}

$valid = ['Archive/PN-4821', 'USB export'];
expectSame([], AdminOnlyFolders::fromRequest([]), 'explicit empty request');
expectSame($valid, AdminOnlyFolders::fromRequest($valid), 'valid paths');
expectSame(['Archive/PN-4821'], AdminOnlyFolders::fromRequest(['Archive\\PN-4821', 'archive/PN-4821']), 'slash normalization and case-insensitive dedupe');
expectSame([], AdminOnlyFolders::fromStored('[]'), 'explicit stored empty array');
expectSame($valid, AdminOnlyFolders::fromStored('["Archive/PN-4821","USB export"]'), 'valid stored array');

foreach ([null, '', '{}', 'null', '"path"', '{"0":"Archive"}', 'false', '[null]', '[1]', '["../secret"]', '["C:\\\\secret"]', '["/absolute"]', '["a//b"]'] as $invalidStored) {
    expectInvalid(fn() => AdminOnlyFolders::fromStored($invalidStored), 'invalid stored value ' . var_export($invalidStored, true), \UnexpectedValueException::class);
}
foreach ([(object)[], 'folder', null, [null], ['../secret'], ['D:\\private'], ['/absolute'], ['a//b']] as $invalidRequest) {
    expectInvalid(fn() => AdminOnlyFolders::fromRequest($invalidRequest), 'invalid request value ' . var_export($invalidRequest, true));
}

$router = file_get_contents(dirname(__DIR__) . '/public/index.php');
if ($router === false) throw new RuntimeException('Unable to read router source.');
foreach ([
    "settings/admin-only-folders",
    "SELECT admin_only_folders FROM organization_settings WHERE organization_id = ?",
    "['owner', 'admin']",
    "AdminOnlyFolders::fromStored",
    "AdminOnlyFolders::fromRequest",
    "POLICY_UNINITIALIZED",
] as $needle) {
    if (!str_contains($router, $needle)) throw new RuntimeException("Missing route contract: {$needle}");
}
$migrator = file_get_contents(dirname(__DIR__) . '/src/Migrator.php');
$installation = file_get_contents(dirname(__DIR__) . '/src/Installation.php');
$runtime = file_get_contents(dirname(__DIR__) . '/src/Runtime.php');
$migration = file_get_contents(dirname(__DIR__) . '/migrations/049_admin_only_folders.sql');
if ($migrator === false || $installation === false || $runtime === false || $migration === false) {
    throw new RuntimeException('Unable to read migration/provisioning/body source.');
}
foreach ([
    [$migrator, 'ALTER TABLE organization_settings ADD COLUMN admin_only_folders JSON NULL', 'nullable schema addition'],
    [$migrator, "execute(['organization_settings', 'admin_only_folders'])", 'column existence guard'],
    [$migrator, "WHERE admin_only_folders IS NULL", 'migration-only explicit initialization'],
    [$migrator, 'LEFT JOIN organization_settings', 'missing settings-row backfill'],
    [$installation, "admin_only_folders) VALUES (?, ?)')->execute([\$organizationId, '[]'])", 'new organization initialization'],
    [$runtime, 'json_decode($raw, false, 512, JSON_THROW_ON_ERROR)', 'typed JSON value decoding'],
    [$migration, 'Applied by Migrator::ensureAdminOnlyFoldersPolicy()', 'guarded migration dispatch'],
] as [$source, $needle, $description]) {
    if (!str_contains($source, $needle)) throw new RuntimeException("Missing storage contract: {$description}");
}

fwrite(STDOUT, "Admin-only folders policy contract passed.\n");
