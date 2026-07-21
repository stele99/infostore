<?php

declare(strict_types=1);

namespace App;

/**
 * Konfiguration aus Umgebungsvariablen mit sicheren Defaults.
 * Lokale Overrides optional in config/local.php (gitignored, gibt Array zurück).
 * Es liegen keine Produktionspfade oder Geheimnisse im Repository.
 */
final class Config
{
    private static ?array $values = null;

    public static function get(string $key): mixed
    {
        if (self::$values === null) {
            self::$values = self::build();
        }
        return self::$values[$key] ?? null;
    }

    /** Nur für Tests: Konfiguration überschreiben. */
    public static function override(array $values): void
    {
        if (self::$values === null) {
            self::$values = self::build();
        }
        self::$values = array_merge(self::$values, $values);
    }

    private static function build(): array
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        $env = fn (string $name, string $default): string => getenv($name) !== false ? getenv($name) : $default;

        $values = [
            'db_path'        => $env('INFOSTORE_DB', $root . '/var/data.sqlite3'),
            'log_dir'        => $env('INFOSTORE_LOG_DIR', $root . '/var/log'),
            'secret_file'    => $env('INFOSTORE_SECRET_FILE', $root . '/var/app_secret'),
            'mail_transport' => $env('INFOSTORE_MAIL', 'file'), // file | native
            'mail_dir'       => $env('INFOSTORE_MAIL_DIR', $root . '/var/mail'),
            'mail_from'      => $env('INFOSTORE_MAIL_FROM', 'infostore@localhost'),
            'session_name'   => 'infostore_sid',
            'max_body_bytes' => 3_000_000,
            'max_entries_per_store' => 1000,
            'max_shares_per_store'  => 10,
            'login_max_attempts'    => 10,   // pro Fenster, je Store-Name und je IP
            'login_window_seconds'  => 900,
            'kdf_default_iterations' => 600_000,
        ];

        $local = $root . '/config/local.php';
        if (is_file($local)) {
            $overrides = require $local;
            if (is_array($overrides)) {
                $values = array_merge($values, $overrides);
            }
        }
        return $values;
    }

    /**
     * Serverseitiges Geheimnis für Decoy-Salts (Anti-Enumeration).
     * Wird beim ersten Zugriff erzeugt und ausserhalb des Webroot gespeichert.
     */
    public static function appSecret(): string
    {
        $file = self::get('secret_file');
        if (is_file($file)) {
            return trim((string) file_get_contents($file));
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $secret = bin2hex(random_bytes(32));
        file_put_contents($file, $secret, LOCK_EX);
        chmod($file, 0600);
        return $secret;
    }
}
