/**
 * Client-Kryptografie auf Basis der Web Crypto API.
 *
 *   KDF (Store-Login/Content-Key):
 *     Version 2 (Default, neue Stores): Argon2id via hash-wasm (m=19 MiB,
 *       t=3, p=1), Salt 16 Byte zufällig. Memory-hart gegen GPU-Cracking.
 *     Version 1 (Legacy, nur migrierte Stores): PBKDF2-SHA256 (600k).
 *   KDF (Share-Seeds): PBKDF2-SHA256 - die 12-Wort-Phrase hat ~132 Bit
 *     Entropie, dort ist eine memory-harte KDF ohne Sicherheitsgewinn.
 *   Split:  HKDF-SHA256 aus dem Master-Secret; info "auth" (Server-Login),
 *           info "enc" (Content-Key) - der Server sieht nur den Auth-Key.
 *   AEAD:   AES-256-GCM, 12-Byte-Nonce frisch aus einem CSPRNG je Verschlüsselung,
 *           AAD bindet Version, Store, Entry-UID und Feldname.
 */

const te = new TextEncoder();
const td = new TextDecoder();

export const CRYPTO_VERSION = 1;
export const DEFAULT_ITERATIONS = 600000; // PBKDF2 (KDF-Version 1 und Share-Seeds)

// KDF-Versionen (Store-Login / Content-Key)
export const KDF_PBKDF2 = 1;
export const KDF_ARGON2 = 2;

// Argon2id-Defaults fuer neue Stores (an OWASP-Baseline ausgerichtet).
export const ARGON2_DEFAULTS = Object.freeze({
  version: KDF_ARGON2,
  time_cost: 3,
  memory: 19456, // KiB = 19 MiB
  parallelism: 1,
});

export function toB64(bytes) {
  return btoa(String.fromCharCode(...new Uint8Array(bytes)));
}

export function fromB64(b64) {
  return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
}

export function randomBytes(n) {
  const b = new Uint8Array(n);
  crypto.getRandomValues(b);
  return b;
}

