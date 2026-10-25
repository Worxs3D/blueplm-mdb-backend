<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use RuntimeException;

final class Migrator
{
    /** @return list<string> */
    public static function apply(PDO $db, string $migrationsDirectory): array
    {
        // DDL statements are deliberately tracked separately from the domain
        // tables. Without this ledger, a second /admin/migrate call reruns
        // every ALTER TABLE and turns an otherwise completed deployment into a
        // duplicate-column failure.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(255) NOT NULL PRIMARY KEY,
                applied_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $appliedRows = $db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $alreadyApplied = array_fill_keys($appliedRows, true);
        $files = glob(rtrim($migrationsDirectory, '/\\') . '/[0-9]*_*.sql') ?: [];
        sort($files, SORT_NATURAL);

        $applied = [];
        foreach ($files as $file) {
            $migration = basename($file);
            if (isset($alreadyApplied[$migration])) continue;
            $sql = file_get_contents($file);
            if ($sql === false) throw new RuntimeException("Cannot read {$file}.");
            try {
                if ($migration === '029_module_defaults.sql') {
                    self::ensureModuleDefaultsColumns($db);
                } elseif ($migration === '049_admin_only_folders.sql') {
                    self::ensureAdminOnlyFoldersPolicy($db);
                } elseif ($migration === '035_checkin_metadata_idempotency.sql') {
                    self::ensureCheckinMetadataColumns($db);
                    $db->exec($sql);
                } elseif ($migration === '038_checkin_request_retention_indexes.sql') {
                    self::ensureCheckinRequestRetentionIndexes($db);
                    $db->exec($sql);
                } elseif ($migration === '039_checkout_request_retention_indexes.sql') {
                    self::ensureCheckoutRequestRetentionIndexes($db);
                    $db->exec($sql);
                } elseif ($migration === '041_workflow_review_rounds.sql') {
                    self::ensureWorkflowReviewRounds($db);
                } else {
                    $db->exec($sql);
                }
                if ($migration === '037_checkout_user_index.sql') self::ensureCheckoutUserIndex($db);
                if ($migration === '015_eco_metadata.sql') self::ensureEcoMetadataConstraints($db);
                if ($migration === '024_file_part_numbers.sql') self::ensureFilePartNumberConstraint($db);
                if ($migration === '032_backup_machine_proof.sql') self::ensureBackupMachineProofColumn($db);
            } catch (\Throwable $error) {
                // The route may safely identify the migration to a holder of
                // the bootstrap secret, while the underlying database error
                // remains confined to the server log.
                throw new RuntimeException("Migration {$migration} failed.", previous: $error);
            }
            $db->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$migration]);
            $applied[] = $migration;
        }
        return $applied;
    }

    private static function ensureEcoMetadataConstraints(PDO $db): void
    {
        self::addForeignKeyIfMissing(
            $db,
            'fk_eco_creator',
            'ALTER TABLE ecos ADD CONSTRAINT fk_eco_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL'
        );
        self::addForeignKeyIfMissing(
            $db,
            'fk_eco_updater',
            'ALTER TABLE ecos ADD CONSTRAINT fk_eco_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL'
        );
    }

    private static function ensureFilePartNumberConstraint(PDO $db): void
    {
        $query = $db->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $query->execute(['files', 'uq_files_org_part_number']);
        if (!$query->fetchColumn()) {
            $db->exec('ALTER TABLE files ADD UNIQUE KEY uq_files_org_part_number (organization_id, part_number)');
        }
    }

    private static function ensureCheckoutUserIndex(PDO $db): void
    {
        $query = $db->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1
             LIMIT 1'
        );
        $query->execute(['checkouts', 'user_id']);
        if (!$query->fetchColumn()) {
            $db->exec('ALTER TABLE checkouts ADD INDEX idx_checkout_user_id (user_id)');
        }
    }

    private static function ensureCheckinRequestRetentionIndexes(PDO $db): void
    {
        $indexes = [
            ['idx_file_checkin_requests_user_scope', 'user_id, created_at'],
            ['idx_file_checkin_requests_org_scope', 'organization_id, created_at'],
            ['idx_file_checkin_requests_created', 'created_at'],
        ];
        $query = $db->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        foreach ($indexes as [$name, $columns]) {
            $query->execute(['file_checkin_requests', $name]);
            if (!$query->fetchColumn()) $db->exec("ALTER TABLE file_checkin_requests ADD INDEX `{$name}` ({$columns})");
        }
    }

    private static function ensureCheckoutRequestRetentionIndexes(PDO $db): void
    {
        $indexes = [
            ['idx_checkout_request_user_retention', 'user_id, status, updated_at'],
            ['idx_checkout_request_org_retention', 'organization_id, status, updated_at'],
            ['idx_checkout_request_file_retention', 'file_id, status, updated_at'],
            ['idx_checkout_request_terminal_retention', 'updated_at, status'],
            ['idx_checkout_request_active_expiry', 'status, expires_at, file_id'],
        ];
        $query = $db->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        foreach ($indexes as [$name, $columns]) {
            $query->execute(['checkout_acquisition_requests', $name]);
            if (!$query->fetchColumn()) $db->exec("ALTER TABLE checkout_acquisition_requests ADD INDEX `{$name}` ({$columns})");
        }
    }

    private static function ensureWorkflowReviewRounds(PDO $db): void
    {
        $columnExists = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        foreach ([
            'review_round_id' => 'CHAR(36) NULL',
            'is_legacy_round' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'legacy_round_ambiguous' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ] as $column => $definition) {
            $columnExists->execute(['pending_reviews', $column]);
            if (!$columnExists->fetchColumn()) {
                $db->exec("ALTER TABLE pending_reviews ADD COLUMN `{$column}` {$definition}");
            }
        }

        // Old rows have no trustworthy submission boundary. Keep every row and
        // group legacy records only into an explicitly ambiguous migration
        // bucket for this file/transition; new submissions always get a fresh
        // server-generated round id.
        $db->exec('DROP TEMPORARY TABLE IF EXISTS workflow_review_round_backfill');
        $db->exec(
            "CREATE TEMPORARY TABLE workflow_review_round_backfill AS
             SELECT file_id, transition_id, UUID() AS review_round_id,
                    MAX(status = 'pending') AS had_pending,
                    MAX(status <> 'pending') AS had_terminal
             FROM pending_reviews WHERE review_round_id IS NULL
             GROUP BY file_id, transition_id"
        );
        $db->exec(
            'UPDATE pending_reviews AS review
             JOIN workflow_review_round_backfill AS legacy
               ON legacy.file_id = review.file_id AND legacy.transition_id = review.transition_id
             SET review.review_round_id = legacy.review_round_id,
                 review.is_legacy_round = 1,
                 review.legacy_round_ambiguous = (legacy.had_pending = 1 AND legacy.had_terminal = 1)
             WHERE review.review_round_id IS NULL'
        );
        $db->exec('DROP TEMPORARY TABLE workflow_review_round_backfill');

        $unassigned = (int)$db->query('SELECT COUNT(*) FROM pending_reviews WHERE review_round_id IS NULL')->fetchColumn();
        if ($unassigned !== 0) throw new RuntimeException('Legacy workflow review rounds could not be assigned safely.');
        $db->exec('ALTER TABLE pending_reviews MODIFY review_round_id CHAR(36) NOT NULL');

        $indexExists = $db->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        $indexExists->execute(['pending_reviews', 'uq_pending_review_round_assignee']);
        if (!$indexExists->fetchColumn()) {
            $db->exec(
                'ALTER TABLE pending_reviews
                 ADD UNIQUE KEY uq_pending_review_round_assignee
                   (file_id, transition_id, gate_id, review_round_id, assigned_to)'
            );
        }
        $indexExists->execute(['pending_reviews', 'uq_pending_review_assignee']);
        if ($indexExists->fetchColumn()) $db->exec('ALTER TABLE pending_reviews DROP INDEX uq_pending_review_assignee');

        $indexExists->execute(['pending_reviews', 'idx_pending_review_round_scope']);
        if (!$indexExists->fetchColumn()) {
            $db->exec(
                'ALTER TABLE pending_reviews
                 ADD INDEX idx_pending_review_round_scope
                   (file_id, transition_id, review_round_id, gate_id, status)'
            );
        }
    }

    /** MariaDB versions supported by the product do not all support ADD COLUMN
     * IF NOT EXISTS. The migration ledger makes this metadata check idempotent. */
    private static function ensureBackupMachineProofColumn(PDO $db): void
    {
        $query = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $query->execute(['backup_config', 'designated_machine_proof_hash']);
        if (!$query->fetchColumn()) {
            $db->exec('ALTER TABLE backup_config ADD COLUMN designated_machine_proof_hash CHAR(64) NULL AFTER designated_machine_last_seen');
        }
    }

    private static function ensureCheckinMetadataColumns(PDO $db): void
    {
        $columns = [
            'files' => [
                'description' => 'TEXT NULL',
                'revision_label' => 'VARCHAR(512) NULL',
                'custom_properties' => 'JSON NULL',
            ],
            'file_revisions' => [
                'part_number' => 'VARCHAR(512) NULL',
                'description' => 'TEXT NULL',
                'revision_label' => 'VARCHAR(512) NULL',
                'custom_properties' => 'JSON NULL',
            ],
        ];
        $exists = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        foreach ($columns as $table => $definitions) {
            foreach ($definitions as $column => $definition) {
                $exists->execute([$table, $column]);
                if (!$exists->fetchColumn()) $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }

    private static function ensureModuleDefaultsColumns(PDO $db): void
    {
        $columns = [
            ['teams', 'module_defaults', 'JSON NULL'],
            ['teams', 'module_defaults_forced_at', 'DATETIME(3) NULL'],
            ['organization_settings', 'module_defaults', 'JSON NULL'],
            ['organization_settings', 'module_defaults_forced_at', 'DATETIME(3) NULL'],
        ];
        $query = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        foreach ($columns as [$table, $column, $definition]) {
            $query->execute([$table, $column]);
            if (!$query->fetchColumn()) {
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }

    private static function ensureAdminOnlyFoldersPolicy(PDO $db): void
    {
        $exists = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $exists->execute(['organization_settings', 'admin_only_folders']);
        if (!$exists->fetchColumn()) {
            $db->exec('ALTER TABLE organization_settings ADD COLUMN admin_only_folders JSON NULL');
        }

        // This feature had no prior MDB storage. Initialize only during migration;
        // request-time reads deliberately treat NULL/missing policy as uninitialized.
        $db->exec(
            'INSERT INTO organization_settings (organization_id, admin_only_folders)
             SELECT o.id, JSON_ARRAY()
             FROM organizations o
             LEFT JOIN organization_settings s ON s.organization_id = o.id
             WHERE s.organization_id IS NULL'
        );
        $db->exec('UPDATE organization_settings SET admin_only_folders = JSON_ARRAY() WHERE admin_only_folders IS NULL');
    }

    private static function addForeignKeyIfMissing(PDO $db, string $name, string $statement): void
    {
        $query = $db->prepare(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
        );
        $query->execute(['ecos', $name]);
        if (!$query->fetchColumn()) $db->exec($statement);
    }
}
