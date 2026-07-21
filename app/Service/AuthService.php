<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Http\ApiError;
use App\RateLimiter;
use App\Repository\StoreRepository;

/**
 * Split-Key-Authentifizierung (Bitwarden-Modell):
 * Der Browser leitet aus dem vollständigen Passwort per PBKDF2 einen Master-Key
 * ab und daraus via HKDF einen getrennten Auth-Key. Nur der Auth-Key wird
 * übertragen; der Server hasht ihn zusätzlich mit Argon2id (libsodium).
 * Passwort und Content-Key erreichen den Server nie.
 */
final class AuthService
{
    private const NAME_PATTERN = '/^[a-zA-Z0-9._-]{5,64}$/';

    public function __construct(
        private readonly StoreRepository $stores,
        private readonly RateLimiter $limiter,
    ) {
    }

    public static function validateName(string $name): string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw ApiError::badRequest('Store-ID: 5-64 Zeichen, erlaubt sind a-z, A-Z, 0-9, Punkt, Unterstrich, Minus.');
        }
        return $name;
    }

    /**
     * Validiert und normalisiert die vom Client gelieferten KDF-Parameter.
     * Neue Stores muessen KDF-Version 2 (Argon2id) verwenden; Version 1
     * (PBKDF2) existiert nur noch fuer aus dem Altbestand migrierte Stores.
     *
     * @param array<string,mixed> $in
     * @return array{version:int,salt:string,time_cost:int,memory:?int,parallelism:?int}
     */
    private function validateKdf(array $in, bool $forRegistration): array
    {
        $version = (int) ($in['version'] ?? 0);
        $saltB64 = is_string($in['salt'] ?? null) ? $in['salt'] : '';
        $salt = base64_decode($saltB64, true);
        if ($salt === false || strlen($salt) < 16 || strlen($salt) > 64) {
            throw ApiError::badRequest('kdf_salt muss 16-64 Bytes Base64 sein.');
        }
        $timeCost = (int) ($in['time_cost'] ?? 0);

        if ($version === 2) {
            $memory = (int) ($in['memory'] ?? 0);
            $parallelism = (int) ($in['parallelism'] ?? 0);
            // Untergrenzen an OWASP-Baseline fuer Argon2id ausgerichtet, damit
            // ein Client seinen eigenen Store nicht unter sichere Werte schwaecht.
            if ($timeCost < 2 || $timeCost > 20) {
                throw ApiError::badRequest('kdf time_cost ausserhalb des erlaubten Bereichs.');
            }
            if ($memory < 19_456 || $memory > 1_048_576) {
                throw ApiError::badRequest('kdf memory ausserhalb des erlaubten Bereichs.');
            }
            if ($parallelism < 1 || $parallelism > 4) {
                throw ApiError::badRequest('kdf parallelism ausserhalb des erlaubten Bereichs.');
            }
            return ['version' => 2, 'salt' => $saltB64, 'time_cost' => $timeCost, 'memory' => $memory, 'parallelism' => $parallelism];
        }

        if ($version === 1 && !$forRegistration) {
            if ($timeCost < 100_000 || $timeCost > 10_000_000) {
                throw ApiError::badRequest('kdf time_cost ausserhalb des erlaubten Bereichs.');
            }
            return ['version' => 1, 'salt' => $saltB64, 'time_cost' => $timeCost, 'memory' => null, 'parallelism' => null];
        }

        throw ApiError::badRequest('kdf_version wird nicht unterstuetzt.');
    }

    /**
     * @param array<string,mixed> $kdfInput vom Client: version, salt, time_cost, memory, parallelism
     */
    public function register(string $name, string $authKeyB64, array $kdfInput, string $ip): array
    {
        self::validateName($name);
        $this->limiter->hit('register', $ip, 20);

        $authKey = base64_decode($authKeyB64, true);
        if ($authKey === false || strlen($authKey) !== 32) {
            throw ApiError::badRequest('auth_key muss 32 Bytes Base64 sein.');
        }
        $kdf = $this->validateKdf($kdfInput, true);

        if ($this->stores->findByName($name) !== null) {
            // Bewusst generisch: Registrierung verrät so wenig wie möglich.
            throw ApiError::conflict('Registrierung nicht möglich.');
        }

        $hash = \App\PasswordHash::hash($authKey);

        try {
            $storeId = $this->stores->create($name, $hash, $kdf);
        } catch (\PDOException) {
            throw ApiError::conflict('Registrierung nicht möglich.');
        }
        return ['store_id' => $storeId];
    }

    /**
     * KDF-Parameter für den Login. Für unbekannte Stores werden deterministische
     * Decoy-Parameter im Format eines Argon2id-Stores geliefert, damit Existenz
     * nicht per Salt-Abfrage aufzählbar ist.
     */
    public function kdfParams(string $name, string $ip): array
    {
        self::validateName($name);
        $this->limiter->hit('kdf', $ip, 60);

        $store = $this->stores->findByName($name);
        if ($store !== null) {
            return [
                'kdf_version'     => (int) $store['kdf_version'],
                'kdf_salt'        => $store['kdf_salt'],
                'kdf_time_cost'   => (int) $store['kdf_time_cost'],
                'kdf_memory'      => isset($store['kdf_memory']) ? (int) $store['kdf_memory'] : null,
                'kdf_parallelism' => isset($store['kdf_parallelism']) ? (int) $store['kdf_parallelism'] : null,
            ];
        }
        $decoy = hash_hmac('sha256', 'kdf-salt|' . $name, Config::appSecret(), true);
        return [
            'kdf_version'     => 2,
            'kdf_salt'        => base64_encode(substr($decoy, 0, 16)),
            'kdf_time_cost'   => (int) Config::get('argon2_time_cost'),
            'kdf_memory'      => (int) Config::get('argon2_memory'),
            'kdf_parallelism' => (int) Config::get('argon2_parallelism'),
        ];
    }

    /** @return array Store-Zeile bei Erfolg; generischer 401 sonst. */
    public function login(string $name, string $authKeyB64, string $ip): array
    {
        self::validateName($name);
        $this->limiter->hit('login-ip', $ip);
        $this->limiter->hit('login-name', $name);

        $authKey = base64_decode($authKeyB64, true);
        if ($authKey === false || strlen($authKey) !== 32) {
            throw ApiError::unauthorized('Anmeldung fehlgeschlagen.');
        }

        $store = $this->stores->findByName($name);
        // Dummy-Verify gegen Timing-Unterschiede zwischen unbekanntem Store und falschem Passwort
        $hash = $store['auth_hash'] ?? \App\PasswordHash::dummy();

        $ok = \App\PasswordHash::verify($hash, $authKey);
        if ($store === null || !$ok) {
            throw ApiError::unauthorized('Anmeldung fehlgeschlagen.');
        }

        $this->limiter->clear('login-name', $name);
        return $store;
    }
}
