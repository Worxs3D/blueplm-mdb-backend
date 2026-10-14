<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Runtime.php';
require_once __DIR__ . '/../src/FileCheckout.php';

use BluePlm\CheckoutLeaseFailure;
use BluePlm\FileCheckout;
use BluePlm\Runtime;

final class CheckoutRetentionNoQueryPDO extends PDO
{
    public bool $queryAttempted = false;

    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queryAttempted = true;
        throw new RuntimeException('Database access was reached before invalid checkout retention config was rejected.');
    }
}

$defaults = Runtime::checkoutRequestRetention([]);
if ($defaults !== ['ttlDays' => 30, 'perFile' => 200, 'perUser' => 1000, 'perOrganization' => 10000]) {
    throw new RuntimeException('Checkout request retention defaults changed unexpectedly.');
}

$configured = Runtime::checkoutRequestRetention([
    'BLUEPLM_CHECKOUT_REQUEST_TTL_DAYS' => '7',
    'BLUEPLM_CHECKOUT_REQUEST_MAX_PER_FILE' => '12',
    'BLUEPLM_CHECKOUT_REQUEST_MAX_PER_USER' => '34',
    'BLUEPLM_CHECKOUT_REQUEST_MAX_PER_ORGANIZATION' => '56',
]);
if ($configured !== ['ttlDays' => 7, 'perFile' => 12, 'perUser' => 34, 'perOrganization' => 56]) {
    throw new RuntimeException('Checkout request retention overrides were not applied.');
}

foreach ([
    'BLUEPLM_CHECKOUT_REQUEST_TTL_DAYS' => ['0', '-1', '3651', '1.5', 'not-a-number'],
    'BLUEPLM_CHECKOUT_REQUEST_MAX_PER_FILE' => ['0', '-1', '1.5', 'not-a-number'],
    'BLUEPLM_CHECKOUT_REQUEST_MAX_PER_USER' => ['0', '-1', '1.5', 'not-a-number'],
    'BLUEPLM_CHECKOUT_REQUEST_MAX_PER_ORGANIZATION' => ['0', '-1', '1.5', 'not-a-number'],
] as $key => $values) {
    foreach ($values as $value) {
        try {
            Runtime::checkoutRequestRetention([$key => $value]);
            throw new RuntimeException($key . ' accepted invalid value ' . $value . '.');
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), 'accepted invalid value')) throw $error;
        }
    }
}

$db = new CheckoutRetentionNoQueryPDO();
$failure = null;
try {
    FileCheckout::acquireWithRequestId(
        $db,
        ['BLUEPLM_CHECKOUT_REQUEST_MAX_PER_USER' => '0'],
        ['userId' => '00000000-0000-4000-8000-000000000001', 'organizationId' => '00000000-0000-4000-8000-000000000002'],
        '00000000-0000-4000-8000-000000000003',
        '00000000-0000-4000-8000-000000000004',
        'C:/checkout-retention-test',
        '00000000-0000-4000-8000-000000000005',
    );
} catch (CheckoutLeaseFailure $error) {
    $failure = $error;
}
if ($failure?->status !== 503 || $failure->errorCode !== 'CHECKOUT_RECOVERY_UNAVAILABLE') {
    throw new RuntimeException('Invalid checkout retention configuration must fail with CHECKOUT_RECOVERY_UNAVAILABLE.');
}
if ($db->queryAttempted) throw new RuntimeException('Invalid checkout retention configuration reached the database.');

echo "Checkout retention defaults, overrides, and invalid-config fail-closed behavior passed.\n";
