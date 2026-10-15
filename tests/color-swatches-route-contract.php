<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$router = file_get_contents($root . '/public/index.php');
$handler = file_get_contents($root . '/src/ColorSwatches.php');
$migration = file_get_contents($root . '/migrations/040_color_swatches.sql');
if ($router === false || $handler === false || $migration === false) {
    fwrite(STDERR, "Unable to read the color swatch route contract sources.\n");
    exit(2);
}

$requiredContracts = [
    ["ColorSwatches::dispatch(\$db, \$principal, \$method, \$path)", $router, 'router dispatch'],
    ["\$method === 'GET' && \$path === '/color-swatches'", $handler, 'list route'],
    ["\$method === 'POST' && \$path === '/color-swatches'", $handler, 'create route'],
    ["\$method === 'DELETE' && preg_match('#^/color-swatches/", $handler, 'delete route'],
    ['WHERE organization_id = ? AND (user_id IS NULL OR user_id = ?)', $handler, 'organization and personal list scope'],
    ['WHERE id = ? AND organization_id = ? AND user_id = ?', $handler, 'personal delete ownership scope'],
    ["\$principal['role'], ['owner', 'admin']", $handler, 'owner/admin organization-write guard'],
    ["CREATE TABLE IF NOT EXISTS color_swatches", $migration, 'idempotent storage migration'],
];

$missing = [];
foreach ($requiredContracts as [$needle, $source, $description]) {
    if (!str_contains($source, $needle)) $missing[] = $description;
}
$readOnlyGate = strpos($router, "in_array(\$principal['role'], ['viewer', 'guest'], true)");
$dispatch = strpos($router, 'ColorSwatches::dispatch(');
if ($readOnlyGate === false || $dispatch === false || $dispatch < $readOnlyGate) {
    $missing[] = 'dispatch after the viewer/guest read-only guard';
}

if ($missing !== []) {
    fwrite(STDERR, "Missing MDB color swatch API contracts:\n - " . implode("\n - ", $missing) . "\n");
    exit(1);
}

fwrite(STDOUT, "MDB color swatch route contract is complete.\n");
