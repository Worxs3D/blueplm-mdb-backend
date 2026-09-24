<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use RuntimeException;

/** Database inspection and deliberate reset for the desktop installer. */
final class DatabaseLifecycle
{
    /** @return array{state:string,tableCount:int,bootstrapped:bool,appliedMigrations:int,pendingMigrations:int} */
    public static function inspect(PDO $db, string $migrationsDirectory): array
    {
        $tables = $db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $tableNames = array_fill_keys(array_map('strval', $tables), true);
        $migrationFiles = glob(rtrim($migrationsDirectory, '/\\') . '/[0-9]*_*.sql') ?: [];
        $availableMigrations = array_map('basename', $migrationFiles);
        sort($availableMigrations, SORT_NATURAL);

        if ($tableNames === []) {
            return [
                'state' => 'empty',
                'tableCount' => 0,
                'bootstrapped' => false,
                'appliedMigrations' => 0,
                'pendingMigrations' => count($availableMigrations),
            ];
        }

        $managed = isset($tableNames['schema_migrations']);
        $bluePlmCore = isset($tableNames['organizations'], $tableNames['users'], $tableNames['vaults']);
        $applied = [];
        if ($managed) {
            $applied = array_map(
                'strval',
                $db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN),
            );
        }
        $bootstrapped = false;
        if (isset($tableNames['installation_state'])) {
            $row = $db->query('SELECT bootstrapped_at FROM installation_state WHERE singleton_id = 1')->fetch();
            $bootstrapped = is_array($row) && $row['bootstrapped_at'] !== null;
        } elseif (isset($tableNames['organizations'])) {
            $bootstrapped = (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn() > 0;
        }

        return [
            'state' => $managed ? 'managed' : ($bluePlmCore ? 'legacy' : 'foreign'),
            'tableCount' => count($tableNames),
            'bootstrapped' => $bootstrapped,
            'appliedMigrations' => count($applied),
            'pendingMigrations' => count(array_diff($availableMigrations, $applied)),
        ];
    }

    public static function reset(PDO $db, string $confirmation): void
    {
        if (!hash_equals('DELETE ALL DATABASE DATA', $confirmation)) {
            throw new RuntimeException('The database reset confirmation is invalid.');
        }

        $views = $db->query(
            "SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()"
        )->fetchAll(PDO::FETCH_COLUMN);
        $tables = $db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($views as $view) {
                $db->exec('DROP VIEW IF EXISTS ' . self::identifier((string)$view));
            }
            foreach ($tables as $table) {
                $db->exec('DROP TABLE IF EXISTS ' . self::identifier((string)$table));
            }
        } finally {
            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function identifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
