<?php

declare(strict_types=1);

use App\Migrator;

t('Migration: frische Datenbank wird vollständig aufgebaut', function () {
    $db = freshDb();
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach (['stores', 'entries', 'shares', 'share_events', 'login_attempts', 'schema_migrations'] as $t) {
        assert_true(in_array($t, $tables, true), "Tabelle $t fehlt");
    }
});

t('Migration: läuft nur einmal (idempotent)', function () {
    $db = freshDb();
    Migrator::migrate($db);
    Migrator::migrate($db);
    $count = (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    assert_eq(1, $count);
});

t('Schema: Constraints greifen (Status-Check, Unique-UID)', function () {
    $db = freshDb();
    $db->exec("INSERT INTO stores (name, auth_hash, kdf_salt, kdf_iterations, created_at)
               VALUES ('teststore', 'h', 's', 600000, '2026-01-01T00:00:00Z')");
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
