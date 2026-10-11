<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Runtime.php';
require_once dirname(__DIR__) . '/src/FileCheckout.php';

use BluePlm\FileCheckout;
use BluePlm\Runtime;

if ($argc !== 3) {
    fwrite(STDERR, "Expected fileId and organizationId.\n");
    exit(2);
}

[, $fileId, $organizationId] = $argv;
$db = Runtime::database(getenv());
$db->beginTransaction();
try {
    $file = $db->prepare('SELECT id FROM files WHERE id = ? FOR UPDATE');
    $file->execute([$fileId]);
    if (!$file->fetch()) throw new RuntimeException('Concurrency fixture file does not exist.');
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    if (trim((string)fgets(STDIN)) !== 'continue') throw new RuntimeException('Concurrency test was not released.');

    $leaseQuery = $db->prepare('SELECT token_hash FROM checkouts WHERE file_id = ? FOR UPDATE');
    $leaseQuery->execute([$fileId]);
    $lease = $leaseQuery->fetch();
    if (!$lease) throw new RuntimeException('Concurrency fixture lease does not exist.');
    FileCheckout::markTerminalByHash($db, $fileId, $lease['token_hash'], 'cancelled');
    $db->prepare('DELETE FROM checkouts WHERE file_id = ?')->execute([$fileId]);
    Runtime::emitEvent($db, $organizationId, 'file.checkout_cancelled', $fileId, ['concurrencyTest' => true]);
    $db->commit();
    fwrite(STDOUT, "COMMITTED\n");
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
