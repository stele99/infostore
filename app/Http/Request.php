<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    private ?array $json = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = $_SERVER['PATH_INFO'] ?? '/';
        $this->path = '/' . trim($path, '/');
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    /** JSON-Body lesen: nur application/json, Größenlimit, keine stillen Fehler. */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $max = (int) Config::get('max_body_bytes');
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > $max) {
            throw new ApiError(413, 'payload_too_large', 'Anfrage ist zu gross.');
        }
        $ctype = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_starts_with(strtolower($ctype), 'application/json')) {
            throw ApiError::badRequest('Content-Type muss application/json sein.');
        }
        $raw = file_get_contents('php://input', false, null, 0, $max + 1);
        if ($raw === false || strlen($raw) > $max) {
            throw new ApiError(413, 'payload_too_large', 'Anfrage ist zu gross.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw ApiError::badRequest('Body ist kein gültiges JSON-Objekt.');
        }
        return $this->json = $data;
    }

    /** Pflichtfeld als String mit Längenbegrenzung. */
    public function str(string $field, int $maxLen, bool $required = true): string
    {
        $data = $this->json();
        $val = $data[$field] ?? null;
        if ($val === null || $val === '') {
            if ($required) {
                throw ApiError::badRequest("Feld '$field' fehlt.");
            }
            return '';
        }
        if (!is_string($val) || strlen($val) > $maxLen) {
            throw ApiError::badRequest("Feld '$field' ist ungültig.");
        }
        return $val;
    }

    public function int(string $field, int $min, int $max): int
    {
        $data = $this->json();
        $val = $data[$field] ?? null;
        if (!is_int($val) || $val < $min || $val > $max) {
            throw ApiError::badRequest("Feld '$field' ist ungültig.");
        }
        return $val;
    }

    /** Base64-Feld validieren und dekodierte Länge prüfen. */
    public function b64(string $field, int $minBytes, int $maxBytes): string
    {
        $val = $this->str($field, (int) ceil($maxBytes / 3) * 4 + 8);
        $bin = base64_decode($val, true);
        if ($bin === false || strlen($bin) < $minBytes || strlen($bin) > $maxBytes) {
            throw ApiError::badRequest("Feld '$field' ist kein gültiger Base64-Wert.");
        }
        return $val;
    }

    public function uuid(string $field): string
    {
        $val = $this->str($field, 36);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $val)) {
            throw ApiError::badRequest("Feld '$field' ist keine gültige UUID.");
        }
        return $val;
    }
}
