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

    public function register(string $name, string $authKeyB64, string $kdfSaltB64, int $kdfIterations, string $ip): array
    {
        self::validateName($name);
        $this->limiter->hit('register', $ip, 20);

        $authKey = base64_decode($authKeyB64, true);
        if ($authKey === false || strlen($authKey) !== 32) {
            throw ApiError::badRequest('auth_key muss 32 Bytes Base64 sein.');
        }
        $salt = base64_decode($kdfSaltB64, true);
        if ($salt === false || strlen($salt) < 16 || strlen($salt) > 64) {
            throw ApiError::badRequest('kdf_salt muss 16-64 Bytes Base64 sein.');
        }
        if ($kdfIterations < 100_000 || $kdfIterations > 5_000_000) {
            throw ApiError::badRequest('kdf_iterations ausserhalb des erlaubten Bereichs.');
        }

        if ($this->stores->findByName($name) !== null) {
            // Bewusst generisch: Registrierung verrät so wenig wie möglich.
            throw ApiError::conflict('Registrierung nicht möglich.');
        }

        $hash = \App\PasswordHash::hash($authKey);

        try {
            $storeId = $this->stores->create($name, $hash, $kdfSaltB64, $kdfIterations, 1);
        } catch (\PDOException) {
            throw ApiError::conflict('Registrierung nicht möglich.');
        }
        return ['store_id' => $storeId];
    }

    /**
     * KDF-Parameter für den Login. Für unbekannte Stores wird ein
     * deterministischer Decoy-Salt geliefert, damit Existenz nicht
     * per Salt-Abfrage aufzählbar ist.
     */
    public function kdfParams(string $name, string $ip): array
    {
        self::validateName($name);
        $this->limiter->hit('kdf', $ip, 60);

        $store = $this->stores->findByName($name);
        if ($store !== null) {
            return [
                'kdf_salt'       => $store['kdf_salt'],
                'kdf_iterations' => (int) $store['kdf_iterations'],
                'kdf_version'    => (int) $store['kdf_version'],
            ];
        }
        $decoy = hash_hmac('sha256', 'kdf-salt|' . $name, Config::appSecret(), true);
        return [
            'kdf_salt'       => base64_encode(substr($decoy, 0, 16)),
            'kdf_iterations' => (int) Config::get('kdf_default_iterations'),
            'kdf_version'    => 1,
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
