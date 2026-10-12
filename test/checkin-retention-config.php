<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Runtime.php';
require_once __DIR__ . '/../src/FileCheckin.php';

use BluePlm\FileCheckin;
use BluePlm\FileCheckinFailure;
use BluePlm\Runtime;

final class RetentionConfigNoQueryPDO extends PDO
{
    public bool $queryAttempted = false;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queryAttempted = true;
        throw new RuntimeException('Database access was reached before invalid retention config was rejected.');
    }
}

$defaults = Runtime::checkinRequestRetention([]);
if ($defaults !== ['ttlDays' => 30, 'perFile' => 200, 'perUser' => 1000, 'perOrganization' => 10000]) {
    throw new RuntimeException('Check-in receipt retention defaults changed unexpectedly.');
}

$configured = Runtime::checkinRequestRetention([
    'BLUEPLM_CHECKIN_REQUEST_TTL_DAYS' => '7',
    'BLUEPLM_CHECKIN_REQUEST_MAX_PER_FILE' => '12',
    'BLUEPLM_CHECKIN_REQUEST_MAX_PER_USER' => '34',
    'BLUEPLM_CHECKIN_REQUEST_MAX_PER_ORGANIZATION' => '56',
]);
if ($configured !== ['ttlDays' => 7, 'perFile' => 12, 'perUser' => 34, 'perOrganization' => 56]) {
    throw new RuntimeException('Check-in receipt retention overrides were not applied.');
}

foreach ([
    'BLUEPLM_CHECKIN_REQUEST_TTL_DAYS' => ['0', '-1', '3651', '1.5', 'not-a-number'],
    'BLUEPLM_CHECKIN_REQUEST_MAX_PER_FILE' => ['0', '-1', '1.5', 'not-a-number'],
    'BLUEPLM_CHECKIN_REQUEST_MAX_PER_USER' => ['0', '-1', '1.5', 'not-a-number'],
    'BLUEPLM_CHECKIN_REQUEST_MAX_PER_ORGANIZATION' => ['0', '-1', '1.5', 'not-a-number'],
] as $key => $invalidValues) {
    foreach ($invalidValues as $value) {
        try {
            Runtime::checkinRequestRetention([$key => $value]);
            throw new RuntimeException($key . ' accepted invalid value ' . $value . '.');
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), 'accepted invalid value')) throw $error;
        }
    }
}

$db = new RetentionConfigNoQueryPDO();
$invalidConfigFailure = null;
try {
    FileCheckin::execute($db, ['BLUEPLM_CHECKIN_REQUEST_TTL_DAYS' => ''], [], '00000000-0000-4000-8000-000000000001', [
        'mode' => 'metadata-only',
        'requestId' => '00000000-0000-4000-8000-000000000002',
        'canonicalPath' => 'invalid-config.sldprt',
        'fileName' => 'invalid-config.sldprt',
        'checkoutToken' => 'valid-shaped-checkout-token',
    ]);
} catch (FileCheckinFailure $error) {
    $invalidConfigFailure = $error;
}
if ($invalidConfigFailure?->status !== 503 || $invalidConfigFailure->errorCode !== 'CHECKIN_RECOVERY_UNAVAILABLE') {
    throw new RuntimeException('Invalid retention configuration must fail with CHECKIN_RECOVERY_UNAVAILABLE.');
}
if ($db->queryAttempted) throw new RuntimeException('Invalid retention configuration reached the database before failing closed.');

echo "Check-in retention defaults, overrides, and invalid-config fail-closed behavior passed.\n";
