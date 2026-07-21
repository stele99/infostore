<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $path = Config::get('db_path');
            if ($path !== ':memory:') {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0770, true);
                }
            }
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo = $pdo;
            Migrator::migrate($pdo);
        }
        return self::$pdo;
    }

    /** Nur für Tests: Verbindung verwerfen, damit ein anderer db_path greift. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
