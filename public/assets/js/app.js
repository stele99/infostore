/**
 * Secure Info Store - UI-Steuerung.
 *
 * Schlüsselmaterial (contentKey, contentKeyRaw) lebt ausschliesslich in
 * diesem Modul-Scope im Speicher: kein localStorage, kein sessionStorage,
 * keine URLs. Nach 15 Minuten Inaktivität oder Logout wird es verworfen.
 */

import { api, ApiException, setCsrf } from "./api.js";
import * as c from "./crypto.js";
import { wordlist } from "./wordlist.js";

const IDLE_LOCK_MS = 15 * 60 * 1000;

// ---- In-Memory-Zustand (nie persistieren!) ---------------------------------
let contentKey = null;      // CryptoKey AES-GCM
let contentKeyRaw = null;   // Uint8Array, nur für Share-Wrapping (Owner)
let storeName = null;
let role = null;            // "owner" | "recipient"
let entriesIndex = [];      // [{entry_uid, title, updated_at, created_at}]
let currentUid = null;
let currentUpdatedAt = null;
let dirty = false;
let idleTimer = null;
let quill = null;

// ---- Kleine DOM-Helfer ------------------------------------------------------
const $ = (id) => document.getElementById(id);
const views = ["view-login", "view-shareaccess", "view-main", "view-shares"];

function showView(id) {
  for (const v of views) {
    $(v).hidden = v !== id;
  }
}

function status(msg, kind = "info") {
  const el = $("statusmsg");
  el.textContent = msg; // immer Textknoten, nie HTML
  el.dataset.kind = kind;
  el.hidden = false;
  clearTimeout(status._t);
  status._t = setTimeout(() => (el.hidden = true), 6000);
}

function fail(err) {
  if (err instanceof ApiException) {
    status(err.message + (err.requestId ? ` (Ref ${err.requestId})` : ""), "error");
  } else {
    console.error(err);
    status("Unerwarteter Fehler.", "error");
  }
}

// ---- Sperren / Schlüssel verwerfen ----------------------------------------
function wipeKeys() {
  if (contentKeyRaw) contentKeyRaw.fill(0);
  contentKey = null;
  contentKeyRaw = null;
  storeName = null;
  role = null;
  entriesIndex = [];
  currentUid = null;
  currentUpdatedAt = null;
  dirty = false;
}

function lock(message) {
  wipeKeys();
  setCsrf(null);
  $("login-notice").textContent = message || "";
  $("login-notice").hidden = !message;
  showView("view-login");
}

function touchIdleTimer() {
  clearTimeout(idleTimer);
  if (contentKey) {
    idleTimer = setTimeout(() => {
      api.post("/auth/logout").catch(() => {});
      lock("Aus Sicherheitsgründen gesperrt (15 Minuten inaktiv). Bitte neu anmelden.");
    }, IDLE_LOCK_MS);
  }
}
for (const ev of ["click", "keydown", "mousemove"]) {
  document.addEventListener(ev, touchIdleTimer, { passive: true });
}

// ---- Login & Registrierung --------------------------------------------------
async function doLogin(register) {
  const name = $("login-store").value.trim();
  const password = $("login-password").value;
  if (name.length < 5 || password.length < 12) {
    status("Store-ID (min. 5) und Passwort (min. 12 Zeichen) prüfen.", "error");
    return;
  }
  status(register ? "Store wird angelegt..." : "Schlüssel wird abgeleitet...");
  try {
    let saltB64, iterations;
    if (register) {
      saltB64 = c.toB64(c.randomBytes(16));
      iterations = c.DEFAULT_ITERATIONS;
    } else {
      const kdf = await api.post("/auth/kdf", { store: name });
      saltB64 = kdf.kdf_salt;
      iterations = kdf.kdf_iterations;
    }
    const master = await c.deriveMasterBits(password, c.fromB64(saltB64), iterations);
    const keys = await c.splitKeys(master);
    master.fill(0);

    if (register) {
      await api.post("/auth/register", {
        store: name,
        auth_key: keys.authKeyB64,
        kdf_salt: saltB64,
        kdf_iterations: iterations,
      });
    }
    const session = await api.post("/auth/login", { store: name, auth_key: keys.authKeyB64 });
    setCsrf(session.csrf);

    contentKey = keys.contentKey;
    contentKeyRaw = keys.contentKeyRaw;
    storeName = name;
    role = "owner";
    $("login-password").value = "";
    enterMain();
    status(register ? "Store angelegt. Passwort gut verwahren - es gibt keine Wiederherstellung!" : "Angemeldet.", "ok");
  } catch (err) {
    fail(err);
  }
}

