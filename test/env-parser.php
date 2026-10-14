<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Runtime.php';

$fixture = __DIR__ . '/env-parser.fixture';
$values = BluePlm\Runtime::env($fixture);
$expected = '  edge "quote" \\slash $dollar #hash=tag' . "\nnext\rreturn ";

if (($values['BLUEPLM_ENV_PARSER_CASE'] ?? null) !== $expected) {
    throw new RuntimeException('Runtime::env did not decode the serialized installer value exactly.');
}

$legacyValues = BluePlm\Runtime::env(__DIR__ . '/env-parser-legacy.fixture');
if (($legacyValues['BLUEPLM_ENV_PARSER_LEGACY'] ?? null) !== 'literal\\nsequence') {
    throw new RuntimeException('Legacy environment values changed while parsing an unversioned file.');
}

echo "installer environment parser round-trip passed\n";
