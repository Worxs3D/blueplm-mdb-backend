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
                } else {
                    $db->exec($sql);
                }
                if ($migration === '015_eco_metadata.sql') self::ensureEcoMetadataConstraints($db);
                if ($migration === '024_file_part_numbers.sql') self::ensureFilePartNumberConstraint($db);
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
