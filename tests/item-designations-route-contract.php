<?php
declare(strict_types=1);

$routerPath = dirname(__DIR__) . '/public/index.php';
$router = file_get_contents($routerPath);
if ($router === false) {
    fwrite(STDERR, "Unable to read {$routerPath}.\n");
    exit(2);
}

$requiredContracts = [
    "\$path === '/item-designations'" => 'item designation collection route',
    "#^/item-designations/([0-9a-f-]{36})$#i" => 'item designation member route',
    "#^/vaults/([0-9a-f-]{36})/item-designations$#i" => 'vault designation assignment collection route',
    "#^/vaults/([0-9a-f-]{36})/item-designations/([^/]+)$#i" => 'vault designation assignment member route',
    "Runtime::requireVault(\$db, \$principal, \$vaultId)" => 'vault access guard',
    "canManageItemDesignations(\$db, \$principal" => 'item designation permission guard',
    "organization_id = ?" => 'tenant-scoped SQL',
];

$missing = [];
foreach ($requiredContracts as $needle => $description) {
    if (!str_contains($router, $needle)) $missing[] = $description;
}

if ($missing !== []) {
    fwrite(STDERR, "Missing MDB item-designation API contracts:\n - " . implode("\n - ", $missing) . "\n");
    exit(1);
}

fwrite(STDOUT, "MDB item-designation route contract is complete.\n");
