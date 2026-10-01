<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../public/index.php');
if (!is_string($source)) throw new RuntimeException('Could not read API entrypoint.');

foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
    if (!str_contains($source, "\$method === '{$method}'")) throw new RuntimeException("Missing {$method} route guard.");
}
foreach ([
    "if (!in_array(\$principal['role'], ['owner', 'admin'], true))",
    'WHERE organization_id = ?',
    'organization_id, name, code',
    'part_suppliers keeps its historical',
] as $contract) {
    if (!str_contains($source, $contract)) throw new RuntimeException("Supplier contract missing: {$contract}");
}

fwrite(STDOUT, "supplier route contract: ok\n");
