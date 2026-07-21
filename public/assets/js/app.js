/**
 * Secure Info Store - UI-Steuerung.
 *
 * Schlüsselmaterial (contentKey, contentKeyRaw) lebt ausschliesslich in
 * diesem Modul-Scope im Speicher: kein localStorage, kein sessionStorage,
 * keine URLs. Nach 15 Minuten Inaktivität oder Logout wird es verworfen.
 * Ausnahme: Theme-Präferenz in localStorage (kein Key-Material).
 */

import { api, ApiException, setCsrf } from "./api.js";
import * as c from "./crypto.js";
import { wordlist } from "./wordlist.js";

const IDLE_LOCK_MS = 15 * 60 * 1000;
const THEME_KEY = "infostore-theme";

// ---- In-Memory-Zustand (nie persistieren!) ---------------------------------
let contentKey = null;
let contentKeyRaw = null;
let storeName = null;
let role = null;
let entriesIndex = [];
let currentUid = null;
let currentUpdatedAt = null;
let dirty = false;
let idleTimer = null;
let quill = null;
let registerMode = false;
let dialogResolver = null;

// ---- DOM-Helfer -------------------------------------------------------------
const $ = (id) => document.getElementById(id);
const views = ["view-login", "view-shareaccess", "view-main", "view-shares"];

function showView(id) {
  closeDrawer();
  for (const v of views) {
    $(v).hidden = v !== id;
  }
  document.body.classList.toggle("app-main", id === "view-main");
}

function setBusy(btn, busy, label) {
  if (!btn) return;
  btn.disabled = busy;
  btn.classList.toggle("is-loading", busy);
  const spin = btn.querySelector(".spinner");
  const text = btn.querySelector(".btn-label");
  if (spin) spin.hidden = !busy;
  if (text && label) text.textContent = label;
}

function hideToast() {
  const el = $("statusmsg");
  el.classList.remove("is-visible");
  clearTimeout(status._hide);
  clearTimeout(status._remove);
  status._remove = setTimeout(() => {
    el.hidden = true;
  }, 220);
}

function status(msg, kind = "info") {
  const el = $("statusmsg");
  const text = $("statusmsg-text");
  const ms = kind === "error" ? 5200 : kind === "warn" ? 4200 : 3200;

  clearTimeout(status._hide);
  clearTimeout(status._remove);
  text.textContent = msg;
  el.dataset.kind = kind;
  el.hidden = false;
  // Re-trigger enter animation when a new toast replaces the current one
  el.classList.remove("is-visible");
  void el.offsetWidth;
  el.classList.add("is-visible");

  status._hide = setTimeout(hideToast, ms);
}

function fail(err) {
  if (err instanceof ApiException) {
    status(err.message + (err.requestId ? ` (Ref ${err.requestId})` : ""), "error");
  } else {
    console.error(err);
    status("Unerwarteter Fehler.", "error");
  }
}

function confirmDialog({ title, body, confirmLabel = "Bestätigen", danger = true }) {
  return new Promise((resolve) => {
    dialogResolver = resolve;
    $("dialog-title").textContent = title;
    $("dialog-body").textContent = body;
    const ok = $("dialog-confirm");
    ok.textContent = confirmLabel;
    ok.className = danger ? "danger" : "primary";
    $("dialog-backdrop").hidden = false;
    ok.focus();
  });
}

function closeDialog(result) {
  $("dialog-backdrop").hidden = true;
  if (dialogResolver) {
    const r = dialogResolver;
    dialogResolver = null;
    r(result);
  }
}

// ---- Theme ------------------------------------------------------------------
function applyTheme(theme) {
  const t = theme === "light" ? "light" : "dark";
  document.documentElement.setAttribute("data-theme", t);
  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.content = t === "light" ? "#eef1f7" : "#0b0f17";
  const btn = $("bt-theme");
  if (btn) {
    btn.title = t === "light" ? "Dunkles Design" : "Helles Design";
    btn.setAttribute("aria-label", btn.title);
  }
}

function initTheme() {
  let theme = "dark";
  try {
    const saved = localStorage.getItem(THEME_KEY);
    if (saved === "light" || saved === "dark") theme = saved;
  } catch {
    /* private mode */
  }
  applyTheme(theme);
}