// ---- Hauptansicht -----------------------------------------------------------
function enterMain() {
  $("main-store").textContent = storeName + (role === "recipient" ? " (Lesezugriff)" : "");
  const owner = role === "owner";
  $("bt-shares").hidden = !owner;
  $("bt-save").hidden = !owner;
  $("bt-delete").hidden = !owner;
  $("bt-new").hidden = !owner;
  if (quill) {
    quill.enable(owner);
  }
  showView("view-main");
  touchIdleTimer();
  loadEntries().catch(fail);
}

function setDirty(d) {
  dirty = d;
  $("save-state").textContent = role !== "owner" ? "" : d ? "● ungespeichert" : "gespeichert";
}

async function loadEntries(selectUid = null) {
  const result = await api.get("/entries");
  entriesIndex = [];
  for (const row of result.entries) {
    let title;
    try {
      title = await c.decrypt(contentKey, row.title_ct, row.title_iv, c.entryAad(storeName, row.entry_uid, "title"));
    } catch {
      title = "⚠ Nicht entschlüsselbar (manipuliert?)";
    }
    entriesIndex.push({ uid: row.entry_uid, title, updated_at: row.updated_at, created_at: row.created_at });
  }
  renderEntryList();
  if (selectUid) {
    await openEntry(selectUid);
  }
}

function renderEntryList() {
  const filter = $("entry-search").value.trim().toLowerCase();
  const ul = $("entry-list");
  ul.textContent = "";
  for (const e of entriesIndex) {
    if (filter && !e.title.toLowerCase().includes(filter)) continue;
    const li = document.createElement("li");
    li.textContent = e.title || "(ohne Titel)";
    li.classList.toggle("active", e.uid === currentUid);
    li.addEventListener("click", () => openEntry(e.uid).catch(fail));
    ul.appendChild(li);
  }
}

async function openEntry(uid) {
  if (dirty && !confirm("Ungespeicherte Aenderungen verwerfen?")) return;
  const row = await api.get(`/entries/${uid}`);
  let title, body;
  try {
    title = await c.decrypt(contentKey, row.title_ct, row.title_iv, c.entryAad(storeName, uid, "title"));
    body = await c.decrypt(contentKey, row.body_ct, row.body_iv, c.entryAad(storeName, uid, "body"));
  } catch {
    status("Eintrag konnte nicht entschlüsselt werden - mögliche Manipulation!", "error");
    return;
  }
  currentUid = uid;
  currentUpdatedAt = row.updated_at;
  $("entry-title").value = title;
  $("entry-meta").textContent = `Erstellt ${row.created_at} · Geändert ${row.updated_at}`;
  // Inhalt ist ein Quill-Delta (strukturiertes JSON), kein HTML: kein XSS-Sink.
  try {
    quill.setContents(JSON.parse(body));
  } catch {
    quill.setContents({ ops: [{ insert: String(body) }] });
  }
  setDirty(false);
  renderEntryList();
}

function newEntry() {
  if (dirty && !confirm("Ungespeicherte Aenderungen verwerfen?")) return;
  currentUid = null;
  currentUpdatedAt = null;
  $("entry-title").value = "";
  $("entry-meta").textContent = "";
  quill.setContents({ ops: [] });
  setDirty(false);
  renderEntryList();
}

