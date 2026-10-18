<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$router = file_get_contents($root . '/public/index.php');
$handler = file_get_contents($root . '/src/JobTitles.php');
$migration = file_get_contents($root . '/migrations/043_job_titles.sql');
if ($router === false || $handler === false || $migration === false) {
    fwrite(STDERR, "Unable to read the job-title route contract sources.\n");
    exit(2);
}

$requiredContracts = [
    ["JobTitles::dispatch(\$db, \$principal, \$method, \$path)", $router, 'router dispatch'],
    ["\$method === 'GET' && \$path === '/job-titles'", $handler, 'catalog read route'],
    ["\$method === 'POST' && \$path === '/job-titles'", $handler, 'catalog create route'],
    ["\$method === 'PATCH' && preg_match('#^/job-titles/", $handler, 'catalog update route'],
    ["\$method === 'DELETE' && preg_match('#^/job-titles/", $handler, 'catalog delete route'],
    ["\$method === 'PUT' && preg_match('#^/users/", $handler, 'user assignment route'],
    ['WHERE organization_id = ? ORDER BY name, id', $handler, 'organization-scoped title read'],
    ["\$principal['role'] ?? null, ['owner', 'admin']", $handler, 'server-side owner/admin write guard'],
    ['ON DUPLICATE KEY UPDATE title_id', $handler, 'one title per user upsert'],
    ['CREATE TABLE IF NOT EXISTS job_titles', $migration, 'job title catalog storage'],
    ['CREATE TABLE IF NOT EXISTS user_job_titles', $migration, 'organization member assignments'],
    ['FOREIGN KEY (organization_id, title_id)', $migration, 'cross-organization title assignment guard'],
    ['FOREIGN KEY (organization_id, user_id)', $migration, 'organization membership assignment guard'],
];

$missing = [];
foreach ($requiredContracts as [$needle, $source, $description]) {
    if (!str_contains($source, $needle)) $missing[] = $description;
}
$readOnlyGate = strpos($router, "in_array(\$principal['role'], ['viewer', 'guest'], true)");
$dispatch = strpos($router, 'JobTitles::dispatch(');
if ($readOnlyGate === false || $dispatch === false || $dispatch < $readOnlyGate) {
    $missing[] = 'dispatch after the viewer/guest read-only guard';
}
if (strpos($handler, "\$principal['organizationId']") === false) $missing[] = 'principal organization scope';

if ($missing !== []) {
    fwrite(STDERR, "Missing MDB job-title API contracts:\n - " . implode("\n - ", $missing) . "\n");
    exit(1);
}

fwrite(STDOUT, "MDB job-title route contract is complete.\n");
