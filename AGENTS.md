# Secure Info Store

## Run and Verify

- Dependency-freies PHP 8.2+ (benoetigt `pdo_sqlite` und `sodium`); kein Composer, kein Build-Schritt.
- Lokal starten: `php -S localhost:8000` aus `public/` heraus. Konfiguration ueber Umgebungsvariablen (`config/env.example`); Default-Datenbank ist `var/data.sqlite3` (gitignored, entsteht samt Migrationen beim ersten Request).
- Tests: `php tests/run.php` (dependency-freier Runner, frische SQLite-Datei pro Test).
- Syntax-Lint: `for f in $(git ls-files '*.php'); do php -l "$f" || exit 1; done`.

## Struktur

- `public/` ist der einzige Webroot: `index.php` (App-Shell mit CSP), `api.php` (JSON-API-Front-Controller mit fester Routing-Tabelle), `assets/` (ES-Module, CSS, lokal vendored Quill).
- `app/` enthaelt das Backend: `Http/` (Request-Validierung, Response, ApiError), `Repository/` (prepared statements, immer `store_id`-gescoped), `Service/` (Use-Cases: `AuthService`, `EntryService`, `ShareService` mit Share-Zustandsautomat), `Mail/`, `Session`, `RateLimiter`, `Migrator`.
- `migrations/NNN_name.sql` laeuft genau einmal (protokolliert in `schema_migrations`). Schemaaenderungen nur ueber neue Migrationsdateien.
- Neue API-Endpoints werden in der Routing-Tabelle in `public/api.php` registriert (Methode, Pfadmuster, Handler, Guard `public|auth|owner|csrf`); niemals Request-Werte in Include-Pfaden.

## Sicherheitsmodell (bewahren!)

- Split-Key-Verfahren: Der Browser leitet aus Passwort+Salt per PBKDF2-SHA256 (600k, versioniert) ein Master-Secret ab und splittet per HKDF in `auth`-Key (geht zum Server, dort Argon2id-gehasht) und `enc`-Key (bleibt im Browser). Passwort und Content-Key duerfen den Browser nie verlassen.
- Inhalte sind AES-256-GCM-verschluesselt mit frischem Zufalls-Nonce je Vorgang und AAD-Bindung (`crypto.js`); der Server speichert nur Ciphertext, IVs und KDF-Metadaten. Jede Payload traegt `crypto_version` - Formataenderungen brauchen eine neue Version plus Legacy-Lesepfad.
- Autorisierung ausschliesslich ueber die serverseitige Session (`Session::requireStoreId/requireOwner`); Mutationen pruefen `rowCount === 1`. Schluesselmaterial im Client existiert nur im Speicher (kein localStorage o. ae.).
- Sharing: Zustandsautomat `active -> requested -> granted|denied`, Widerruf jederzeit; Serverzeit ist allein massgeblich, Key-Pakete gibt es nur im Zustand `granted` gegen Seed-Nachweis.
