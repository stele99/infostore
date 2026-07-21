<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Mail\MailerFactory;
use App\RateLimiter;
use App\Repository\EntryRepository;
use App\Repository\ShareRepository;
use App\Repository\StoreRepository;
use App\Service\AuthService;
use App\Service\EntryService;
use App\Service\ShareService;
use App\Session;

$requestId = bin2hex(random_bytes(8));

set_exception_handler(function (\Throwable $e) use ($requestId): void {
    if ($e instanceof ApiError) {
        Response::error($e->status, $e->errorCode, $e->getMessage(), $requestId);
    }
    error_log("[$requestId] " . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::error(500, 'internal', 'Interner Fehler.', $requestId);
});

$req = new Request();

// Feste Routing-Tabelle: METHOD + Pfadmuster -> Handler. Keine Request-Werte
// in Include-Pfaden, unbekannte Routen enden mit 404.
$routes = [
    ['POST',   '#^/auth/register$#',        'authRegister',   'public'],
    ['POST',   '#^/auth/kdf$#',             'authKdf',        'public'],
    ['POST',   '#^/auth/login$#',           'authLogin',      'public'],
    ['POST',   '#^/auth/logout$#',          'authLogout',     'csrf'],
    ['GET',    '#^/auth/session$#',         'authSession',    'public'],
    ['GET',    '#^/entries$#',              'entriesList',    'auth'],
    ['GET',    '#^/entries/([0-9a-f-]{36})$#', 'entriesGet',  'auth'],
    ['PUT',    '#^/entries/([0-9a-f-]{36})$#', 'entriesPut',  'owner'],
    ['DELETE', '#^/entries/([0-9a-f-]{36})$#', 'entriesDelete', 'owner'],
    ['GET',    '#^/shares$#',               'sharesList',     'owner'],
    ['POST',   '#^/shares$#',               'sharesCreate',   'owner'],
    ['POST',   '#^/shares/([0-9a-f-]{36})/deny$#',   'sharesDeny',   'owner'],
    ['POST',   '#^/shares/([0-9a-f-]{36})/revoke$#', 'sharesRevoke', 'owner'],
    ['DELETE', '#^/shares/([0-9a-f-]{36})$#',        'sharesDelete', 'owner'],
    ['POST',   '#^/share-access/kdf$#',     'shareAccessKdf',     'public'],
    ['POST',   '#^/share-access/request$#', 'shareAccessRequest', 'public'],
];

$handler = null;
$args = [];
foreach ($routes as [$method, $pattern, $fn, $guard]) {
    if ($req->method === $method && preg_match($pattern, $req->path, $m)) {
        $handler = $fn;
        $args = array_slice($m, 1);
        break;
    }
}
if ($handler === null) {
    throw ApiError::notFound('Unbekannter Endpoint.');
}

// Guards: Session-/Rollen-Pflicht und CSRF fuer zustandsaendernde Requests
// mit bestehender Session. Public-Endpoints sind einzeln rate-limitiert.
$storeId = null;
switch ($guard) {
    case 'auth':
        $storeId = Session::requireStoreId();
        break;
    case 'owner':
        $storeId = Session::requireOwner();
        if ($req->method !== 'GET') {
            Session::checkCsrf($req->header('X-CSRF-Token'));
        }
        break;
    case 'csrf':
        Session::checkCsrf($req->header('X-CSRF-Token'));
        break;
}

$db = Database::connection();
$limiter = new RateLimiter($db);
$auth = new AuthService(new StoreRepository($db), $limiter);
$entries = new EntryService(new EntryRepository($db));
$shares = new ShareService(new ShareRepository($db), $limiter, MailerFactory::create());

/** Verschluesselte Entry-Felder aus dem Request validieren. */
function entryFields(Request $req): array
{
    return [
        'title_ct' => $req->b64('title_ct', 1, 6000),
        'title_iv' => $req->b64('title_iv', 12, 16),
        'body_ct'  => $req->b64('body_ct', 1, 1_500_000),
        'body_iv'  => $req->b64('body_iv', 12, 16),
    ];
}

switch ($handler) {
    case 'authRegister':
        $result = $auth->register(
            $req->str('store', 64),
            $req->b64('auth_key', 32, 32),
            $req->b64('kdf_salt', 16, 64),
            $req->int('kdf_iterations', 100_000, 5_000_000),
            $req->ip()
        );
        Response::json(['registered' => true], 201);

    case 'authKdf':
        Response::json($auth->kdfParams($req->str('store', 64), $req->ip()));

    case 'authLogin':
        $store = $auth->login($req->str('store', 64), $req->b64('auth_key', 32, 32), $req->ip());
        Session::login((int) $store['id'], Session::ROLE_OWNER);
        Response::json([
            'role' => Session::ROLE_OWNER,
            'csrf' => Session::csrfToken(),
        ]);

    case 'authLogout':
        Session::logout();
        Response::json(['loggedOut' => true]);

    case 'authSession':
        $id = Session::storeIdOrNull();
        Response::json([
            'authenticated' => $id !== null,
            'role'          => Session::role(),
            'csrf'          => $id !== null ? Session::csrfToken() : null,
        ]);

    case 'entriesList':
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
        Response::json($entries->list((int) $storeId, $limit, $offset));

    case 'entriesGet':
        Response::json($entries->get((int) $storeId, $args[0]));

    case 'entriesPut':
        $expected = $req->str('expected_updated_at', 32, false);
        Response::json($entries->save(
            (int) $storeId,
            $args[0],
            $req->int('crypto_version', 1, 1),
            entryFields($req),
            $expected === '' ? null : $expected
        ));

    case 'entriesDelete':
        $entries->delete((int) $storeId, $args[0]);
        Response::json(['deleted' => true]);

    case 'sharesList':
        Response::json(['shares' => $shares->listForOwner((int) $storeId)]);

    case 'sharesCreate':
        Response::json($shares->create((int) $storeId, [
            'share_uid'      => $req->uuid('share_uid'),
            'crypto_version' => $req->int('crypto_version', 1, 1),
            'wrapped_key'    => $req->b64('wrapped_key', 16, 256),
            'wrap_iv'        => $req->b64('wrap_iv', 12, 16),
            'kdf_salt'       => $req->b64('kdf_salt', 16, 64),
            'kdf_iterations' => $req->int('kdf_iterations', 100_000, 5_000_000),
            'seed_auth'      => $req->b64('seed_auth', 32, 32),
            'owner_mail'     => $req->str('owner_mail', 254),
            'delay_hours'    => $req->int('delay_hours', 0, 8760),
        ]), 201);

    case 'sharesDeny':
        Response::json($shares->deny((int) $storeId, $args[0]));

    case 'sharesRevoke':
        Response::json($shares->revoke((int) $storeId, $args[0]));

    case 'sharesDelete':
        $shares->delete((int) $storeId, $args[0]);
        Response::json(['deleted' => true]);

    case 'shareAccessKdf':
        Response::json(['shares' => $shares->saltsForStore($req->str('store', 64), $req->ip())]);

    case 'shareAccessRequest':
        $result = $shares->request(
            $req->str('store', 64),
            $req->uuid('share_uid'),
            $req->b64('seed_auth', 32, 32),
            $req->ip()
        );
        if (($result['status'] ?? '') === 'granted') {
            // Empfaenger erhaelt eine Lese-Session fuer den Store
            Session::login((int) $result['store_id'], Session::ROLE_RECIPIENT);
            unset($result['store_id']);
            $result['csrf'] = Session::csrfToken();
        }
        Response::json($result);
}

throw ApiError::notFound('Unbekannter Endpoint.');
