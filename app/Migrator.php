<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Versionierte Migrationen: migrations/NNN_name.sql läuft genau einmal
 * und wird in schema_migrations protokolliert.
 */
final class Migrator
{
    public static function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version    INTEGER PRIMARY KEY,
            name       TEXT NOT NULL,
            applied_at TEXT NOT NULL
        )');

        $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = array_map('intval', $applied);

        $dir = APP_ROOT . '/migrations';
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $base = basename($file);
            if (!preg_match('/^(\d{3})_([a-z0-9_]+)\.sql$/', $base, $m)) {
                throw new RuntimeException("Ungültiger Migrationsdateiname: $base");
            }
            $version = (int) $m[1];
            if (in_array($version, $applied, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            $pdo->exec('BEGIN');
            try {
                $pdo->exec($sql);
                $stm = $pdo->prepare('INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, ?)');
                $stm->execute([$version, $m[2], Database::now()]);
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw new RuntimeException("Migration $base fehlgeschlagen: " . $e->getMessage(), 0, $e);
            }
        }
    }
}
