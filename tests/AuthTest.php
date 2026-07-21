<?php

declare(strict_types=1);

use App\Config;
use App\RateLimiter;
use App\Repository\StoreRepository;
use App\Service\AuthService;

function makeAuth(): AuthService
{
    $db = App\Database::connection();
    return new AuthService(new StoreRepository($db), new RateLimiter($db));
}

function b64key(string $seed): string
{
    return base64_encode(hash('sha256', $seed, true));
}

t('Auth: Registrierung und Login mit korrektem Auth-Key', function () {
    freshDb();
    $auth = makeAuth();
    $auth->register('store.one', b64key('k1'), base64_encode(random_bytes(16)), 600000, '1.1.1.1');
    $store = $auth->login('store.one', b64key('k1'), '1.1.1.1');
    assert_eq('store.one', $store['name']);
});

t('Auth: falscher Auth-Key und unbekannter Store antworten identisch mit 401', function () {
    freshDb();
    $auth = makeAuth();
    $auth->register('store.one', b64key('k1'), base64_encode(random_bytes(16)), 600000, '1.1.1.1');
    assert_api_error(401, fn () => $auth->login('store.one', b64key('falsch'), '1.1.1.1'));
    assert_api_error(401, fn () => $auth->login('store.zwei', b64key('k1'), '1.1.1.1'));
});

t('Auth: doppelte Registrierung wird generisch abgelehnt', function () {
    freshDb();
    $auth = makeAuth();
    $auth->register('store.one', b64key('k1'), base64_encode(random_bytes(16)), 600000, '1.1.1.1');
    assert_api_error(409, fn () => $auth->register('store.one', b64key('k2'), base64_encode(random_bytes(16)), 600000, '1.1.1.1'));
});

t('Auth: kein Auto-Create beim Login', function () {
    freshDb();
    $auth = makeAuth();
    assert_api_error(401, fn () => $auth->login('store.neu', b64key('x'), '1.1.1.1'));
    $db = App\Database::connection();
    assert_eq(0, (int) $db->query('SELECT COUNT(*) FROM stores')->fetchColumn());
});

t('Auth: KDF-Decoy fuer unbekannte Stores ist deterministisch und formatgleich', function () {
    freshDb();
    $auth = makeAuth();
    $a = $auth->kdfParams('gibtsnicht', '1.1.1.1');
    $b = $auth->kdfParams('gibtsnicht', '1.1.1.1');
    assert_eq($a['kdf_salt'], $b['kdf_salt'], 'Decoy-Salt muss stabil sein');
    assert_eq(16, strlen(base64_decode($a['kdf_salt'])));
    assert_eq((int) Config::get('kdf_default_iterations'), $a['kdf_iterations']);
});

t('Auth: Rate-Limit sperrt nach zu vielen Fehlversuchen', function () {
    freshDb();
    Config::override(['login_max_attempts' => 3]);
    try {
        $auth = makeAuth();
        $auth->register('store.one', b64key('k1'), base64_encode(random_bytes(16)), 600000, '9.9.9.9');
        for ($i = 0; $i < 3; $i++) {
            try {
                $auth->login('store.one', b64key('falsch'), '9.9.9.9');
            } catch (App\Http\ApiError) {
            }
        }
        assert_api_error(429, fn () => $auth->login('store.one', b64key('k1'), '9.9.9.9'));
    } finally {
        Config::override(['login_max_attempts' => 10]);
    }
});
