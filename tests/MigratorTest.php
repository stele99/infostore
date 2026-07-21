<?php

declare(strict_types=1);

use App\Config;
use App\Database;
use App\Migrator;

t('Migration: frische Datenbank wird vollständig aufgebaut', function () {
    $db = freshDb();
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach (['stores', 'entries', 'shares', 'share_events', 'login_attempts', 'schema_migrations'] as $t) {
        assert_true(in_array($t, $tables, true), "Tabelle $t fehlt");
    }
    // Nach Migration 002 hat stores die Argon2-KDF-Spalten und keine kdf_iterations mehr.
    $cols = $db->query('PRAGMA table_info(stores)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['kdf_version', 'kdf_salt', 'kdf_time_cost', 'kdf_memory', 'kdf_parallelism'] as $col) {
        assert_true(in_array($col, $cols, true), "Spalte stores.$col fehlt");
    }
    assert_true(!in_array('kdf_iterations', $cols, true), 'kdf_iterations sollte nach Migration weg sein');
});

t('Migration: v1-Store übersteht den 002-Rebuild mit erhaltenen FKs', function () {
    // Baut das Schema bis Migration 001 nach, fügt einen v1-Store + Eintrag ein
    // und prüft, dass der stores-Rebuild in 002 die Daten und den entries-FK erhält.
    Database::reset();
    $path = Config::get('db_path');
    foreach ([$path, "$path-wal", "$path-shm"] as $f) {
        if (file_exists($f)) {
            unlink($f);
        }
    }
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');

    // Nur Migration 001 anwenden (altes stores-Schema mit kdf_iterations).
    $db->exec('CREATE TABLE schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)');
    $db->exec((string) file_get_contents(APP_ROOT . '/migrations/001_init.sql'));
    $db->exec("INSERT INTO schema_migrations VALUES (1, 'init', '2026-01-01T00:00:00Z')");
    $db->exec("INSERT INTO stores (name, auth_hash, kdf_salt, kdf_iterations, kdf_version, created_at)
               VALUES ('store.v1', 'h', 's', 600000, 1, '2026-01-01T00:00:00Z')");
    $db->exec("INSERT INTO entries (store_id, entry_uid, title_ct, title_iv, body_ct, body_iv, created_at, updated_at)
               VALUES (1, '22222222-2222-4222-8222-222222222222', 't', 'i', 'b', 'i', 'x', 'x')");

    // Jetzt Migration 002 (und alles Ausstehende) anwenden.
    Migrator::migrate($db);

    $store = $db->query("SELECT * FROM stores WHERE name = 'store.v1'")->fetch(PDO::FETCH_ASSOC);
    assert_eq(1, (int) $store['kdf_version']);
    assert_eq(600000, (int) $store['kdf_time_cost']);
    assert_eq(null, $store['kdf_memory']);

    // FK muss noch greifen: Eintrag verweist weiter auf den Store.
    $cnt = (int) $db->query('SELECT COUNT(*) FROM entries WHERE store_id = ' . (int) $store['id'])->fetchColumn();
    assert_eq(1, $cnt);
    $viol = $db->query('PRAGMA foreign_key_check')->fetchAll();
    assert_eq(0, count($viol), 'keine FK-Verletzung nach Rebuild');
});

t('Migration: läuft nur einmal (idempotent)', function () {
    $db = freshDb();
    Migrator::migrate($db);
    Migrator::migrate($db);
    // Erwartet: so viele Einträge wie es Migrationsdateien gibt.
    $expected = count(glob(APP_ROOT . '/migrations/*.sql'));
    $count = (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    assert_eq($expected, $count);
});

t('Schema: Constraints greifen (Status-Check, Unique-UID)', function () {
    $db = freshDb();
    $db->exec("INSERT INTO stores (name, auth_hash, kdf_version, kdf_salt, kdf_time_cost, created_at)
               VALUES ('teststore', 'h', 1, 's', 600000, '2026-01-01T00:00:00Z')");
    $threw = false;
    try {
        $db->exec("INSERT INTO shares (share_uid, store_id, wrapped_key, wrap_iv, kdf_salt, kdf_iterations,
                   seed_auth_hash, owner_mail, delay_hours, status, created_at)
                   VALUES ('00000000-0000-4000-8000-000000000000', 1, 'w', 'i', 's', 600000, 'h', 'a@b.de', 1,
                           'kaputt', '2026-01-01T00:00:00Z')");
    } catch (PDOException) {
        $threw = true;
    }
    assert_true($threw, 'Ungültiger Share-Status hätte scheitern müssen');

    $db->exec("INSERT INTO entries (store_id, entry_uid, title_ct, title_iv, body_ct, body_iv, created_at, updated_at)
               VALUES (1, '11111111-1111-4111-8111-111111111111', 't', 'i', 'b', 'i', 'x', 'x')");
    $threw = false;
    try {
        $db->exec("INSERT INTO entries (store_id, entry_uid, title_ct, title_iv, body_ct, body_iv, created_at, updated_at)
                   VALUES (1, '11111111-1111-4111-8111-111111111111', 't', 'i', 'b', 'i', 'x', 'x')");
    } catch (PDOException) {
        $threw = true;
    }
    assert_true($threw, 'Doppelte entry_uid hätte scheitern müssen');
});
