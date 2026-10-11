<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Runtime.php';
require_once dirname(__DIR__) . '/src/FileCheckout.php';

use BluePlm\FileCheckout;
use BluePlm\Runtime;

if ($argc !== 4) {
    fwrite(STDERR, "Expected operation, organizationId, and requestId.\n");
    exit(2);
}

[, $operation, $organizationId, $requestId] = $argv;
$db = Runtime::database(getenv());
$lockName = FileCheckout::acquireRequestIdLock($db, $operation, $organizationId, $requestId);
if ($lockName === null) {
    fwrite(STDERR, "Could not acquire the request lock.\n");
    exit(1);
}

$failure = null;
try {
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    if (trim((string)fgets(STDIN)) !== 'continue') throw new RuntimeException('Concurrency test was not released.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if (!FileCheckout::releaseRequestIdLock($db, $lockName)) {
        $failure = new RuntimeException('Could not release the request lock.');
    }
}
if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}
