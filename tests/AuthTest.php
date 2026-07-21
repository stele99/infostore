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

/** KDF-Parameter für eine Argon2id-Registrierung (KDF-Version 2). */
function argonKdf(): array
{
    return ['version' => 2, 'salt' => base64_encode(random_bytes(16)), 'time_cost' => 3, 'memory' => 19456, 'parallelism' => 1];
}

t('Auth: Registrierung und Login mit korrektem Auth-Key', function () {
    freshDb();
    $auth = makeAuth();
    $auth->register('store.one', b64key('k1'), argonKdf(), '1.1.1.1');
    $store = $auth->login('store.one', b64key('k1'), '1.1.1.1');
    assert_eq('store.one', $store['name']);
});

t('Auth: falscher Auth-Key und unbekannter Store antworten identisch mit 401', function () {
    freshDb();
    $auth = makeAuth();
    $auth->register('store.one', b64key('k1'), argonKdf(), '1.1.1.1');
    assert_api_error(401, fn () => $auth->login('store.one', b64key('falsch'), '1.1.1.1'));
    assert_api_error(401, fn () => $auth->login('store.zwei', b64key('k1'), '1.1.1.1'));
});

t('Auth: doppelte Registrierung wird generisch abgelehnt', function () {
    freshDb();
    $auth = makeAuth();
    $auth->register('store.one', b64key('k1'), argonKdf(), '1.1.1.1');
    assert_api_error(409, fn () => $auth->register('store.one', b64key('k2'), argonKdf(), '1.1.1.1'));
});

t('Auth: kein Auto-Create beim Login', function () {
    freshDb();
    $auth = makeAuth();
    assert_api_error(401, fn () => $auth->login('store.neu', b64key('x'), '1.1.1.1'));
    $db = App\Database::connection();
    assert_eq(0, (int) $db->query('SELECT COUNT(*) FROM stores')->fetchColumn());
});

t('Auth: KDF-Decoy für unbekannte Stores ist deterministisch und formatgleich', function () {
    freshDb();
    $auth = makeAuth();
    $a = $auth->kdfParams('gibtsnicht', '1.1.1.1');
    $b = $auth->kdfParams('gibtsnicht', '1.1.1.1');
    assert_eq($a['kdf_salt'], $b['kdf_salt'], 'Decoy-Salt muss stabil sein');
    assert_eq(16, strlen(base64_decode($a['kdf_salt'])));
    // Decoy muss wie ein regulärer Argon2id-Store (Version 2) aussehen.
    assert_eq(2, $a['kdf_version']);
    assert_eq((int) Config::get('argon2_memory'), $a['kdf_memory']);
});

t('Auth: Registrierung mit zu schwachen Argon2-Parametern wird abgelehnt', function () {
    freshDb();
    $auth = makeAuth();
    $weak = ['version' => 2, 'salt' => base64_encode(random_bytes(16)), 'time_cost' => 1, 'memory' => 8192, 'parallelism' => 1];
    assert_api_error(400, fn () => $auth->register('store.weak', b64key('k1'), $weak, '1.1.1.1'));
});

t('Auth: migrierter PBKDF2-Store (Version 1) bleibt anmeldbar', function () {
    freshDb();
    // Simuliert einen aus dem Altbestand migrierten Store: Version 1, PBKDF2.
    $db = App\Database::connection();
    $hash = App\PasswordHash::hash(base64_decode(b64key('legacy')));
    $stm = $db->prepare('INSERT INTO stores (name, auth_hash, kdf_version, kdf_salt, kdf_time_cost, created_at)
                         VALUES (?, ?, 1, ?, 600000, ?)');
    $stm->execute(['store.legacy', $hash, base64_encode(random_bytes(16)), App\Database::now()]);

    $auth = makeAuth();
    $kdf = $auth->kdfParams('store.legacy', '1.1.1.1');
    assert_eq(1, $kdf['kdf_version']);
    assert_eq(600000, $kdf['kdf_time_cost']);
    assert_eq(null, $kdf['kdf_memory']);

    $store = $auth->login('store.legacy', b64key('legacy'), '1.1.1.1');
    assert_eq('store.legacy', $store['name']);
});

t('Auth: Rate-Limit sperrt nach zu vielen Fehlversuchen', function () {
    freshDb();
    Config::override(['login_max_attempts' => 3]);
    try {
        $auth = makeAuth();
        $auth->register('store.one', b64key('k1'), argonKdf(), '9.9.9.9');
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
