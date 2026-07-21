<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Versionierte Migrationen: migrations/NNN_name.sql läuft genau einmal
 * und wird in schema_migrations protokolliert.
 *
 * Während der Migrationen wird die Foreign-Key-Durchsetzung deaktiviert,
 * damit Tabellen-Rebuilds (CREATE new / copy / DROP / RENAME) möglich sind,
 * ohne dass referenzierende Kindtabellen den DROP blockieren. Nach Abschluss
 * prüft PRAGMA foreign_key_check die Integrität, bevor FK wieder aktiviert wird.
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

        $pending = [];
        foreach ($files as $file) {
            $base = basename($file);
            if (!preg_match('/^(\d{3})_([a-z0-9_]+)\.sql$/', $base, $m)) {
                throw new RuntimeException("Ungültiger Migrationsdateiname: $base");
            }
            $version = (int) $m[1];
            if (!in_array($version, $applied, true)) {
                $pending[] = [$version, $m[2], $file];
            }
        }

        if ($pending === []) {
            return;
        }

        // FK-Enforcement ist nur ausserhalb einer Transaktion umschaltbar.
        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            foreach ($pending as [$version, $name, $file]) {
                $sql = (string) file_get_contents($file);
                $pdo->exec('BEGIN');
                try {
                    $pdo->exec($sql);
                    $stm = $pdo->prepare('INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, ?)');
                    $stm->execute([$version, $name, Database::now()]);
                    $pdo->exec('COMMIT');
                } catch (\Throwable $e) {
                    $pdo->exec('ROLLBACK');
                    throw new RuntimeException("Migration " . basename($file) . " fehlgeschlagen: " . $e->getMessage(), 0, $e);
                }
            }

            $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
            if ($violations !== []) {
                throw new RuntimeException('Foreign-Key-Verletzung nach Migration: ' . json_encode($violations));
            }
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}
