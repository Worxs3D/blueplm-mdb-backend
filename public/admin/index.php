<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Runtime.php';
require dirname(__DIR__, 2) . '/src/Totp.php';
require dirname(__DIR__, 2) . '/src/Locale.php';
require dirname(__DIR__, 2) . '/src/AdminPortal.php';

use BluePlm\AdminPortal;
use BluePlm\Runtime;

header_remove('X-Powered-By');
$env = Runtime::env(dirname(__DIR__, 2) . '/.env');

try {
    AdminPortal::handle(Runtime::database($env), $env);
} catch (Throwable $error) {
    error_log('[BluePLM Admin] ' . $error->getMessage());
    http_response_code(500);
    echo 'Administration portal temporarily unavailable.';
}