function toggleTheme() {
  const cur = document.documentElement.getAttribute("data-theme") === "light" ? "light" : "dark";
  const next = cur === "light" ? "dark" : "light";
  applyTheme(next);
  try {
    localStorage.setItem(THEME_KEY, next);
  } catch {
    /* ignore */
  }
}

// ---- Drawer (Mobile) --------------------------------------------------------
function openDrawer() {
  document.body.classList.add("drawer-open");
  $("sidebar-backdrop").hidden = false;
  $("bt-drawer")?.setAttribute("aria-expanded", "true");
}

function closeDrawer() {
  document.body.classList.remove("drawer-open");
  const bd = $("sidebar-backdrop");
  if (bd) bd.hidden = true;
  $("bt-drawer")?.setAttribute("aria-expanded", "false");
}

function toggleDrawer() {
  if (document.body.classList.contains("drawer-open")) closeDrawer();
  else openDrawer();
}

// ---- Relative Zeit ----------------------------------------------------------
function formatRelative(iso) {
  if (!iso) return "";
  const t = Date.parse(iso.includes("T") || iso.includes("Z") ? iso : iso.replace(" ", "T") + "Z");
  if (Number.isNaN(t)) return iso;
  const diff = Date.now() - t;
  const sec = Math.round(diff / 1000);
  if (sec < 60) return "gerade eben";
  const min = Math.round(sec / 60);
  if (min < 60) return `vor ${min} Min.`;
  const h = Math.round(min / 60);
  if (h < 48) return `vor ${h} Std.`;
  const d = Math.round(h / 24);
  if (d < 30) return `vor ${d} Tag${d === 1 ? "" : "en"}`;
  try {
    return new Date(t).toLocaleDateString("de-DE", { day: "2-digit", month: "short", year: "numeric" });
  } catch {
    return iso;
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
  closeDrawer();
  setDirty(false);
  const notice = $("login-notice");
  notice.textContent = message || "";
  notice.hidden = !message;
  notice.dataset.kind = "warn";
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
for (const ev of ["click", "keydown", "mousemove", "touchstart"]) {
  document.addEventListener(ev, touchIdleTimer, { passive: true });
}

// ---- Auth-Modus -------------------------------------------------------------
function setAuthMode(register) {
  registerMode = register;
  $("tab-login").setAttribute("aria-selected", register ? "false" : "true");
  $("tab-register").setAttribute("aria-selected", register ? "true" : "false");
  $("login-title").textContent = register ? "Neuen Store anlegen" : "Anmelden";
  const pw = $("login-password");
  pw.autocomplete = register ? "new-password" : "current-password";
  $("login-pw-hint").textContent = register
    ? "Wähle ein starkes Passwort (min. 12 Zeichen). Es gibt keine serverseitige Wiederherstellung."
    : "Mindestens 12 Zeichen. Ohne Passwort sind die Daten unwiederbringlich verloren.";
  const btn = $("bt-login");
  const label = btn.querySelector(".btn-label");
  if (label) label.textContent = register ? "Store anlegen" : "Anmelden";
}

function togglePasswordVisibility() {
  const input = $("login-password");
  const btn = $("bt-pw-toggle");
  const show = input.type === "password";
  input.type = show ? "text" : "password";
  btn.setAttribute("aria-label", show ? "Passwort verbergen" : "Passwort anzeigen");
  btn.title = btn.getAttribute("aria-label");
}

// ---- Login & Registrierung --------------------------------------------------
async function doLogin() {
  const name = $("login-store").value.trim();
  const password = $("login-password").value;
  if (name.length < 5 || password.length < 12) {
    status("Store-ID (min. 5) und Passwort (min. 12 Zeichen) prüfen.", "error");
    return;
  }
  const btn = $("bt-login");
  setBusy(btn, true, registerMode ? "Wird angelegt…" : "Schlüssel ableiten…");
  try {
    let kdf;
    if (registerMode) {
      // Neuer Store: Argon2id (KDF-Version 2) mit frischem Zufalls-Salt.
      kdf = {
        kdf_version: c.KDF_ARGON2,
        kdf_salt: c.toB64(c.randomBytes(16)),
        kdf_time_cost: c.ARGON2_DEFAULTS.time_cost,
        kdf_memory: c.ARGON2_DEFAULTS.memory,
        kdf_parallelism: c.ARGON2_DEFAULTS.parallelism,
      };
    } else {
      // Login: KDF-Parameter des Stores vom Server holen (Version 1 oder 2).
      kdf = await api.post("/auth/kdf", { store: name });
    }
    const master = await c.deriveMaster(password, kdf);
    const keys = await c.splitKeys(master);
    master.fill(0);

    if (registerMode) {
      await api.post("/auth/register", {
        store: name,
        auth_key: keys.authKeyB64,
        kdf_version: kdf.kdf_version,
        kdf_salt: kdf.kdf_salt,
        kdf_time_cost: kdf.kdf_time_cost,
        kdf_memory: kdf.kdf_memory,
        kdf_parallelism: kdf.kdf_parallelism,
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
    status(
      registerMode
        ? "Store angelegt. Passwort gut verwahren – es gibt keine Wiederherstellung!"
        : "Angemeldet.",
      "ok"
    );
  } catch (err) {
    fail(err);
  } finally {
    setBusy(btn, false, registerMode ? "Store anlegen" : "Anmelden");
  }
}

// ---- Hauptansicht -----------------------------------------------------------
function enterMain() {
  $("main-store").textContent = storeName;
  const owner = role === "owner";
  $("bt-shares").hidden = !owner;
  $("bt-save").hidden = !owner;
  $("bt-delete").hidden = !owner;
  $("bt-new").hidden = !owner;
  $("bt-new-side").hidden = !owner;
  $("recipient-banner").hidden = owner;
  const badge = $("role-badge");
  if (owner) {
    badge.hidden = true;
  } else {
    badge.hidden = false;
    badge.textContent = "Lesezugriff";
    badge.className = "badge badge-warn";
  }
  if (quill) quill.enable(owner);
  $("entry-title").readOnly = !owner;
  showView("view-main");
  touchIdleTimer();
  setDirty(false);
  loadEntries().catch(fail);
}

function setDirty(d) {
  dirty = d;
  const el = $("save-state");
  if (role !== "owner") {
    el.hidden = true;
    el.textContent = "";
    return;
  }
  el.hidden = false;
  if (d) {
    el.textContent = "ungespeichert";
    el.className = "badge badge-warn";
  } else {
    el.textContent = "gespeichert";
    el.className = "badge badge-ok";
  }
}

function setListLoading(on) {
  $("entry-list-loading").hidden = !on;
  $("entry-list").hidden = on;
}

async function loadEntries(selectUid = null) {
  setListLoading(true);
  try {
    const result = await api.get("/entries");
    entriesIndex = [];
    for (const row of result.entries) {
      let title;
      try {
        title = await c.decrypt(contentKey, row.title_ct, row.title_iv, c.entryAad(storeName, row.entry_uid, "title"));
      } catch {
        title = "⚠ Nicht entschlüsselbar";
      }
      entriesIndex.push({
        uid: row.entry_uid,
        title,
        updated_at: row.updated_at,
        created_at: row.created_at,
      });
    }
    renderEntryList();
    if (selectUid) await openEntry(selectUid);
  } finally {
    setListLoading(false);
  }
}

function renderEntryList() {
  const filter = $("entry-search").value.trim().toLowerCase();
  const ul = $("entry-list");
  ul.textContent = "";

  const filtered = entriesIndex.filter((e) => !filter || e.title.toLowerCase().includes(filter));

  if (filtered.length === 0) {
    const empty = document.createElement("li");
    empty.className = "empty-state";
    empty.setAttribute("aria-disabled", "true");
    const strong = document.createElement("strong");
    strong.textContent = filter ? "Keine Treffer" : "Noch keine Notizen";
    const p = document.createElement("span");
    p.textContent = filter
      ? "Andere Suche versuchen."
      : role === "owner"
        ? "Lege den ersten Eintrag an."
        : "In diesem Store gibt es noch keine Einträge.";
    empty.append(strong, document.createElement("br"), p);
    ul.appendChild(empty);
    return;
  }

  for (const e of filtered) {
    const li = document.createElement("li");
    li.setAttribute("role", "option");
    li.tabIndex = 0;
    li.dataset.uid = e.uid;
    li.setAttribute("aria-selected", e.uid === currentUid ? "true" : "false");
    if (e.uid === currentUid) li.classList.add("active");

    const title = document.createElement("span");
    title.className = "entry-title";
    title.textContent = e.title || "(ohne Titel)";

    const time = document.createElement("span");
    time.className = "entry-time";
    time.textContent = formatRelative(e.updated_at || e.created_at);

    li.append(title, time);
    li.addEventListener("click", () => openEntry(e.uid).catch(fail));
    li.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter" || ev.key === " ") {
        ev.preventDefault();
        openEntry(e.uid).catch(fail);
      }
    });
    ul.appendChild(li);
  }
}

async function openEntry(uid) {
  if (dirty) {
    const ok = await confirmDialog({
      title: "Ungespeicherte Änderungen",
      body: "Möchtest du die ungespeicherten Änderungen verwerfen?",
      confirmLabel: "Verwerfen",
      danger: true,
    });
    if (!ok) return;
  }
  const row = await api.get(`/entries/${uid}`);
  let title, body;
  try {
    title = await c.decrypt(contentKey, row.title_ct, row.title_iv, c.entryAad(storeName, uid, "title"));
    body = await c.decrypt(contentKey, row.body_ct, row.body_iv, c.entryAad(storeName, uid, "body"));
  } catch {
    status("Eintrag konnte nicht entschlüsselt werden – mögliche Manipulation!", "error");
    return;
  }
  currentUid = uid;
  currentUpdatedAt = row.updated_at;
  $("entry-title").value = title;
  $("entry-meta").textContent = `Erstellt ${row.created_at} · Geändert ${row.updated_at}`;
  try {
    quill.setContents(JSON.parse(body));
  } catch {
    quill.setContents({ ops: [{ insert: String(body) }] });
  }
  setDirty(false);
  renderEntryList();
  closeDrawer();
}

async function newEntry() {
  if (dirty) {
    const ok = await confirmDialog({
      title: "Ungespeicherte Änderungen",
      body: "Möchtest du die ungespeicherten Änderungen verwerfen?",
      confirmLabel: "Verwerfen",
      danger: true,
    });
    if (!ok) return;
  }
  currentUid = null;
  currentUpdatedAt = null;
  $("entry-title").value = "";
  $("entry-meta").textContent = "";
  quill.setContents({ ops: [] });
  setDirty(false);
  renderEntryList();
  closeDrawer();
  $("entry-title").focus();
}

async function saveEntry() {
  const title = $("entry-title").value.trim();
  if (!title) {
    status("Bitte einen Titel angeben.", "error");
    $("entry-title").focus();
    return;
  }
  const btn = $("bt-save");
  setBusy(btn, true, "Speichern…");
  const uid = currentUid ?? crypto.randomUUID();
  const body = JSON.stringify(quill.getContents());
  try {
    const encTitle = await c.encrypt(contentKey, title, c.entryAad(storeName, uid, "title"));
    const encBody = await c.encrypt(contentKey, body, c.entryAad(storeName, uid, "body"));
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
  } finally {
    setBusy(btn, false, "Speichern");
  }
}

async function deleteEntry() {
  if (!currentUid) return;
  const entry = entriesIndex.find((e) => e.uid === currentUid);
  const ok = await confirmDialog({
    title: "Eintrag löschen",
    body: `„${entry ? entry.title : "Diesen Eintrag"}“ wirklich unwiderruflich löschen?`,
    confirmLabel: "Löschen",
    danger: true,
  });
  if (!ok) return;
  try {
    await api.del(`/entries/${currentUid}`);
    status("Eintrag gelöscht.", "ok");
    await newEntry();
    await loadEntries();
  } catch (err) {
    fail(err);
  }
}

// ---- Share-Verwaltung (Owner) ----------------------------------------------
function regenSeed() {
  $("share-seed").textContent = c.generateSeedWords(wordlist).join(" ");
}

async function copySeed() {
  const phrase = $("share-seed").textContent.trim();
  if (!phrase) {
    status("Bitte zuerst eine Seed-Phrase erzeugen.", "error");
    return;
  }
  try {
    await navigator.clipboard.writeText(phrase);
    status("Seed-Phrase kopiert.", "ok");
  } catch {
    status("Kopieren nicht möglich – bitte manuell markieren.", "warn");
  }
}

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

function shareStatusBadge(statusName) {
  const map = {
    active: ["badge badge-ok", "aktiv"],
    requested: ["badge badge-warn", "angefragt"],
    granted: ["badge badge-info", "freigegeben"],
    denied: ["badge badge-danger", "abgelehnt"],
    revoked: ["badge badge-danger", "widerrufen"],
  };
  const [cls, label] = map[statusName] || ["badge", statusName];
  const el = document.createElement("span");
  el.className = cls;
  el.textContent = label;
  return el;
}

async function createShare(ev) {
  ev.preventDefault();
  const phrase = $("share-seed").textContent.trim();
  if (phrase.split(/\s+/).filter(Boolean).length !== 12) {
    status("Bitte zuerst eine Seed-Phrase erzeugen.", "error");
    return;
  }
  const btn = $("bt-share-create");
  setBusy(btn, true, "Einrichten…");
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
    status("Share eingerichtet. Seed-Phrase jetzt sicher übergeben – sie wird nicht erneut angezeigt.", "ok");
    regenSeed();
    await renderShares();
  } catch (err) {
    fail(err);
  } finally {
    setBusy(btn, false, "Share einrichten");
  }
}

async function renderShares() {
  const result = await api.get("/shares");
  const ul = $("share-list");
  ul.textContent = "";
  if (result.shares.length === 0) {
    const li = document.createElement("li");
    li.className = "empty-state";
    const strong = document.createElement("strong");
    strong.textContent = "Keine Shares vorhanden";
    const span = document.createElement("span");
    span.textContent = "Richte oben einen Notfallzugriff ein.";
    li.append(strong, document.createElement("br"), span);
    ul.appendChild(li);
    return;
  }
  for (const s of result.shares) {
    const li = document.createElement("li");
    const info = document.createElement("div");
    info.className = "share-info";
    info.appendChild(shareStatusBadge(s.status));
    const meta = document.createElement("div");
    meta.className = "share-meta";
    let text = `Angelegt ${s.created_at} · Wartezeit ${s.delay_hours}h`;
    if (s.status === "requested" && s.available_at) text += ` · Freigabe am ${s.available_at}`;
    meta.textContent = text;
    info.appendChild(meta);

    const actions = document.createElement("div");
    actions.className = "share-actions";

    const addBtn = (label, cls, fn) => {
      const b = document.createElement("button");
      b.type = "button";
      b.textContent = label;
      if (cls) b.className = cls;
      b.addEventListener("click", () => fn().then(renderShares).catch(fail));
      actions.appendChild(b);
    };

    if (s.status === "requested") {
      addBtn("Ablehnen", "danger", () => api.post(`/shares/${s.share_uid}/deny`));
    }
    if (s.status !== "revoked") {
      addBtn("Widerrufen", "danger", async () => {
        const ok = await confirmDialog({
          title: "Share widerrufen",
          body: "Der Notfallzugriff wird sofort ungültig. Fortfahren?",
          confirmLabel: "Widerrufen",
        });
        if (ok) await api.post(`/shares/${s.share_uid}/revoke`);
      });
    }
    addBtn("Entfernen", "ghost", async () => {
      const ok = await confirmDialog({
        title: "Share entfernen",
        body: "Share endgültig aus der Liste entfernen?",
        confirmLabel: "Entfernen",
      });
      if (ok) await api.del(`/shares/${s.share_uid}`);
    });

    li.append(info, actions);
    ul.appendChild(li);
  }
}

// ---- Notfallzugriff (Empfänger) -------------------------------------------
function updateSeedWordCount() {
  const raw = $("sa-seed").value.trim();
  const n = raw ? raw.split(/\s+/).filter(Boolean).length : 0;
  $("sa-wordcount").textContent = `${n} / 12 Wörter`;
}

async function requestShareAccess(ev) {
  ev.preventDefault();
  const store = $("sa-store").value.trim();
  const phrase = c.normalizeSeed($("sa-seed").value);
  if (phrase.split(" ").length !== 12) {
    status("Die Seed-Phrase muss aus 12 Wörtern bestehen.", "error");
    return;
  }
  const btn = $("bt-sa-submit");
  const box = $("sa-status");
  setBusy(btn, true, "Prüfen…");
  box.hidden = false;
  box.dataset.kind = "info";
  box.textContent = "Schlüssel werden geprüft…";
  try {
    const { shares } = await api.post("/share-access/kdf", { store });
    let granted = null,
      waiting = null,
      denied = null;
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
      box.dataset.kind = "warn";
      box.textContent = `Anfrage läuft. Freigabe am ${waiting.available_at} (UTC) – der Inhaber wurde benachrichtigt und kann ablehnen. Diese Seite später erneut aufrufen.`;
    } else if (denied) {
      box.dataset.kind = "error";
      box.textContent =
        denied.status === "denied"
          ? "Der Inhaber hat die Anfrage abgelehnt."
          : "Der Zugriff wurde widerrufen.";
    } else {
      box.dataset.kind = "error";
      box.textContent = "Kein passender Share gefunden – Store-ID und Seed-Phrase prüfen.";
    }
  } catch (err) {
    box.hidden = true;
    fail(err);
  } finally {
    setBusy(btn, false, "Zugriff anfordern");
  }
}

