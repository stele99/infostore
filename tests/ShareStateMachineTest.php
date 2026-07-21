<?php

declare(strict_types=1);

use App\Config;
use App\Mail\FileMailer;
use App\RateLimiter;
use App\Repository\ShareRepository;
use App\Service\ShareService;

const SHARE_UID = 'ssssssss-1111-4111-8111-111111111111';

function makeShares(): ShareService
{
    $db = App\Database::connection();
    return new ShareService(new ShareRepository($db), new RateLimiter($db), new FileMailer());
}

function shareStore(): int
{
    $db = freshDb();
    $db->exec("INSERT INTO stores (name, auth_hash, kdf_version, kdf_salt, kdf_time_cost, created_at)
               VALUES ('store.owner', 'h', 1, 's', 600000, 'x'), ('store.other', 'h', 1, 's', 600000, 'x')");
    return 1;
}

function seedAuthB64(string $seed = 'seed'): string
{
    return base64_encode(hash('sha256', $seed, true));
}

function createTestShare(ShareService $svc, int $storeId, int $delayHours, string $uid = 'ssssssss-0000-4000-8000-000000000000'): string
{
    $svc->create($storeId, [
        'share_uid'      => $uid,
        'crypto_version' => 1,
        'wrapped_key'    => base64_encode(random_bytes(48)),
        'wrap_iv'        => base64_encode(random_bytes(12)),
        'kdf_salt'       => base64_encode(random_bytes(16)),
        'kdf_iterations' => 600000,
        'seed_auth'      => seedAuthB64(),
        'owner_mail'     => 'owner@example.org',
        'delay_hours'    => $delayHours,
    ]);
    return $uid;
}

t('Shares: falscher Seed-Nachweis liefert 401 und kein Key-Paket', function () {
    $store = shareStore();
    $svc = makeShares();
    $uid = createTestShare($svc, $store, 0);
    assert_api_error(401, fn () => $svc->request('store.owner', $uid, seedAuthB64('falsch'), '1.1.1.1'));
});

t('Shares: delay=0 gewährt sofort mit Key-Paket', function () {
    $store = shareStore();
    $svc = makeShares();
    $uid = createTestShare($svc, $store, 0);
    $r = $svc->request('store.owner', $uid, seedAuthB64(), '1.1.1.1');
    assert_eq('granted', $r['status']);
    assert_true(isset($r['wrapped_key'], $r['wrap_iv']), 'Key-Paket fehlt');
});

t('Shares: mit Wartezeit erst requested, Freigabe erst nach Serverzeit', function () {
    $store = shareStore();
    $svc = makeShares();
    $uid = createTestShare($svc, $store, 2);

    $r1 = $svc->request('store.owner', $uid, seedAuthB64(), '1.1.1.1');
    assert_eq('requested', $r1['status']);
    assert_true(!isset($r1['wrapped_key']), 'Vor Ablauf darf kein Key-Paket kommen');

    // Erneuter Poll vor Ablauf: weiterhin requested
    $r2 = $svc->request('store.owner', $uid, seedAuthB64(), '1.1.1.1');
    assert_eq('requested', $r2['status']);

    // Serverzeit simulieren: available_at in die Vergangenheit legen
    App\Database::connection()->exec("UPDATE shares SET available_at = '2020-01-01T00:00:00Z' WHERE share_uid = '$uid'");
    $r3 = $svc->request('store.owner', $uid, seedAuthB64(), '1.1.1.1');
    assert_eq('granted', $r3['status']);
    assert_true(isset($r3['wrapped_key']));
});

t('Shares: Anfrage benachrichtigt den Owner per Mail', function () {
    $store = shareStore();
    $mailDir = Config::get('mail_dir');
    array_map('unlink', glob("$mailDir/*.eml") ?: []);
    $svc = makeShares();
    $uid = createTestShare($svc, $store, 2);
    $svc->request('store.owner', $uid, seedAuthB64(), '1.1.1.1');
    assert_true(count(glob("$mailDir/*.eml") ?: []) === 1, 'Mail-Datei fehlt');
});

t('Shares: Owner kann laufende Anfrage ablehnen; danach kein Zugriff', function () {
    $store = shareStore();
    $svc = makeShares();
    $uid = createTestShare($svc, $store, 2);
    $svc->request('store.owner', $uid, seedAuthB64(), '1.1.1.1');

    $svc->deny($store, $uid);
    $r = $svc->request('store.owner', $uid, seedAuthB64(), '1.1.1.1');
    assert_eq('denied', $r['status']);
    assert_true(!isset($r['wrapped_key']));
});

t('Shares: Ablehnen ist nur aus requested möglich', function () {
    $store = shareStore();
    $svc = makeShares();
    $uid = createTestShare($svc, $store, 2);
    assert_api_error(409, fn () => $svc->deny($store, $uid), 'active darf nicht abgelehnt werden');
});

t('Shares: Widerruf greift auch nach Grant', function () {
    $store = shareStore();
    $svc = makeShares();
    $uid = createTestShare($svc, $store, 0);
    assert_eq('granted', $svc->request('store.owner', $uid, seedAuthB64(), '1.1.1.1')['status']);

    $svc->revoke($store, $uid);
    $r = $svc->request('store.owner', $uid, seedAuthB64(), '1.1.1.1');
    assert_eq('revoked', $r['status']);
    assert_true(!isset($r['wrapped_key']));
});

t('Shares: fremder Store kann weder ablehnen noch widerrufen (404)', function () {
    $store = shareStore();
    $svc = makeShares();
    $uid = createTestShare($svc, $store, 2);
    $svc->request('store.owner', $uid, seedAuthB64(), '1.1.1.1');
    assert_api_error(404, fn () => $svc->deny(2, $uid));
    assert_api_error(404, fn () => $svc->revoke(2, $uid));
});

t('Shares: Salt-Abfrage liefert Decoy für Stores ohne Shares', function () {
    shareStore();
    $svc = makeShares();
    $a = $svc->saltsForStore('unbekannt.store', '1.1.1.1');
    $b = $svc->saltsForStore('unbekannt.store', '1.1.1.1');
    assert_eq(1, count($a));
    assert_eq($a[0]['kdf_salt'], $b[0]['kdf_salt'], 'Decoy muss deterministisch sein');
});

t('Shares: Audit-Events werden geschrieben', function () {
    $store = shareStore();
    $svc = makeShares();
    $uid = createTestShare($svc, $store, 2);
    $svc->request('store.owner', $uid, seedAuthB64(), '1.1.1.1');
    $svc->deny($store, $uid);

    $db = App\Database::connection();
    $events = $db->query('SELECT event FROM share_events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('created', $events, true), 'created-Event fehlt');
    assert_true(in_array('requested', $events, true), 'requested-Event fehlt');
    assert_true(in_array('denied', $events, true), 'denied-Event fehlt');
});
