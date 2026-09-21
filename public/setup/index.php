<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Runtime.php';
require dirname(__DIR__, 2) . '/src/Migrator.php';
require dirname(__DIR__, 2) . '/src/Totp.php';
require dirname(__DIR__, 2) . '/src/Installation.php';
require dirname(__DIR__, 2) . '/src/Locale.php';
require dirname(__DIR__, 2) . '/src/SetupPortal.php';

use BluePlm\Runtime;
use BluePlm\SetupPortal;

header_remove('X-Powered-By');
$environmentPath = dirname(__DIR__, 2) . '/.env';
$env = Runtime::env($environmentPath);
try {
    SetupPortal::handle(Runtime::database($env), $env, dirname(__DIR__, 2) . '/migrations', $environmentPath);
} catch (Throwable $error) {
    error_log('[BluePLM Setup] ' . $error->getMessage());
    http_response_code(500);
    echo 'Setup portal temporarily unavailable.';
}
