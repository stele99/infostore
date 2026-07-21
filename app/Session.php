<?php

declare(strict_types=1);

namespace App;

use App\Http\ApiError;

/**
 * Sichere PHP-Session: HttpOnly, SameSite=Lax, Secure bei HTTPS, strict mode.
 * Store-Identität und Rolle kommen ausschliesslich aus der Session,
 * nie aus Request-Daten.
 */
final class Session
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_RECIPIENT = 'recipient';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_name(Config::get('session_name'));
        session_start([
            'use_strict_mode'  => true,
            'cookie_httponly'  => true,
            'cookie_secure'    => $secure,
            'cookie_samesite'  => 'Lax',
            'gc_maxlifetime'   => 3600,
        ]);
    }

    /** Nach erfolgreichem Login: Session-ID rotieren und Identität setzen. */
    public static function login(int $storeId, string $role): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['store_id'] = $storeId;
        $_SESSION['role'] = $role;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
    }

    public static function storeIdOrNull(): ?int
    {
        self::start();
        return isset($_SESSION['store_id']) ? (int) $_SESSION['store_id'] : null;
    }

    public static function role(): ?string
    {
        self::start();
        return $_SESSION['role'] ?? null;
    }

    /** Authentifizierte Store-ID erzwingen (Owner oder Recipient). */
    public static function requireStoreId(): int
    {
        $id = self::storeIdOrNull();
        if ($id === null) {
            throw ApiError::unauthorized();
        }
        return $id;
    }

    /** Nur der Owner darf schreiben und Shares verwalten. */
    public static function requireOwner(): int
    {
        $id = self::requireStoreId();
        if (self::role() !== self::ROLE_OWNER) {
            throw ApiError::forbidden('Nur der Inhaber darf diese Aktion ausführen.');
        }
        return $id;
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** CSRF-Prüfung für zustandsändernde Requests mit bestehender Session. */
    public static function checkCsrf(?string $token): void
    {
        self::start();
        $expected = $_SESSION['csrf'] ?? '';
        if ($expected === '' || $token === null || !hash_equals($expected, $token)) {
            throw ApiError::forbidden('CSRF-Token fehlt oder ist ungültig.');
        }
    }
}