/** PBKDF2: Passwort/Phrase -> 32 Byte Master-Secret (KDF-Version 1, Share-Seeds). */
export async function deriveMasterBits(secret, saltBytes, iterations) {
  const keyMaterial = await crypto.subtle.importKey("raw", te.encode(secret), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits(
    { name: "PBKDF2", hash: "SHA-256", salt: saltBytes, iterations },
    keyMaterial,
    256
  );
  return new Uint8Array(bits);
}

/** Argon2id: Passwort -> 32 Byte Master-Secret (KDF-Version 2). */
export async function deriveMasterArgon2(secret, saltBytes, { time_cost, memory, parallelism }) {
  if (typeof window.hashwasm === "undefined") {
    throw new Error("Argon2-Bibliothek nicht geladen.");
  }
  return window.hashwasm.argon2id({
    password: secret,
    salt: saltBytes,
    parallelism,
    iterations: time_cost,
    memorySize: memory, // KiB
    hashLength: 32,
    outputType: "binary",
  });
}

/**
 * Leitet das Master-Secret gemäß den (vom Server gelieferten oder lokal
 * gewählten) KDF-Parametern ab. Erwartet Server-Feldnamen:
 * { kdf_version, kdf_salt (Base64) | Salt-Bytes, kdf_time_cost, kdf_memory, kdf_parallelism }.
 */
export async function deriveMaster(secret, kdf) {
  const salt = kdf.salt_bytes ?? fromB64(kdf.kdf_salt);
  if (kdf.kdf_version === KDF_ARGON2) {
    return deriveMasterArgon2(secret, salt, {
      time_cost: kdf.kdf_time_cost,
      memory: kdf.kdf_memory,
      parallelism: kdf.kdf_parallelism,
    });
  }
  if (kdf.kdf_version === KDF_PBKDF2) {
    return deriveMasterBits(secret, salt, kdf.kdf_time_cost);
  }
  throw new Error("Unbekannte KDF-Version: " + kdf.kdf_version);
}

/** HKDF-Ableitung mit Domänentrennung über info. */
export async function hkdf(masterBits, info, length = 32) {
  const key = await crypto.subtle.importKey("raw", masterBits, "HKDF", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits(
    { name: "HKDF", hash: "SHA-256", salt: new Uint8Array(32), info: te.encode(info) },
    key,
    length * 8
  );
  return new Uint8Array(bits);
}

/** Rohbytes als nicht-extrahierbaren AES-GCM-Key importieren. */
export async function importAesKey(rawBytes) {
  return crypto.subtle.importKey("raw", rawBytes, "AES-GCM", false, ["encrypt", "decrypt"]);
}

/**
 * Master-Secret -> { authKeyB64 (für Server), contentKey (CryptoKey),
 * contentKeyRaw (für Share-Wrapping; nur im Speicher halten!) }.
 */
export async function splitKeys(masterBits) {
  const authBits = await hkdf(masterBits, "infostore/v1/auth");
  const encBits = await hkdf(masterBits, "infostore/v1/enc");
  return {
    authKeyB64: toB64(authBits),
    contentKeyRaw: encBits,
    contentKey: await importAesKey(encBits),
  };
}

export async function encrypt(key, plaintext, aad) {
  const iv = randomBytes(12);
  const ct = await crypto.subtle.encrypt(
    { name: "AES-GCM", iv, additionalData: te.encode(aad) },
    key,
    typeof plaintext === "string" ? te.encode(plaintext) : plaintext
  );
  return { ct: toB64(ct), iv: toB64(iv) };
}

/** Wirft bei Manipulation (Tag-/AAD-Fehler) - Aufrufer behandelt das als Alarm. */
export async function decrypt(key, ctB64, ivB64, aad) {
  const pt = await crypto.subtle.decrypt(
    { name: "AES-GCM", iv: fromB64(ivB64), additionalData: te.encode(aad) },
    key,
    fromB64(ctB64)
  );
  return td.decode(pt);
}

export async function decryptBytes(key, ctB64, ivB64, aad) {
  const pt = await crypto.subtle.decrypt(
    { name: "AES-GCM", iv: fromB64(ivB64), additionalData: te.encode(aad) },
    key,
    fromB64(ctB64)
  );
  return new Uint8Array(pt);
}

/** AAD für Entry-Felder: bindet Version, Store, Eintrag und Feld. */
export function entryAad(store, entryUid, field) {
  return `infostore|v${CRYPTO_VERSION}|${store}|${entryUid}|${field}`;
}

export function shareAad(shareUid) {
  return `infostore|v${CRYPTO_VERSION}|share|${shareUid}`;
}

/**
 * Kanonische Seed-Phrase: NFKD, Kleinschreibung, einfache Leerzeichen.
 * Gleiche Normalisierung bei Erzeugung und Eingabe.
 */
export function normalizeSeed(text) {
  return text
    .normalize("NFKD")
    .toLowerCase()
    .trim()
    .split(/\s+/)
    .join(" ");
}

/**
 * 12 Wörter gleichverteilt aus einer 2048er-Liste (11 Bit je Wort, ~132 Bit
 * Entropie). 2048 ist eine Zweierpotenz - die Maskierung ist verzerrungsfrei.
 */
export function generateSeedWords(wordlist, count = 12) {
  if (wordlist.length !== 2048) {
    throw new Error("Wortliste muss exakt 2048 Wörter haben.");
  }
  const values = new Uint16Array(count);
  crypto.getRandomValues(values);
  return Array.from(values, (v) => wordlist[v & 2047]);
}

/** Seed-Phrase -> Wrap-Key + Auth-Nachweis (getrennte HKDF-Domänen). */
export async function deriveSeedKeys(phrase, saltBytes, iterations) {
  const master = await deriveMasterBits(normalizeSeed(phrase), saltBytes, iterations);
  const wrapBits = await hkdf(master, "infostore/v1/share-wrap");
  const authBits = await hkdf(master, "infostore/v1/share-auth");
  return { wrapKey: await importAesKey(wrapBits), seedAuthB64: toB64(authBits) };
}
