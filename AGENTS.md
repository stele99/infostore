# Secure Info Store

## Run and Verify

- This is a dependency-free PHP application; no package manifest, test suite, CI workflow, or formatter configuration is present.
- `inc/config.inc.php` hard-codes the deployed `ROOTPATH` (`/home/www/4-host/app/infostore`). Set it to the local checkout before exercising AJAX locally, then run `php -S localhost:8000` from the repository root. The configured SQLite file is `ROOTPATH/.data/data.sqlite3`.
- The available repository-wide check is PHP syntax linting: `for f in $(git ls-files '*.php'); do php -l "$f" || exit 1; done`.
- `.data/` is ignored. `initDB()` creates missing tables, but uses `CREATE TABLE IF NOT EXISTS`; it does not migrate existing databases when the schema changes.

## Structure and Data Flow

- `index.php` is the login shell. Successful client-side login loads `edit.php`; the editor behavior is in `js/entry.class.js` and sharing behavior is in `js/share.class.js`.
- `ajax.php?m=<method>` JSON-decodes the request and includes `ajax/<method>.inc.php`. Add endpoint handlers as `.inc.php` files and keep the `$ajaxRet` JSON response shape (`status`, `msg`, `data`).
- `src/class_dbobject.php` provides the PDO persistence layer; `m_data`, `m_user`, and `m_share` bind it to the `data`, `users`, and `shares` tables.
- `js/js.php` emits every top-level `.js` file in `js/` and assigns `window.onload = init`; do not add separate script tags for those files. It does not recurse into `js/ext/`.

## Security Boundary

- Entry titles and content are encrypted in the browser by `js/se_crypt.class.js` before `save`; the server stores and returns ciphertext plus IV. Preserve the client/server field names and encryption flow when changing entry or sharing payloads.
- Login identity is a client-side SHA-256 hash of the store ID, and the browser retains the derived key and verification values in `localStorage`; changing these formats breaks access to existing stores.
