CREATE TABLE stores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    name           TEXT NOT NULL UNIQUE CHECK (length(name) BETWEEN 5 AND 64),
    auth_hash      TEXT NOT NULL,
    kdf_salt       TEXT NOT NULL,
    kdf_iterations INTEGER NOT NULL CHECK (kdf_iterations BETWEEN 100000 AND 5000000),
    kdf_version    INTEGER NOT NULL DEFAULT 1,
    created_at     TEXT NOT NULL,
    updated_at     TEXT
);

CREATE TABLE entries (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    store_id       INTEGER NOT NULL REFERENCES stores(id) ON DELETE CASCADE,
    entry_uid      TEXT NOT NULL UNIQUE CHECK (length(entry_uid) = 36),
    crypto_version INTEGER NOT NULL DEFAULT 1,
    title_ct       TEXT NOT NULL CHECK (length(title_ct) <= 8192),
    title_iv       TEXT NOT NULL CHECK (length(title_iv) <= 64),
    body_ct        TEXT NOT NULL CHECK (length(body_ct) <= 2097152),
    body_iv        TEXT NOT NULL CHECK (length(body_iv) <= 64),
    created_at     TEXT NOT NULL,
    updated_at     TEXT NOT NULL
);
CREATE INDEX idx_entries_store_updated ON entries(store_id, updated_at DESC);

CREATE TABLE shares (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    share_uid      TEXT NOT NULL UNIQUE CHECK (length(share_uid) = 36),
    store_id       INTEGER NOT NULL REFERENCES stores(id) ON DELETE CASCADE,
    crypto_version INTEGER NOT NULL DEFAULT 1,
    wrapped_key    TEXT NOT NULL CHECK (length(wrapped_key) <= 1024),
    wrap_iv        TEXT NOT NULL CHECK (length(wrap_iv) <= 64),
    kdf_salt       TEXT NOT NULL,
    kdf_iterations INTEGER NOT NULL CHECK (kdf_iterations BETWEEN 100000 AND 5000000),
    seed_auth_hash TEXT NOT NULL,
    owner_mail     TEXT NOT NULL CHECK (length(owner_mail) <= 254),
    delay_hours    INTEGER NOT NULL CHECK (delay_hours BETWEEN 0 AND 8760),
    status         TEXT NOT NULL DEFAULT 'active'
                   CHECK (status IN ('active','requested','granted','denied','revoked')),
    requested_at   TEXT,
    available_at   TEXT,
    decided_at     TEXT,
    revoked_at     TEXT,
    created_at     TEXT NOT NULL,
    updated_at     TEXT
);
CREATE INDEX idx_shares_store ON shares(store_id, status);

CREATE TABLE share_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    share_id   INTEGER NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
    event      TEXT NOT NULL,
    detail     TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE login_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    kind       TEXT NOT NULL,
    subject    TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_attempts ON login_attempts(kind, subject, created_at);
