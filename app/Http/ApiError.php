<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Fachlicher Fehler, der als JSON-Fehlerantwort mit korrektem HTTP-Code endet.
 * Interne Details gehören ins Log, nie in die Message.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $msg = 'Ungültige Anfrage.'): self
    {
        return new self(400, 'bad_request', $msg);
    }

    public static function unauthorized(string $msg = 'Nicht angemeldet.'): self
    {
        return new self(401, 'unauthorized', $msg);
    }

    public static function forbidden(string $msg = 'Keine Berechtigung.'): self
    {
        return new self(403, 'forbidden', $msg);
    }

    public static function notFound(string $msg = 'Nicht gefunden.'): self
    {
        return new self(404, 'not_found', $msg);
    }

    public static function conflict(string $msg = 'Konflikt.'): self
    {
        return new self(409, 'conflict', $msg);
    }

    public static function tooMany(string $msg = 'Zu viele Anfragen. Bitte später erneut versuchen.'): self
    {
        return new self(429, 'rate_limited', $msg);
    }
}
