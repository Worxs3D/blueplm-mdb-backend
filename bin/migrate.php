<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Runtime.php';
require dirname(__DIR__) . '/src/Migrator.php';

use BluePlm\Migrator;
use BluePlm\Runtime;

$env = Runtime::env(dirname(__DIR__) . '/.env');
$db = Runtime::database($env);
foreach (Migrator::apply($db, dirname(__DIR__) . '/migrations') as $migration) fwrite(STDOUT, "Applied {$migration}" . PHP_EOL);
