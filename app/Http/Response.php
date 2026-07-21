<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        self::securityHeaders();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['data' => $data], JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(int $status, string $code, string $message, string $requestId): never
    {
        http_response_code($status);
        self::securityHeaders();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => ['code' => $code, 'message' => $message, 'requestId' => $requestId],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
