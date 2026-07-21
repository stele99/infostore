<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Database;
use App\Http\ApiError;
use App\Mail\Mailer;
use App\RateLimiter;
use App\Repository\ShareRepository;

/**
 * Notfallzugriff (Sharing) mit serverseitigem Zustandsautomaten.
 *
 * Zustaende: active -> requested -> granted | denied; jeder Zustand -> revoked (Owner).
 * Serverzeit ist allein massgeblich; der Client liefert nie Status oder Zeit.
 * Das verschluesselte Key-Paket wird erst im Zustand 'granted' herausgegeben,
 * und nur gegen Nachweis des Seed-Wissens (seed_auth, separat vom Wrap-Key
 * abgeleitet und serverseitig Argon2id-gehasht).
 */
final class ShareService
{
    public function __construct(
        private readonly ShareRepository $shares,
        private readonly RateLimiter $limiter,
        private readonly Mailer $mailer,
    ) {
    }

    // ---- Owner-Seite -------------------------------------------------------

    public function create(int $storeId, array $f): array
    {
        $max = (int) Config::get('max_shares_per_store');
        if ($this->shares->countForStore($storeId) >= $max) {
            throw ApiError::conflict("Limit von $max Shares erreicht.");
        }

        $seedAuth = base64_decode($f['seed_auth'], true);
        if ($seedAuth === false || strlen($seedAuth) !== 32) {
            throw ApiError::badRequest('seed_auth muss 32 Bytes Base64 sein.');
        }
        if (!filter_var($f['owner_mail'], FILTER_VALIDATE_EMAIL)) {
            throw ApiError::badRequest('owner_mail ist keine gueltige E-Mail-Adresse.');
        }

        $f['seed_auth_hash'] = \App\PasswordHash::hash($seedAuth);
        unset($f['seed_auth']);

        $shareId = $this->shares->insert($storeId, $f);
        $this->shares->logEvent($shareId, 'created');
        return ['share_uid' => $f['share_uid'], 'status' => 'active'];
    }

    public function listForOwner(int $storeId): array
    {
        return $this->shares->listForOwner($storeId);
    }

    /** Owner lehnt eine laufende Anfrage ab (nur aus 'requested'). */
    public function deny(int $storeId, string $shareUid): array
    {
        $share = $this->requireOwnerShare($storeId, $shareUid);
        if (!$this->shares->transition((int) $share['id'], 'requested', 'denied', ['decided_at' => Database::now()])) {
            throw ApiError::conflict('Ablehnen ist nur fuer eine laufende Anfrage moeglich.');
        }
        $this->shares->logEvent((int) $share['id'], 'denied', 'by owner');
        return ['share_uid' => $shareUid, 'status' => 'denied'];
    }

    /** Owner widerruft einen Share endgueltig - aus jedem Zustand, auch nach Grant. */
    public function revoke(int $storeId, string $shareUid): array
    {
        $share = $this->requireOwnerShare($storeId, $shareUid);
        foreach (['active', 'requested', 'granted', 'denied'] as $from) {
            if ($this->shares->transition((int) $share['id'], $from, 'revoked', ['revoked_at' => Database::now()])) {
                $this->shares->logEvent((int) $share['id'], 'revoked', "from $from");
                return ['share_uid' => $shareUid, 'status' => 'revoked'];
            }
        }
        throw ApiError::conflict('Share ist bereits widerrufen.');
    }

    public function delete(int $storeId, string $shareUid): void
    {
        $share = $this->requireOwnerShare($storeId, $shareUid);
        $this->shares->logEvent((int) $share['id'], 'deleted');
        $this->shares->delete($storeId, $shareUid);
    }

    private function requireOwnerShare(int $storeId, string $shareUid): array
    {
        $share = $this->shares->findForStoreByUid($storeId, $shareUid);
        if ($share === null) {
            throw ApiError::notFound('Share nicht gefunden.');
        }
        return $share;
    }

    // ---- Empfaenger-Seite (unauthentifiziert, rate-limitiert) --------------

    /**
     * KDF-Parameter der Shares eines Stores, damit der Empfaenger seed_auth
     * berechnen kann. Fuer unbekannte Stores wird ein deterministischer
     * Decoy geliefert (keine Aufzaehlbarkeit von Stores oder Shares).
     */
    public function saltsForStore(string $storeName, string $ip): array
    {
        AuthService::validateName($storeName);
        $this->limiter->hit('share-salt', $ip, 30);

        $rows = $this->shares->saltsForStoreName($storeName);
        if (count($rows) > 0) {
            return $rows;
        }
        $seed = hash_hmac('sha256', 'share-salt|' . $storeName, Config::appSecret(), true);
        return [[
            'share_uid'      => self::decoyUuid($seed),
            'kdf_salt'       => base64_encode(substr($seed, 0, 16)),
            'kdf_iterations' => (int) Config::get('kdf_default_iterations'),
            'crypto_version' => 1,
        ]];
    }

