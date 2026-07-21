<?php

declare(strict_types=1);

namespace App;

/**
 * Argon2id-Hashing (libsodium) fuer Auth-Keys und Seed-Nachweise.
 * Limits sind fuer Tests konfigurierbar; Produktion nutzt MODERATE.
 */
final class PasswordHash
{
    public static function hash(string $value): string
    {
        return sodium_crypto_pwhash_str(
            $value,
            Config::get('pwhash_opslimit') ?? SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            Config::get('pwhash_memlimit') ?? SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE
        );
    }

    public static function verify(string $hash, string $value): bool
    {
        return sodium_crypto_pwhash_str_verify($hash, $value);
    }

    /** Vorberechneter Dummy-Hash gegen Timing-Orakel bei unbekannten Subjekten. */
    public static function dummy(): string
    {
        static $dummy = null;
        return $dummy ??= self::hash('dummy');
    }
}