async function saveEntry() {
  const title = $("entry-title").value.trim();
  if (!title) {
    status("Bitte einen Titel angeben.", "error");
    return;
  }
  const uid = currentUid ?? crypto.randomUUID();
  const body = JSON.stringify(quill.getContents());
  const encTitle = await c.encrypt(contentKey, title, c.entryAad(storeName, uid, "title"));
  const encBody = await c.encrypt(contentKey, body, c.entryAad(storeName, uid, "body"));
  try {
    const result = await api.put(`/entries/${uid}`, {
      crypto_version: c.CRYPTO_VERSION,
      title_ct: encTitle.ct,
      title_iv: encTitle.iv,
      body_ct: encBody.ct,
      body_iv: encBody.iv,
      expected_updated_at: currentUpdatedAt ?? "",
    });
    currentUid = uid;
    currentUpdatedAt = result.updated_at;
    setDirty(false);
    status("Gespeichert.", "ok");
    await loadEntries(uid);
  } catch (err) {
    if (err instanceof ApiException && err.code === "conflict") {
      status("Konflikt: Der Eintrag wurde parallel geändert. Bitte neu laden.", "error");
    } else {
      fail(err);
    }
  }
}

async function deleteEntry() {
  if (!currentUid) return;
  const entry = entriesIndex.find((e) => e.uid === currentUid);
  if (!confirm(`Eintrag "${entry ? entry.title : ""}" wirklich löschen?`)) return;
  try {
    await api.del(`/entries/${currentUid}`);
    status("Eintrag gelöscht.", "ok");
    newEntry();
    await loadEntries();
  } catch (err) {
    fail(err);
  }
}

// ---- Share-Verwaltung (Owner) ----------------------------------------------
function regenSeed() {
  $("share-seed").textContent = c.generateSeedWords(wordlist).join(" ");
}

/**
 * Befüllt die Druckvorlage mit den aktuell im Formular stehenden Werten und
 * öffnet den Systemdruckdialog. Die Phrase verlässt dabei nie den Browser -
 * es wird nichts hochgeladen, nur das aktuelle DOM für den Druck ausgeblendet
 * bzw. eingeblendet (siehe @media print in main.css).
 */
function printShareSheet() {
  const phrase = $("share-seed").textContent.trim();
  const words = phrase.split(/\s+/).filter(Boolean);
  if (words.length !== 12) {
    status("Bitte zuerst eine Seed-Phrase erzeugen.", "error");
    return;
  }

  const list = $("ps-seed-list");
  list.textContent = "";
  words.forEach((word) => {
    const li = document.createElement("li");
    li.textContent = word;
    list.appendChild(li);
  });

  const url = location.origin + location.pathname;
  const delay = Number($("share-delay").value) || 0;
  $("ps-store").textContent = storeName || "(Store-ID hier eintragen)";
  $("ps-url").textContent = url;
  $("ps-delay").textContent = delay === 0 ? "keine (sofortiger Zugriff)" : `${delay} Stunden`;
  $("ps-mail").textContent = $("share-mail").value.trim() || "(nicht angegeben)";
  $("ps-date").textContent = new Date().toLocaleString("de-DE");

  window.print();
}

async function createShare(ev) {
  ev.preventDefault();
  const phrase = $("share-seed").textContent.trim();
  if (phrase.split(" ").length !== 12) {
    status("Bitte zuerst eine Seed-Phrase erzeugen.", "error");
    return;
  }
  try {
    const shareUid = crypto.randomUUID();
    const salt = c.randomBytes(16);
    const { wrapKey, seedAuthB64 } = await c.deriveSeedKeys(phrase, salt, c.DEFAULT_ITERATIONS);
    const wrapped = await c.encrypt(wrapKey, contentKeyRaw, c.shareAad(shareUid));
    await api.post("/shares", {
      share_uid: shareUid,
      crypto_version: c.CRYPTO_VERSION,
      wrapped_key: wrapped.ct,
      wrap_iv: wrapped.iv,
      kdf_salt: c.toB64(salt),
      kdf_iterations: c.DEFAULT_ITERATIONS,
      seed_auth: seedAuthB64,
      owner_mail: $("share-mail").value.trim(),
      delay_hours: Number($("share-delay").value),
    });
    status("Share eingerichtet. Seed-Phrase jetzt sicher übergeben - sie wird nicht erneut angezeigt.", "ok");
    await renderShares();
  } catch (err) {
    fail(err);
  }
}

