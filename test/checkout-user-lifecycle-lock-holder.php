<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Runtime.php';
require_once __DIR__ . '/../src/FileCheckout.php';

use BluePlm\FileCheckout;
use BluePlm\Runtime;

if ($argc !== 2) {
    fwrite(STDERR, "Expected userId.\n");
    exit(2);
}

[, $userId] = $argv;
$db = Runtime::database(getenv());
$lockName = FileCheckout::acquireUserLifecycleLock($db, $userId);
if ($lockName === null) {
    fwrite(STDERR, "Could not acquire checkout lifecycle lock.\n");
    exit(1);
}

try {
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    if (trim((string)fgets(STDIN)) !== 'continue') throw new RuntimeException('Concurrency test was not released.');
    fwrite(STDOUT, "RELEASED\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    if (!FileCheckout::releaseUserLifecycleLock($db, $lockName)) {
        fwrite(STDERR, "Checkout lifecycle lock was not released.\n");
        exit(1);
    }
}
