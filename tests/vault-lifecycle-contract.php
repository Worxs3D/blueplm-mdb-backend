<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$router = file_get_contents($root . '/public/index.php');
$service = file_get_contents($root . '/src/VaultLifecycle.php');
$migration = file_get_contents($root . '/migrations/051_vault_lifecycle_operations.sql');
foreach (['router' => $router, 'service' => $service, 'migration' => $migration] as $label => $source) {
    if ($source === false) {
        fwrite(STDERR, "Unable to read Vault lifecycle {$label}.\n");
        exit(2);
    }
}

$required = [
    [$router, "\$method === 'POST' && preg_match('#^/vaults/([0-9a-f-]{36})/clear$#i'", 'POST Vault clear route'],
    [$router, "\$method === 'DELETE' && preg_match('#^/vaults/([0-9a-f-]{36})$#i'", 'DELETE Vault route'],
    [$router, "\$method === 'GET' && preg_match('#^/vault-lifecycle-operations/([0-9a-f-]{36})$#i'", 'read-only operation status route'],
    [$service, 'SELECT id FROM organizations WHERE id = ? FOR UPDATE', 'organization lock first'],
    [$service, 'SELECT id, name FROM vaults WHERE id = ? AND organization_id = ? FOR UPDATE', 'tenant-scoped Vault lock'],
    [$service, 'UPDATE files SET updated_at = updated_at WHERE organization_id = ? AND vault_id = ?', 'bounded bulk file-row lock'],
    [$service, 'c.expires_at > UTC_TIMESTAMP(3)', 'active checkout preflight'],
    [$service, "r.status = 'active' AND r.expires_at > UTC_TIMESTAMP(3)", 'active checkout-request preflight'],
    [$service, 'DELETE FROM files WHERE organization_id = ? AND vault_id = ?', 'bulk file metadata clear'],
    [$service, 'DELETE FROM folders WHERE organization_id = ? AND vault_id = ?', 'bulk folder metadata clear'],
    [$service, 'request_fingerprint', 'request fingerprint idempotency'],
    [$service, 'IDEMPOTENCY_CONFLICT', 'different-body conflict'],
    [$service, 'NAME_MISMATCH', 'exact current-name confirmation'],
    [$service, 'VAULT_OPERATION_UNKNOWN', 'unknown commit outcome response'],
    [$service, 'Status 404 is not proof of failure', 'caller keeps unknown until directed same-ID repeat or stored success'],
    [$service, 'no committed result is visible', 'status 404 is not mutation failure evidence'],
    [$migration, 'PRIMARY KEY (organization_id, operation_id)', 'organization-scoped operation key'],
    [$migration, 'FOREIGN KEY (organization_id)', 'organization retention boundary'],
];

$missing = [];
foreach ($required as [$source, $needle, $description]) {
    if (!str_contains($source, $needle)) $missing[] = $description;
}

if (str_contains($migration, 'FOREIGN KEY (vault_id)')) {
    $missing[] = 'operation journal must survive Vault deletion';
}
if (preg_match('/\bstate\s+ENUM\([^)]*running/i', $migration)) {
    $missing[] = 'operation journal must not use a separately committed running phase';
}
$mutationPosition = strpos($service, 'DELETE FROM files WHERE organization_id = ? AND vault_id = ?');
$journalPosition = strpos($service, 'INSERT INTO vault_lifecycle_operations');
$commitPosition = $journalPosition === false ? false : strpos($service, '$db->commit();', $journalPosition);
if ($mutationPosition === false || $journalPosition === false || $commitPosition === false || $mutationPosition > $journalPosition) {
    $missing[] = 'successful metadata mutation and operation result must share one transaction';
}
if (preg_match('/\bforeach\s*\(/i', $service)) {
    $missing[] = 'Vault file/folder removal must remain database-bulk, without a PHP per-row loop';
}
foreach (['network_root', 'client_working_path', 'unlink(', 'rmdir('] as $forbidden) {
    if (str_contains($service, $forbidden)) $missing[] = 'no physical path or object cleanup: ' . $forbidden;
}

if ($missing !== []) {
    fwrite(STDERR, "Missing MDB Vault lifecycle contracts:\n - " . implode("\n - ", array_unique($missing)) . "\n");
    exit(1);
}

fwrite(STDOUT, "MDB Vault lifecycle source contract is complete.\n");