// ---- Initialisierung --------------------------------------------------------
const TOOLBAR_TITLES = {
  bold: "Fett",
  italic: "Kursiv",
  underline: "Unterstrichen",
  strike: "Durchgestrichen",
  blockquote: "Zitat",
  "code-block": "Code",
  link: "Link einfügen",
  clean: "Formatierung entfernen",
  "list-ordered": "Nummerierte Liste",
  "list-bullet": "Aufzählung",
  "list-check": "Checkliste",
};

function labelToolbarButtons(root) {
  if (!root) return;
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
    placeholder: "Notiz schreiben…",
  });
  labelToolbarButtons(document.querySelector(".ql-toolbar"));
  quill.on("text-change", (d, o, source) => {
    if (source === "user") setDirty(true);
  });
}

function wire() {
  $("bt-theme").addEventListener("click", toggleTheme);

  $("tab-login").addEventListener("click", () => setAuthMode(false));
  $("tab-register").addEventListener("click", () => setAuthMode(true));
  $("bt-pw-toggle").addEventListener("click", togglePasswordVisibility);

  $("form-login").addEventListener("submit", (ev) => {
    ev.preventDefault();
    doLogin();
  });
  $("link-shareaccess").addEventListener("click", (ev) => {
    ev.preventDefault();
    showView("view-shareaccess");
  });
  $("bt-sa-back").addEventListener("click", () => showView("view-login"));
  $("form-shareaccess").addEventListener("submit", (ev) => requestShareAccess(ev).catch(fail));
  $("sa-seed").addEventListener("input", updateSeedWordCount);

  $("bt-logout").addEventListener("click", async () => {
    try {
      await api.post("/auth/logout");
    } catch {
      /* Session ggf. abgelaufen */
    }
    lock("Abgemeldet.");
  });
  $("bt-save").addEventListener("click", () => saveEntry().catch(fail));
  $("bt-new").addEventListener("click", () => newEntry().catch(fail));
  $("bt-new-side").addEventListener("click", () => newEntry().catch(fail));
  $("bt-delete").addEventListener("click", () => deleteEntry().catch(fail));
  $("entry-title").addEventListener("input", () => setDirty(true));
  $("entry-search").addEventListener("input", renderEntryList);

  $("bt-drawer").addEventListener("click", toggleDrawer);
  $("sidebar-backdrop").addEventListener("click", closeDrawer);

  $("bt-shares").addEventListener("click", () => {
    regenSeed();
    renderShares().catch(fail);
    showView("view-shares");
  });
  const backFromShares = () => showView("view-main");
  $("bt-shares-back").addEventListener("click", backFromShares);
  $("bt-shares-close").addEventListener("click", backFromShares);
  $("bt-seed-new").addEventListener("click", regenSeed);
  $("bt-seed-copy").addEventListener("click", () => copySeed().catch(fail));
  $("bt-seed-print").addEventListener("click", printShareSheet);
  $("form-share-create").addEventListener("submit", (ev) => createShare(ev));

  $("dialog-cancel").addEventListener("click", () => closeDialog(false));
  $("dialog-confirm").addEventListener("click", () => closeDialog(true));
  $("dialog-backdrop").addEventListener("click", (ev) => {
    if (ev.target === $("dialog-backdrop")) closeDialog(false);
  });
  document.addEventListener("keydown", (ev) => {
    if (ev.key === "Escape") {
      if (!$("dialog-backdrop").hidden) {
        closeDialog(false);
      } else if (document.body.classList.contains("drawer-open")) {
        closeDrawer();
      }
    }
  });

  window.addEventListener("beforeunload", (ev) => {
    if (dirty) ev.preventDefault();
  });

  window.addEventListener("resize", () => {
    if (window.innerWidth > 820) closeDrawer();
  });
}

document.addEventListener("DOMContentLoaded", () => {
  initTheme();
  initQuill();
  wire();
  setAuthMode(false);
  updateSeedWordCount();
  // Kein Auto-Login: Content-Key nur im Speicher.
  lock("");
});