async function renderShares() {
  const result = await api.get("/shares");
  const ul = $("share-list");
  ul.textContent = "";
  if (result.shares.length === 0) {
    const li = document.createElement("li");
    li.textContent = "Keine Shares vorhanden.";
    ul.appendChild(li);
    return;
  }
  for (const s of result.shares) {
    const li = document.createElement("li");
    const info = document.createElement("span");
    let text = `${s.status.toUpperCase()} · angelegt ${s.created_at} · Wartezeit ${s.delay_hours}h`;
    if (s.status === "requested") text += ` · Freigabe am ${s.available_at}`;
    info.textContent = text;
    li.appendChild(info);

    const addBtn = (label, danger, fn) => {
      const b = document.createElement("button");
      b.textContent = label;
      if (danger) b.classList.add("danger");
      b.addEventListener("click", () => fn().then(renderShares).catch(fail));
      li.appendChild(b);
    };
    if (s.status === "requested") {
      addBtn("Ablehnen", true, () => api.post(`/shares/${s.share_uid}/deny`));
    }
    if (s.status !== "revoked") {
      addBtn("Widerrufen", true, () => api.post(`/shares/${s.share_uid}/revoke`));
    }
    addBtn("Entfernen", false, async () => {
      if (confirm("Share endgültig entfernen?")) await api.del(`/shares/${s.share_uid}`);
    });
    ul.appendChild(li);
  }
}

// ---- Notfallzugriff (Empfänger) -------------------------------------------
async function requestShareAccess(ev) {
  ev.preventDefault();
  const store = $("sa-store").value.trim();
  const phrase = c.normalizeSeed($("sa-seed").value);
  if (phrase.split(" ").length !== 12) {
    status("Die Seed-Phrase muss aus 12 Wörtern bestehen.", "error");
    return;
  }
  const box = $("sa-status");
  box.hidden = false;
  box.textContent = "Schlüssel werden geprüft...";
  try {
    const { shares } = await api.post("/share-access/kdf", { store });
    let granted = null, waiting = null, denied = null;
    for (const s of shares) {
      const { seedAuthB64, wrapKey } = await c.deriveSeedKeys(phrase, c.fromB64(s.kdf_salt), s.kdf_iterations);
      let result;
      try {
        result = await api.post("/share-access/request", {
          store,
          share_uid: s.share_uid,
          seed_auth: seedAuthB64,
        });
      } catch (err) {
        if (err instanceof ApiException && (err.status === 401 || err.status === 404)) continue;
        throw err;
      }
      if (result.status === "granted") {
        granted = { result, wrapKey, shareUid: s.share_uid };
        break;
      }
      if (result.status === "requested") waiting = result;
      if (result.status === "denied" || result.status === "revoked") denied = result;
    }

    if (granted) {
      const raw = await c.decryptBytes(
        granted.wrapKey,
        granted.result.wrapped_key,
        granted.result.wrap_iv,
        c.shareAad(granted.shareUid)
      );
      contentKey = await c.importAesKey(raw);
      raw.fill(0);
      contentKeyRaw = null;
      storeName = store;
      role = "recipient";
      setCsrf(granted.result.csrf);
      box.hidden = true;
      enterMain();
      status("Zugriff gewährt (nur Lesen).", "ok");
    } else if (waiting) {
      box.textContent = `Anfrage läuft. Freigabe am ${waiting.available_at} (UTC) - der Inhaber wurde benachrichtigt und kann ablehnen. Diese Seite später erneut aufrufen.`;
    } else if (denied) {
      box.textContent = denied.status === "denied"
        ? "Der Inhaber hat die Anfrage abgelehnt."
        : "Der Zugriff wurde widerrufen.";
    } else {
      box.textContent = "Kein passender Share gefunden - Store-ID und Seed-Phrase prüfen.";
    }
  } catch (err) {
    box.hidden = true;
    fail(err);
  }
}

