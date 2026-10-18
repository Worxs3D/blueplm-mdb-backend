<?php
declare(strict_types=1);

function fixtureUuid(): string
{
    $hex = bin2hex(random_bytes(16));
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function fixtureTimestamp(DateTimeImmutable $value): string
{
    return $value->format('Y-m-d H:i:s.v');
}

function fixtureDatabaseDateTime(string $value): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
    if ($date === false) throw new RuntimeException('Database fixture clock returned an invalid DATETIME.');
    return $date;
}

function fixtureDatabase(): PDO
{
    $host = getenv('MARIADB_HOST');
    $port = getenv('MARIADB_PORT') ?: '3306';
    $database = getenv('MARIADB_DATABASE');
    $user = getenv('MARIADB_USER');
    $password = getenv('MARIADB_PASSWORD');
    foreach ([$host, $database, $user, $password] as $value) {
        if (!is_string($value) || $value === '') throw new RuntimeException('Fixture database configuration is incomplete.');
    }
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
}

function seedProfileSummary(PDO $db, string $organizationId, string $ownerId, string $memberId, string $guestId): array
{
    $ownerId = strtolower($ownerId);
    $memberId = strtolower($memberId);
    $guestId = strtolower($guestId);
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM events WHERE organization_id = ?')->execute([$organizationId]);

        $visibleVaultId = fixtureUuid();
        $hiddenVaultId = fixtureUuid();
        $db->prepare('INSERT INTO vaults (id, organization_id, name, network_root) VALUES (?, ?, ?, ?)')->execute([$visibleVaultId, $organizationId, 'Profile fixture visible vault', 'fixture-visible-root']);
        $db->prepare('INSERT INTO vaults (id, organization_id, name, network_root) VALUES (?, ?, ?, ?)')->execute([$hiddenVaultId, $organizationId, 'Profile fixture hidden vault', 'fixture-hidden-root']);
        $visibleFileId = fixtureUuid();
        $hiddenFileId = fixtureUuid();
        $missingFileId = fixtureUuid();
        $insertFile = $db->prepare('INSERT INTO files (id, organization_id, vault_id, canonical_path, file_name, storage_relative_path, current_revision, state, content_hash, size_bytes) VALUES (?, ?, ?, ?, ?, ?, 1, \'wip\', ?, 17)');
        $insertFile->execute([$visibleFileId, $organizationId, $visibleVaultId, 'ProfileFixture/visible.txt', 'visible-profile-file.txt', 'private-marker/visible', str_repeat('a', 64)]);
        $insertFile->execute([$hiddenFileId, $organizationId, $hiddenVaultId, 'ProfileFixture/hidden.txt', 'hidden-profile-file.txt', 'private-marker/hidden', str_repeat('b', 64)]);

        $otherOrganizationId = fixtureUuid();
        $foreignUserId = fixtureUuid();
        $db->prepare('INSERT INTO organizations (id, name, slug) VALUES (?, ?, ?)')->execute([$otherOrganizationId, 'Profile Summary Foreign Fixture', 'profile-' . bin2hex(random_bytes(6))]);
        $db->prepare('INSERT INTO users (id, email, display_name, password_hash) VALUES (?, ?, ?, ?)')->execute([
            $foreignUserId,
            'foreign-' . bin2hex(random_bytes(6)) . '@profile-summary.test',
            'Foreign Fixture User',
            password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
        ]);
        $db->prepare('INSERT INTO organization_memberships (organization_id, user_id, role) VALUES (?, ?, \'member\')')->execute([$otherOrganizationId, $foreignUserId]);
        $foreignVaultId = fixtureUuid();
        $db->prepare('INSERT INTO vaults (id, organization_id, name, network_root) VALUES (?, ?, ?, ?)')->execute([$foreignVaultId, $otherOrganizationId, 'Profile fixture foreign vault', 'fixture-foreign-root']);
        $foreignFileId = fixtureUuid();
        $insertFile->execute([$foreignFileId, $otherOrganizationId, $foreignVaultId, 'ProfileFixture/foreign.txt', 'foreign-profile-file.txt', 'private-marker/foreign', str_repeat('c', 64)]);

        $insertEvent = $db->prepare('INSERT INTO events (organization_id, type, aggregate_id, payload, created_at) VALUES (?, ?, ?, ?, ?)');
        $ownerDays = [];
        $clock = $db->query(
            "SELECT @@session.time_zone AS sessionTimeZone,
                    DATE_FORMAT(NOW(3), '%Y-%m-%d %H:%i:%s.%f') AS nowDatabaseTime,
                    DATE_FORMAT(DATE_SUB(NOW(3), INTERVAL 1 YEAR), '%Y-%m-%d %H:%i:%s.%f') AS priorYearDatabaseTime,
                    DATE_FORMAT(DATE_SUB('2024-02-29 12:00:00.000000', INTERVAL 1 YEAR), '%Y-%m-%d %H:%i:%s.%f') AS leapDayPriorYear"
        )->fetch();
        if (!is_array($clock) || !is_string($clock['sessionTimeZone'] ?? null)) throw new RuntimeException('Database fixture clock is unavailable.');
        $now = fixtureDatabaseDateTime((string)$clock['nowDatabaseTime']);
        $priorYear = fixtureDatabaseDateTime((string)$clock['priorYearDatabaseTime']);
        $yesterday = $now->modify('-1 day')->setTime(12, 0, 0);
        $today = $now->modify('-30 seconds');
        $insertActorEvent = static function (string $type, string $actorField, string $actorId, DateTimeImmutable $createdAt) use ($insertEvent, $organizationId, $ownerId, &$ownerDays): void {
            $insertEvent->execute([$organizationId, $type, $organizationId, json_encode([$actorField => $actorId], JSON_THROW_ON_ERROR), fixtureTimestamp($createdAt)]);
            if ($actorId === $ownerId) {
                $day = $createdAt->format('Y-m-d');
                $ownerDays[$day] = ($ownerDays[$day] ?? 0) + 1;
            }
        };

        for ($index = 0; $index < 250; $index++) $insertActorEvent('organization.settings_updated', 'updatedBy', $ownerId, $yesterday);
        for ($index = 0; $index < 251; $index++) $insertActorEvent('organization.settings_updated', 'updatedBy', $ownerId, $today);

        $actorCases = [
            ['account.totp_enabled', 'userId'],
            ['user.created', 'createdBy'],
            ['user.credentials_updated', 'changedBy'],
            ['user.removed', 'removedBy'],
            ['team.deleted', 'deletedBy'],
            ['team.member_added', 'addedBy'],
            ['team.module_defaults_cleared', 'clearedBy'],
            ['registration.approved', 'approvedBy'],
            ['registration.rejected', 'rejectedBy'],
            ['organization.serial_number_allocated', 'allocatedBy'],
        ];
        foreach ($actorCases as [$type, $field]) $insertActorEvent($type, $field, $ownerId, $today);

        $boundaryIncluded = $priorYear->modify('+1 hour');
        $boundaryExcluded = $priorYear->modify('-1 hour');
        $futureExcluded = $now->modify('+1 hour');
        $insertActorEvent('organization.settings_updated', 'updatedBy', $ownerId, $boundaryIncluded);
        $insertEvent->execute([$organizationId, 'organization.settings_updated', $organizationId, json_encode(['updatedBy' => $ownerId], JSON_THROW_ON_ERROR), fixtureTimestamp($boundaryExcluded)]);
        $insertEvent->execute([$organizationId, 'organization.settings_updated', $organizationId, json_encode(['updatedBy' => $ownerId], JSON_THROW_ON_ERROR), fixtureTimestamp($futureExcluded)]);

        $insertEvent->execute([$organizationId, 'organization.settings_updated', $organizationId, json_encode(['updatedBy' => $memberId], JSON_THROW_ON_ERROR), fixtureTimestamp($today)]);
        $insertEvent->execute([$organizationId, 'organization.settings_updated', $organizationId, json_encode(['userId' => $ownerId], JSON_THROW_ON_ERROR), fixtureTimestamp($today)]);
        $insertEvent->execute([$organizationId, 'unlisted.private_fixture', $organizationId, json_encode(['updatedBy' => $ownerId], JSON_THROW_ON_ERROR), fixtureTimestamp($today)]);

        foreach ([[$visibleFileId, $ownerId], [$hiddenFileId, $ownerId], [$visibleFileId, $memberId], [$hiddenFileId, $memberId], [$missingFileId, $memberId]] as [$fileId, $actorId]) {
            $insertEvent->execute([$organizationId, 'file.checked_in', $fileId, json_encode(['userId' => $actorId], JSON_THROW_ON_ERROR), fixtureTimestamp($today)]);
            if ($actorId === $ownerId) $ownerDays[$today->format('Y-m-d')] = ($ownerDays[$today->format('Y-m-d')] ?? 0) + 1;
        }
        $insertEvent->execute([$organizationId, 'file.checked_in', $visibleFileId, json_encode(['userId' => $guestId], JSON_THROW_ON_ERROR), fixtureTimestamp($today)]);
        $ownerContributionTypes = ['file.checked_in', 'file.checked_out', 'file.imported'];
        for ($index = 0; $index < 21; $index++) {
            $type = $ownerContributionTypes[$index % count($ownerContributionTypes)];
            $insertEvent->execute([$organizationId, $type, $visibleFileId, json_encode(['userId' => $ownerId], JSON_THROW_ON_ERROR), fixtureTimestamp($today)]);
            $ownerDays[$today->format('Y-m-d')] = ($ownerDays[$today->format('Y-m-d')] ?? 0) + 1;
        }
        foreach ([['file.checked_in', $visibleFileId], ['file.checked_out', $visibleFileId], ['file.imported', $visibleFileId], ['file.checked_out', $hiddenFileId]] as [$type, $fileId]) {
            $insertEvent->execute([$organizationId, $type, $fileId, json_encode(['userId' => $memberId], JSON_THROW_ON_ERROR), fixtureTimestamp($today)]);
        }
        $insertEvent->execute([
            $organizationId,
            'file.workflow_transition_executed',
            $visibleFileId,
            json_encode(['userId' => $ownerId, 'transitionId' => fixtureUuid()], JSON_THROW_ON_ERROR),
            fixtureTimestamp($today),
        ]);
        $ownerDays[$today->format('Y-m-d')] = ($ownerDays[$today->format('Y-m-d')] ?? 0) + 1;

        $workflowId = fixtureUuid();
        $fromStateId = fixtureUuid();
        $toStateId = fixtureUuid();
        $transitionId = fixtureUuid();
        $insertEvent->execute([
            $organizationId,
            'file.workflow_review_requested',
            $visibleFileId,
            json_encode(['fileId' => $visibleFileId, 'transitionId' => $transitionId, 'userId' => $memberId], JSON_THROW_ON_ERROR),
            fixtureTimestamp($today),
        ]);
        $db->prepare('INSERT INTO workflow_templates (id, organization_id, name, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$workflowId, $organizationId, 'Profile contributions fixture workflow', $ownerId]);
        $insertState = $db->prepare('INSERT INTO workflow_states (id, workflow_id, name) VALUES (?, ?, ?)');
        $insertState->execute([$fromStateId, $workflowId, 'Fixture from state']);
        $insertState->execute([$toStateId, $workflowId, 'Fixture to state']);
        $db->prepare('INSERT INTO workflow_transitions (id, workflow_id, from_state_id, to_state_id, waypoints) VALUES (?, ?, ?, ?, ?)')
            ->execute([$transitionId, $workflowId, $fromStateId, $toStateId, '[]']);
        $gateIds = [];
        $insertGate = $db->prepare('INSERT INTO workflow_gates (id, transition_id, name) VALUES (?, ?, ?)');
        for ($index = 1; $index <= 4; $index++) {
            $gateIds[$index] = fixtureUuid();
            $insertGate->execute([$gateIds[$index], $transitionId, 'Profile fixture gate ' . $index]);
        }
        $reviewRows = [
            [$visibleFileId, $gateIds[1], $memberId, $ownerId, 'approved', $ownerId],
            [$visibleFileId, $gateIds[2], $memberId, $memberId, 'pending', null],
            [$visibleFileId, $gateIds[1], $ownerId, $memberId, 'kicked_back', $memberId],
            [$visibleFileId, $gateIds[3], $memberId, $ownerId, 'rejected', $ownerId],
            [$hiddenFileId, $gateIds[4], $memberId, $memberId, 'approved', $memberId],
            [$visibleFileId, $gateIds[4], $guestId, $ownerId, 'pending', null],
        ];
        $roundA = fixtureUuid();
        $roundB = fixtureUuid();
        $roundC = fixtureUuid();
        $roundD = fixtureUuid();
        $roundE = fixtureUuid();
        $rounds = [$roundA, $roundA, $roundB, $roundC, $roundD, $roundE];
        $insertReview = $db->prepare('INSERT INTO pending_reviews
            (id, organization_id, file_id, transition_id, gate_id, review_round_id, requested_by, assigned_to, status, requested_at, reviewed_at, reviewed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($reviewRows as $index => [$fileId, $gateId, $requestedBy, $assignedTo, $status, $reviewedBy]) {
            $reviewedAt = $reviewedBy === null ? null : fixtureTimestamp($today);
            $insertReview->execute([
                fixtureUuid(), $organizationId, $fileId, $transitionId, $gateId, $rounds[$index], $requestedBy,
                $assignedTo, $status, fixtureTimestamp($today), $reviewedAt, $reviewedBy,
            ]);
        }
        $insertReviewRejected = static function (string $fileId, string $actorId) use ($insertEvent, $organizationId, $today, $ownerId, &$ownerDays): void {
            $insertEvent->execute([
                $organizationId,
                'review.rejected',
                fixtureUuid(),
                json_encode(['fileId' => $fileId, 'transitionId' => fixtureUuid(), 'userId' => $actorId], JSON_THROW_ON_ERROR),
                fixtureTimestamp($today),
            ]);
            if ($actorId === $ownerId) $ownerDays[$today->format('Y-m-d')] = ($ownerDays[$today->format('Y-m-d')] ?? 0) + 1;
        };
        $insertReviewRejected($visibleFileId, $ownerId);
        $insertReviewRejected($visibleFileId, $memberId);
        $insertReviewRejected($hiddenFileId, $memberId);
        $insertEvent->execute([$organizationId, 'account.totp_enabled', $organizationId, json_encode(['userId' => $memberId], JSON_THROW_ON_ERROR), fixtureTimestamp($today)]);

        $ecoIds = [];
        $insertEco = $db->prepare('INSERT INTO ecos (id, organization_id, eco_number, title, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        for ($index = 1; $index <= 6; $index++) {
            $ecoNumber = 'PROFILE-OWNER-' . $index;
            $ecoId = fixtureUuid();
            $insertEco->execute([$ecoId, $organizationId, $ecoNumber, 'Fixture ECO ' . $index, 'open', $ownerId, fixtureTimestamp($now->modify('-' . ($index - 1) . ' days'))]);
            $ecoIds[] = ['id' => $ecoId, 'number' => $ecoNumber];
        }
        $memberEcoId = fixtureUuid();
        $insertEco->execute([$memberEcoId, $organizationId, 'PROFILE-MEMBER-1', 'Member Fixture ECO', 'open', $memberId, fixtureTimestamp($now)]);

        $insertFileEco = $db->prepare('INSERT INTO file_ecos (id, file_id, eco_id, created_by) VALUES (?, ?, ?, ?)');
        $insertFileEco->execute([fixtureUuid(), $visibleFileId, $ecoIds[0]['id'], $ownerId]);
        $ownerAssociatedEcoNumbers = [];
        for ($index = 1; $index <= 5; $index++) {
            $ecoId = fixtureUuid();
            $ecoNumber = 'PROFILE-OWNER-LINKED-' . $index;
            $insertEco->execute([$ecoId, $organizationId, $ecoNumber, 'Linked ECO ' . $index, 'open', $memberId, fixtureTimestamp($now->modify('-' . ($index + 5) . ' days'))]);
            $insertFileEco->execute([fixtureUuid(), $visibleFileId, $ecoId, $ownerId]);
            $ownerAssociatedEcoNumbers[] = $ecoNumber;
        }

        $memberVisibleEcoId = fixtureUuid();
        $insertEco->execute([$memberVisibleEcoId, $organizationId, 'PROFILE-MEMBER-LINKED-VISIBLE', 'Member visible linked ECO', 'open', $ownerId, fixtureTimestamp($now->modify('-11 days'))]);
        $insertFileEco->execute([fixtureUuid(), $visibleFileId, $memberVisibleEcoId, $memberId]);
        $guestLinkedEcoId = fixtureUuid();
        $insertEco->execute([$guestLinkedEcoId, $organizationId, 'PROFILE-GUEST-LINKED', 'Guest linked ECO', 'open', $ownerId, fixtureTimestamp($now->modify('-13 days'))]);
        $insertFileEco->execute([fixtureUuid(), $visibleFileId, $guestLinkedEcoId, $guestId]);
        $memberHiddenEcoId = fixtureUuid();
        $insertEco->execute([$memberHiddenEcoId, $organizationId, 'PROFILE-MEMBER-LINKED-HIDDEN', 'Member hidden linked ECO', 'open', $ownerId, fixtureTimestamp($now->modify('-12 days'))]);
        $insertFileEco->execute([fixtureUuid(), $hiddenFileId, $memberHiddenEcoId, $memberId]);

        $foreignEcoId = fixtureUuid();
        $insertEco->execute([$foreignEcoId, $otherOrganizationId, 'PROFILE-MEMBER-FOREIGN-LINKED', 'Foreign linked ECO', 'open', $memberId, fixtureTimestamp($now)]);
        $insertFileEco->execute([fixtureUuid(), $foreignFileId, $foreignEcoId, $memberId]);

        $insertDevice = $db->prepare('INSERT INTO device_sessions (id, user_id, organization_id, machine_id, last_seen) VALUES (?, ?, ?, ?, ?)');
        $insertDevice->execute([fixtureUuid(), $ownerId, $organizationId, 'profile-fixture-device-old', fixtureTimestamp($now->modify('-10 minutes'))]);
        $latestDeviceSeen = $now->modify('-30 seconds');
        $insertDevice->execute([fixtureUuid(), $ownerId, $organizationId, 'profile-fixture-device-latest', fixtureTimestamp($latestDeviceSeen)]);

        $db->commit();
        ksort($ownerDays);
        return [
            'ownerId' => $ownerId,
            'memberId' => $memberId,
            'guestId' => $guestId,
            'foreignUserId' => $foreignUserId,
            'databaseSessionTimeZone' => $clock['sessionTimeZone'],
            'leapDayPriorYear' => $clock['leapDayPriorYear'],
            'ownerExpectedCount' => '537',
            'ownerDailyCounts' => array_map(static fn(string $day, int $count): array => ['day' => $day, 'count' => (string)$count], array_keys($ownerDays), array_values($ownerDays)),
            'memberExpectedCount' => '8',
            'ownerViewMemberExpectedCount' => '11',
            'contributionDay' => $today->format('Y-m-d'),
            'visibleFileName' => 'visible-profile-file.txt',
            'hiddenFileName' => 'hidden-profile-file.txt',
            'visibleVaultId' => $visibleVaultId,
            'guestLinkedEcoNumber' => 'PROFILE-GUEST-LINKED',
            'ecoNumbers' => array_merge(array_column($ecoIds, 'number'), array_slice($ownerAssociatedEcoNumbers, 0, 4)),
            'memberEcoNumber' => 'PROFILE-MEMBER-1',
            'memberVisibleEcoNumber' => 'PROFILE-MEMBER-LINKED-VISIBLE',
            'memberHiddenEcoNumber' => 'PROFILE-MEMBER-LINKED-HIDDEN',
            'memberExpectedEcoNumbers' => array_merge(['PROFILE-MEMBER-1'], $ownerAssociatedEcoNumbers, ['PROFILE-MEMBER-LINKED-VISIBLE']),
            'ownerViewMemberExpectedEcoNumbers' => array_merge(['PROFILE-MEMBER-1'], $ownerAssociatedEcoNumbers, ['PROFILE-MEMBER-LINKED-VISIBLE', 'PROFILE-MEMBER-LINKED-HIDDEN']),
            'deviceLastSeen' => fixtureTimestamp($latestDeviceSeen) . '000',
        ];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

try {
    if (($argv[1] ?? null) !== 'seed' || !isset($argv[2], $argv[3])) throw new RuntimeException('Fixture arguments are invalid.');
    if (!isset($argv[5])) throw new RuntimeException('Guest fixture argument is required.');
    $result = seedProfileSummary(fixtureDatabase(), $argv[2], $argv[3], $argv[4] ?? '', $argv[5]);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Profile summary fixture failed (' . get_class($error) . ').' . PHP_EOL);
    exit(2);
}
