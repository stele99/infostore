<?php

declare(strict_types=1);

use App\Config;
use App\Repository\EntryRepository;
use App\Service\EntryService;

const UID_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

function makeEntries(): EntryService
{
    return new EntryService(new EntryRepository(App\Database::connection()));
}

function twoStores(): array
{
    $db = freshDb();
    $db->exec("INSERT INTO stores (name, auth_hash, kdf_version, kdf_salt, kdf_time_cost, created_at)
               VALUES ('store.a', 'h', 1, 's', 600000, 'x'), ('store.b', 'h', 1, 's', 600000, 'x')");
    return [1, 2];
}

function fields(string $suffix = ''): array
{
    return [
        'title_ct' => base64_encode("titel$suffix"),
        'title_iv' => base64_encode(random_bytes(12)),
        'body_ct'  => base64_encode("inhalt$suffix"),
        'body_iv'  => base64_encode(random_bytes(12)),
    ];
}

t('Entries: Anlegen, Lesen, Liste nur im eigenen Store', function () {
    [$a, $b] = twoStores();
    $svc = makeEntries();
    $svc->save($a, UID_A, 1, fields(), null);

    assert_eq(1, $svc->list($a, 100, 0)['total']);
    assert_eq(0, $svc->list($b, 100, 0)['total'], 'Fremder Store darf die Liste nicht sehen');
    assert_eq(UID_A, $svc->get($a, UID_A)['entry_uid']);
    assert_api_error(404, fn () => $svc->get($b, UID_A), 'Fremder Store darf nicht lesen');
});

t('Entries: fremde UID kann nicht überschrieben oder übernommen werden', function () {
    [$a, $b] = twoStores();
    $svc = makeEntries();
    $svc->save($a, UID_A, 1, fields(), null);

    assert_api_error(404, fn () => $svc->save($b, UID_A, 1, fields('böse'), null));
    $row = $svc->get($a, UID_A);
    assert_eq(base64_encode('titel'), $row['title_ct'], 'Inhalt darf nicht verändert sein');
});

t('Entries: fremde UID kann nicht gelöscht werden', function () {
    [$a, $b] = twoStores();
    $svc = makeEntries();
    $svc->save($a, UID_A, 1, fields(), null);

    assert_api_error(404, fn () => $svc->delete($b, UID_A));
    assert_eq(1, $svc->list($a, 100, 0)['total'], 'Eintrag muss erhalten bleiben');
    $svc->delete($a, UID_A);
    assert_eq(0, $svc->list($a, 100, 0)['total']);
});

t('Entries: Konflikterkennung über expected_updated_at', function () {
    [$a] = twoStores();
    $svc = makeEntries();
    $svc->save($a, UID_A, 1, fields(), null);
    $stand = $svc->get($a, UID_A)['updated_at'];

    $svc->save($a, UID_A, 1, fields('v2'), $stand);
    assert_api_error(409, fn () => $svc->save($a, UID_A, 1, fields('v3'), 'veralteter-stand'));
});

t('Entries: Quota pro Store greift', function () {
    [$a] = twoStores();
    Config::override(['max_entries_per_store' => 2]);
    try {
        $svc = makeEntries();
        $svc->save($a, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa01', 1, fields(), null);
        $svc->save($a, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa02', 1, fields(), null);
        assert_api_error(409, fn () => $svc->save($a, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa03', 1, fields(), null));
    } finally {
        Config::override(['max_entries_per_store' => 1000]);
    }
});