    /**
     * Zugriffsanfrage bzw. Poll des Empfaengers. Ein Aufruf mit gueltigem
     * seed_auth bewirkt je nach Zustand und Serverzeit:
     *   active           -> requested (Mail an Owner) bzw. granted bei delay=0
     *   requested        -> granted, sobald available_at erreicht ist; sonst Wartestatus
     *   granted          -> Key-Paket
     *   denied/revoked   -> abschlaegiger Status ohne Key-Paket
     */
    public function request(string $storeName, string $shareUid, string $seedAuthB64, string $ip): array
    {
        AuthService::validateName($storeName);
        $this->limiter->hit('share-request-ip', $ip);
        $this->limiter->hit('share-request-store', $storeName);

        $share = $this->shares->findByUidAndStoreName($shareUid, $storeName);
        $seedAuth = base64_decode($seedAuthB64, true);

        $hash = $share['seed_auth_hash'] ?? \App\PasswordHash::dummy();
        $ok = $seedAuth !== false && strlen($seedAuth) === 32
            && \App\PasswordHash::verify($hash, $seedAuth);
        if ($share === null || !$ok) {
            if ($share !== null) {
                $this->shares->logEvent((int) $share['id'], 'auth_failed');
            }
            throw ApiError::unauthorized('Zugriff nicht moeglich.');
        }

        $id = (int) $share['id'];
        $now = time();

        if ($share['status'] === 'active') {
            $delay = (int) $share['delay_hours'];
            $availableAt = gmdate('Y-m-d\TH:i:s\Z', $now + $delay * 3600);
            if ($delay === 0) {
                $this->shares->transition($id, 'active', 'granted', [
                    'requested_at' => Database::now(),
                    'available_at' => $availableAt,
                    'decided_at'   => Database::now(),
                ]);
                $this->shares->logEvent($id, 'granted', 'delay=0');
                return $this->grantPackage($share);
            }
            $this->shares->transition($id, 'active', 'requested', [
                'requested_at' => Database::now(),
                'available_at' => $availableAt,
            ]);
            $this->shares->logEvent($id, 'requested', "available_at=$availableAt");
            $this->notifyOwner($share, $availableAt);
            return ['status' => 'requested', 'available_at' => $availableAt];
        }

        if ($share['status'] === 'requested') {
            $availableAt = (string) $share['available_at'];
            if ($now >= strtotime($availableAt)) {
                if ($this->shares->transition($id, 'requested', 'granted', ['decided_at' => Database::now()])) {
                    $this->shares->logEvent($id, 'granted', 'delay elapsed');
                }
                return $this->grantPackage($share);
            }
            return ['status' => 'requested', 'available_at' => $availableAt];
        }

        if ($share['status'] === 'granted') {
            return $this->grantPackage($share);
        }

        // denied oder revoked: kein Key-Paket, klarer Status
        return ['status' => $share['status']];
    }

    /** @return array Key-Paket; setzt ausserdem die Empfaenger-Session-Daten. */
    private function grantPackage(array $share): array
    {
        return [
            'status'         => 'granted',
            'store_id'       => (int) $share['store_id'],
            'wrapped_key'    => $share['wrapped_key'],
            'wrap_iv'        => $share['wrap_iv'],
            'crypto_version' => (int) $share['crypto_version'],
        ];
    }

    private function notifyOwner(array $share, string $availableAt): void
    {
        $store = $share['store_name'] ?? ('#' . $share['store_id']);
        $body = "Fuer deinen Info-Store \"$store\" wurde ein Notfallzugriff angefordert.\n\n"
            . "Der Zugriff wird am $availableAt (UTC) automatisch freigegeben.\n"
            . "Wenn du das nicht moechtest, melde dich an und lehne die Anfrage ab oder widerrufe den Share.\n";
        $sent = $this->mailer->send((string) $share['owner_mail'], 'Info-Store: Notfallzugriff angefordert', $body);
        $this->shares->logEvent((int) $share['id'], $sent ? 'owner_notified' : 'owner_notify_failed');
    }

    private static function decoyUuid(string $bytes): string
    {
        $h = bin2hex(substr($bytes, 16, 16));
        return sprintf(
            '%s-%s-4%s-8%s-%s',
            substr($h, 0, 8),
            substr($h, 8, 4),
            substr($h, 13, 3),
            substr($h, 17, 3),
            substr($h, 20, 12)
        );
    }
}
