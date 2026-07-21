-- KDF-Version 2 (Argon2id) einfuehren.
--
-- Das bisherige stores-Schema hatte einen starren CHECK auf kdf_iterations
-- (100k..5M), passend nur fuer PBKDF2. Argon2id braucht andere Parameter
-- (kleiner timeCost, memory in KiB, parallelism). Daher wird stores neu
-- aufgebaut mit generischen KDF-Spalten und einem versionsabhaengigen CHECK.
--
-- Bestandsdaten (KDF-Version 1 = PBKDF2) werden uebernommen:
--   kdf_time_cost = altes kdf_iterations, kdf_memory/kdf_parallelism = NULL.
-- Der Rebuild laeuft mit deaktivierter FK-Durchsetzung (siehe Migrator).

CREATE TABLE stores_new (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL UNIQUE CHECK (length(name) BETWEEN 5 AND 64),
    auth_hash       TEXT NOT NULL,
    kdf_version     INTEGER NOT NULL DEFAULT 2,
    kdf_salt        TEXT NOT NULL,
    kdf_time_cost   INTEGER NOT NULL,   -- v1: PBKDF2-Iterationen, v2: Argon2-timeCost
    kdf_memory      INTEGER,            -- v2: Argon2 Speicher in KiB, sonst NULL
    kdf_parallelism INTEGER,            -- v2: Argon2 Lanes, sonst NULL
    created_at      TEXT NOT NULL,
    updated_at      TEXT,
    CHECK (
        (kdf_version = 1
            AND kdf_time_cost BETWEEN 100000 AND 10000000
            AND kdf_memory IS NULL
            AND kdf_parallelism IS NULL)
        OR
        (kdf_version = 2
            AND kdf_time_cost BETWEEN 1 AND 20
            AND kdf_memory BETWEEN 8192 AND 1048576
            AND kdf_parallelism BETWEEN 1 AND 4)
    )
);

INSERT INTO stores_new
    (id, name, auth_hash, kdf_version, kdf_salt, kdf_time_cost, kdf_memory, kdf_parallelism, created_at, updated_at)
SELECT
    id, name, auth_hash, kdf_version, kdf_salt, kdf_iterations, NULL, NULL, created_at, updated_at
FROM stores;

DROP TABLE stores;
ALTER TABLE stores_new RENAME TO stores;
