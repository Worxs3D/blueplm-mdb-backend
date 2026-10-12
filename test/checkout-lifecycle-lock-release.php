<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/FileCheckout.php';
require_once __DIR__ . '/../src/FileCheckin.php';

use BluePlm\FileCheckout;
use BluePlm\FileCheckin;

final class ReleaseFaultStatement extends PDOStatement
{
    public function __construct(private readonly int $result, private readonly bool $throws)
    {
    }

    public function execute(?array $params = null): bool
    {
        if ($this->throws) throw new PDOException('Injected RELEASE_LOCK failure.');
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->result;
    }
}

final class ReleaseFaultPDO extends PDO
{
    public function __construct(private readonly int $result, private readonly bool $throws)
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($query !== 'SELECT RELEASE_LOCK(?)') throw new RuntimeException('Unexpected statement in lifecycle lock fault test.');
        return new ReleaseFaultStatement($this->result, $this->throws);
    }
}

final class CheckinReleaseFaultStatement extends PDOStatement
{
    public function __construct(private readonly \Closure $onExecute)
    {
    }

    public function execute(?array $params = null): bool
    {
        ($this->onExecute)($params ?? []);
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return 1;
    }
}

final class CheckinReleaseFaultPDO extends PDO
{
    /** @var list<string> */
    public array $attempted = [];
    private bool $failFirst = true;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($query !== 'SELECT RELEASE_LOCK(?)') throw new RuntimeException('Unexpected statement in check-in lock fault test.');
        return new CheckinReleaseFaultStatement(function (array $params): void {
            $this->attempted[] = (string)($params[0] ?? '');
            if ($this->failFirst) {
                $this->failFirst = false;
                throw new PDOException('Injected first RELEASE_LOCK failure.');
            }
        });
    }
}

FileCheckout::finishUserLifecycleLock(new ReleaseFaultPDO(1, false), 'test-lock', true, null);
FileCheckout::finishUserLifecycleLock(new ReleaseFaultPDO(0, false), 'test-lock', true, null);
FileCheckout::finishUserLifecycleLock(new ReleaseFaultPDO(0, true), 'test-lock', true, null);

$originalFailure = new RuntimeException('Injected transaction failure.');
$observedFailure = null;
try {
    try {
        throw $originalFailure;
    } catch (Throwable $error) {
        FileCheckout::finishUserLifecycleLock(new ReleaseFaultPDO(0, true), 'test-lock', false, $error);
        throw $error;
    }
} catch (Throwable $error) {
    $observedFailure = $error;
}
if ($observedFailure !== $originalFailure) {
    throw new RuntimeException('Lifecycle cleanup replaced the original transaction failure.');
}

foreach ([new ReleaseFaultPDO(0, false), new ReleaseFaultPDO(0, true)] as $cleanupFailure) {
    $unexpectedReleaseFailure = false;
    try {
        FileCheckout::finishUserLifecycleLock($cleanupFailure, 'test-lock', false, null);
    } catch (RuntimeException $error) {
        $unexpectedReleaseFailure = $error->getMessage() === 'User checkout lifecycle lock was not released.';
    }
    if (!$unexpectedReleaseFailure) throw new RuntimeException('A cleanup-only failure without a commit or original operation error must not be hidden.');
}

$checkinReleaseFault = new CheckinReleaseFaultPDO();
$releaseCheckinLocks = new ReflectionMethod(FileCheckin::class, 'releaseCheckinLocks');
$releasedAll = $releaseCheckinLocks->invoke(null, $checkinReleaseFault, ['request-lock', 'user-lock', 'organization-lock']);
if ($releasedAll !== false || $checkinReleaseFault->attempted !== ['organization-lock', 'user-lock', 'request-lock']) {
    throw new RuntimeException('Check-in lock cleanup must attempt every lock in reverse order after a release failure.');
}

echo "Lifecycle release success, committed checkout/delete outcome, rollback-error identity, and cleanup-only failure passed.\n";