// ---- Initialisierung --------------------------------------------------------
// Tooltips für die reinen Icon-Buttons der Toolbar (Quill liefert keine mit).
const TOOLBAR_TITLES = {
  bold: "Fett", italic: "Kursiv", underline: "Unterstrichen", strike: "Durchgestrichen",
  blockquote: "Zitat", "code-block": "Code", link: "Link einfügen", clean: "Formatierung entfernen",
  "list-ordered": "Nummerierte Liste", "list-bullet": "Aufzählung", "list-check": "Checkliste",
};

function labelToolbarButtons(root) {
  root.querySelectorAll("button").forEach((btn) => {
    const cls = [...btn.classList].find((c) => c.startsWith("ql-") && c !== "ql-active");
    if (!cls) return;
    const name = cls.slice(3);
    const value = btn.getAttribute("value");
    const key = value ? `${name}-${value}` : name;
    const title = TOOLBAR_TITLES[key] || TOOLBAR_TITLES[name];
    if (title) btn.title = title;
  });
  root.querySelectorAll(".ql-picker").forEach((picker) => {
    if (picker.classList.contains("ql-header")) picker.setAttribute("aria-label", "Textformat");
    if (picker.classList.contains("ql-color")) picker.setAttribute("aria-label", "Textfarbe");
  });
}

function initQuill() {
  quill = new Quill("#editor", {
    modules: {
      // Schlanke, auf das Wesentliche reduzierte Toolbar statt der vollen
      // Quill-Standardpalette - passt zum kompakteren Stil in main.css.
      toolbar: [
        [{ header: [false, 2, 3] }],
        ["bold", "italic", "underline", "strike"],
        [{ color: [] }],
        [{ list: "ordered" }, { list: "bullet" }, { list: "check" }],
        ["blockquote", "code-block", "link"],
        ["clean"],
      ],
    },
    theme: "snow",
  });
  labelToolbarButtons(document.querySelector(".ql-toolbar"));
  quill.on("text-change", (d, o, source) => {
    if (source === "user") setDirty(true);
  });
}

function wire() {
  $("form-login").addEventListener("submit", (ev) => {
    ev.preventDefault();
    doLogin(false);
  });
  $("bt-register").addEventListener("click", () => doLogin(true));
  $("link-shareaccess").addEventListener("click", (ev) => {
    ev.preventDefault();
    showView("view-shareaccess");
  });
  $("bt-sa-back").addEventListener("click", () => showView("view-login"));
  $("form-shareaccess").addEventListener("submit", (ev) => requestShareAccess(ev).catch(fail));

  $("bt-logout").addEventListener("click", async () => {
    try {
      await api.post("/auth/logout");
    } catch {
      /* Session serverseitig ggf. schon abgelaufen */
    }
    lock("Abgemeldet.");
  });
  $("bt-save").addEventListener("click", () => saveEntry().catch(fail));
  $("bt-new").addEventListener("click", newEntry);
  $("bt-delete").addEventListener("click", () => deleteEntry().catch(fail));
  $("entry-title").addEventListener("input", () => setDirty(true));
  $("entry-search").addEventListener("input", renderEntryList);

  $("bt-shares").addEventListener("click", () => {
    regenSeed();
    renderShares().catch(fail);
    showView("view-shares");
  });
  $("bt-shares-back").addEventListener("click", () => showView("view-main"));
  $("bt-seed-new").addEventListener("click", regenSeed);
  $("bt-seed-print").addEventListener("click", printShareSheet);
  $("form-share-create").addEventListener("submit", (ev) => createShare(ev));

  window.addEventListener("beforeunload", (ev) => {
    if (dirty) {
      ev.preventDefault();
    }
  });
}

document.addEventListener("DOMContentLoaded", () => {
  initQuill();
  wire();
  // Kein Auto-Login: Der Content-Key existiert nur im Speicher; nach einem
  // Reload ist immer eine erneute Passworteingabe nötig.
  lock("");
});
