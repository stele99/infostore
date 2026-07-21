<?php

declare(strict_types=1);

/**
 * Dependency-freier Testrunner: php tests/run.php
 * Jeder Test laeuft gegen eine frische SQLite-Datei; Argon2id nutzt
 * Minimal-Limits, damit die Suite schnell bleibt.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$tmpBase = sys_get_temp_dir() . '/infostore-test-' . getmypid();
@mkdir($tmpBase, 0777, true);
putenv("INFOSTORE_LOG_DIR=$tmpBase/log");

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Config;
use App\Database;
use App\Http\ApiError;

Config::override([
    'db_path'         => "$tmpBase/test.sqlite3",
    'secret_file'     => "$tmpBase/app_secret",
    'mail_dir'        => "$tmpBase/mail",
    'log_dir'         => "$tmpBase/log",
    // libsodium-Minimalwerte (Argon2id): opslimit 1, memlimit 8 KiB - nur fuer Tests!
    'pwhash_opslimit' => 1,
    'pwhash_memlimit' => 8192,
]);

$GLOBALS['__tests'] = [];

function t(string $name, callable $fn): void
{
    $GLOBALS['__tests'][$name] = $fn;
}

function freshDb(): PDO
{
    Database::reset();
    $path = Config::get('db_path');
    foreach ([$path, "$path-wal", "$path-shm"] as $f) {
        if (file_exists($f)) {
            unlink($f);
        }
    }
    return Database::connection();
}

function assert_true(bool $cond, string $msg = 'Bedingung verletzt'): void
{
    if (!$cond) {
        throw new RuntimeException("Assertion fehlgeschlagen: $msg");
    }
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new RuntimeException("Assertion fehlgeschlagen: erwartet $e, erhalten $a. $msg");
    }
}

/** Erwartet einen ApiError mit bestimmtem HTTP-Status. */
function assert_api_error(int $status, callable $fn, string $msg = ''): void
{
    try {
        $fn();
    } catch (ApiError $e) {
        assert_eq($status, $e->status, "Falscher Status. $msg");
        return;
    }
    throw new RuntimeException("Assertion fehlgeschlagen: ApiError $status erwartet, keiner geworfen. $msg");
}

// Testdateien laden
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$pass = 0;
$fail = 0;
foreach ($GLOBALS['__tests'] as $name => $fn) {
    try {
        $fn();
        $pass++;
        echo "  ok  $name\n";
    } catch (Throwable $e) {
        $fail++;
        echo "FAIL  $name\n      " . $e->getMessage() . "\n      " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit($fail > 0 ? 1 : 0);
