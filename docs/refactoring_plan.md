# Refactoring Plan: Secure Info Store

> Verifikationsstand: 2026-07-21, gegen Commit `e1eec98` und die vorhandene `.data/data.sqlite3` geprueft. Alle Datei- und Zeilenangaben wurden gegen den Code bestaetigt; Korrekturen und Ergaenzungen aus dieser Pruefung sind eingearbeitet (siehe H-7 und "Funktionale Defekte").

## Entscheidungen und Umsetzungsstand (2026-07-21)

Auf Basis dieses Plans wurde der Neuaufbau auf dem Branch `refactor/secure-rewrite` umgesetzt. Getroffene Entscheidungen:

1. **Frischer Start statt Datenmigration.** Der Altbestand enthielt nur Testdaten; Legacy-Decoder und Re-Encrypt-Dialog (Teile von Phase 3) entfallen. Der Altcode wurde vollstaendig entfernt und ist ueber die Git-Historie verfuegbar.
2. **Split-Key-Authentifizierung statt OPAQUE.** Fuer PHP existiert keine etablierte, extern gepruefte OPAQUE-Serverbibliothek. Umgesetzt ist das Bitwarden-Modell: PBKDF2-SHA256 im Browser -> HKDF-Split in `auth`-Key (zum Server, dort Argon2id) und `enc`-Key (nur Browser-Speicher). Damit verlaesst ein KDF-abgeleiteter Auth-Wert den Browser - eine dokumentierte Abweichung von der strikten PAKE-Forderung dieses Plans, industrieerprobt und versioniert, sodass ein spaeterer PAKE-Umstieg moeglich bleibt.
3. **Browser-KDF ist PBKDF2-SHA256 (600k, versioniert) statt Argon2id.** Grund: Entscheidung fuer Vanilla-ES-Module ohne Build-/Wasm-Lieferkette; die Web Crypto API bietet kein Argon2. `kdf_version` ist gespeichert, ein Upgrade-Pfad bleibt offen.
4. **Sharing wurde direkt mit neu gebaut** (Phase 5 im Umfang): serverseitiger Zustandsautomat `active -> requested -> granted|denied`, Widerruf jederzeit, Serverzeit massgeblich, Key-Paket nur im Zustand `granted` gegen Seed-Nachweis (getrennte HKDF-Domaenen fuer Wrap und Auth), Audit-Events, Mail-Adapter (Datei-Transport als Default, `INFOSTORE_MAIL=native` fuer echten Versand).
5. **Frontend als Vanilla-ES-Module ohne Build-Schritt**; Quill lokal vendored, Inhalte als Quill-Delta (kein HTML-Sink), strikte CSP ohne externe Quellen.

Damit sind die Phasen 0 und 2-5 im Code umgesetzt (Phase 1 teilweise: Migrationen, Testsuite `php tests/run.php`, getrennte Konfiguration; bewusst ohne Composer/CI). **Offen bleiben:** Deployment-seitige Phase-0-Punkte (Pruefung, ob `sqladmin.php` je erreichbar war; Produktions-Webroot auf `public/` stellen; TLS/HSTS), Browser-E2E-Tests, CI sowie der externe Kryptografie-/Penetrationstest vor einem Sicherheitsversprechen (Phase 6).

## Zweck und Befund

Diese Analyse umfasst den gesamten eigenen PHP-, JavaScript-, HTML- und CSS-Code sowie die im Checkout vorhandenen, ignorierten Laufzeitartefakte. Die minifizierten Bibliotheken `js/ext/crypto-js.min.js` und `js/ext/jquery-min.js` wurden nur als Fremdcode identifiziert, nicht Zeile fuer Zeile auditiert.

Die Anwendung soll einen verschluesselten Notizspeicher mit Wiederherstellungs-/Sharing-Funktion bieten. Der aktuelle Stand erfuellt dieses Sicherheitsversprechen nicht: Der Server prueft keine Berechtigung fuer Datenzugriffe, Kryptografie und Datenformate sind nicht migrationsfaehig, und die Sharing-Funktion ist unvollstaendig. Deshalb darf das Ergebnis nicht als schrittweises kosmetisches Refactoring umgesetzt werden. Zuerst muss das System abgesichert und eine Zielarchitektur verbindlich entschieden werden.

## Ist-Architektur

### Komponenten und Ablauf

| Bereich | Ist-Zustand | Relevante Dateien |
| --- | --- | --- |
| Seiten | `index.php` liefert Login und bindet Bibliotheken ein. Nach Login wird `edit.php` per jQuery in die Seite geladen. | `index.php`, `edit.php` |
| Frontend | Unmodulares, globales JavaScript. `js/js.php` erzeugt eine einzige Script-Antwort aus allen Top-Level-Dateien in `js/`. | `js/js.php`, `js/main.js`, `js/entry.class.js`, `js/share.class.js` |
| API | `ajax.php?m=<name>` dekodiert JSON und inkludiert dynamisch `ajax/<name>.inc.php`. | `ajax.php`, `ajax/*.inc.php` |
| Persistenz | Handgeschriebene generische `dbobject`-Klasse auf PDO/SQLite; Datenklassen fuer `data`, `users`, `shares`. | `src/class_dbobject.php`, `src/class_data.php`, `src/class_user.php`, `src/class_share.php` |
| Datenbank | SQLite-Datei unter `.data/data.sqlite3`; das Schema entsteht beim ersten Request per `CREATE TABLE IF NOT EXISTS`. | `inc/config.inc.php` |
| Verschluesselung | Browser leitet einen Key aus Store-ID und Passwort her und verschluesselt Titel/Inhalt vor `save`. Der Server speichert Chiffretext und IV. | `js/se_crypt.class.js`, `js/entry.class.js` |
| Sharing | Ein aus einer 12-Wort-Phrase abgeleiteter Key verschluesselt den Store-Key. Der geplante Verzogerungsablauf ist im Client angelegt. | `js/share.class.js`, `ajax/createshare.inc.php`, `ajax/shareaccess1.inc.php` |

### Aktueller Datenfluss

1. Der Browser bildet `SHA-256(Store-ID)` als `userid` und leitet einen AES-Key aus Passwort und Store-ID ab.
2. Der Browser sendet einen verschluesselten Pruefwert und einen Passwort-Ableitungswert an `userlogin`.
3. Der Server erstellt bei unbekannter Store-ID automatisch einen `users`-Datensatz oder gibt den gespeicherten Pruefwert zurueck.
4. Der Browser legt Key, IV, Pruefwert und gehashte Store-ID dauerhaft in `localStorage` ab.
5. Beim Speichern verschluesselt der Browser Titel und Inhalt und sendet Chiffretext, IV, UUID und eine vom Client gelieferte `f_userid`.
6. Die API liest, aendert und loescht anhand dieser Request-Werte, ohne eine authentisierte Identitaet oder Besitzbeziehung zu pruefen.

### Datenmodell und Inkonsistenzen

Das Schema erzeugt die Tabellen `data`, `users` und `shares` in `inc/config.inc.php:24-55`. Es fehlen Unique Constraints, Foreign Keys, Check Constraints, Versionierung, Quoten und Migrationen.

- `data`: `userid`, `uid`, `iv`, `title`, `data`, Zeitstempel. Es gibt keinen eindeutigen Schluessel fuer `uid` oder `userid + uid`.
- `users`: `userid`, `pwhash`, `verification`. `userid` ist nicht eindeutig.
- `shares`: `uid`, `storeid`, `key`, `mail`, `delay`, `iv`, `status`, Zeitstempel. Der Code schreibt zusaetzlich `ts_status` (`ajax/createshare.inc.php:10`). Das Schema in `inc/config.inc.php` erzeugt diese Spalte nicht; in der vorhandenen `.data/data.sqlite3` wurde sie manuell nachgezogen (`, 'ts_status' TEXT` am Tabellenende). Auf jeder frischen Installation wirft `dbobject::save()` deshalb `Attribute 'ts_status' not in fieldlist` und das Speichern schlaegt fehl. Code-Schema und Laufzeitschema sind bereits auseinandergelaufen — der direkte Beleg fuer das fehlende Migrationskonzept.
- `initDB()` fuehrt keine Migrationen aus. Neue Spalten im PHP-Schema erscheinen nicht in bestehenden Datenbanken.

## Sicherheitsbewertung

### Kritisch

#### K-1: Keine serverseitige Autorisierung von Datenzugriffen

`ajax.php` startet keine Session und verifiziert keinen Token. `getlist` vertraut der vom Client gelieferten `f_userid` (`ajax/getlist.inc.php:4`), `save` laedt nur nach UUID (`ajax/save.inc.php:4`) und `delete` loescht nur nach UUID (`ajax/delete.inc.php:4`). Damit kann jeder HTTP-Client Eintraege eines bekannten oder erratenen Stores lesen, ersetzen, anlegen oder loeschen. Verschluesselung begrenzt die Klartextoffenlegung, verhindert aber weder Manipulation noch Loeschung oder Metadatenabfluss.

**Folge:** Integritaet und Verfuegbarkeit aller Stores sind kompromittiert. Dies ist ein Produktionsstopp-Kriterium.

#### K-2: Datenbank-Adminwerkzeug im potenziellen Webroot

`.data/sqladmin.php` ist phpLiteAdmin 1.9.7.1 von 2016 und enthaelt das Klartextpasswort `guinness` (`.data/sqladmin.php:48-55`). `.gitignore` verhindert keinen HTTP-Zugriff. Wenn der Webserver versteckte Verzeichnisse ausliefert oder `.data` anders erreichbar ist, erhaelt ein Angreifer komplette SQLite-Administration inklusive beliebiger SQL-Abfragen.

**Folge:** Vollstaendige Datenbankmanipulation und -exfiltration. Das Artefakt muss vor jeder weiteren Bereitstellung aus dem Webroot entfernt werden.

#### K-3: Persistierte Entschluesselungskeys und unkontrollierte Fremdskripte

`js/main.js:27-34` schreibt den AES-Key in `localStorage` und liest ihn beim Seitenstart automatisch wieder (`:77-87`). JavaScript im gleichen Origin kann diesen Key auslesen. Gleichzeitig werden Quill und jQuery von CDNs geladen; Quill ohne Subresource Integrity (`index.php:12-16`). Ein XSS, kompromittiertes CDN, boesartige Browser-Erweiterung oder sonstiges Origin-Skript kann Klartext und Keys entwenden.

**Folge:** Der behauptete Schutz der Notizen faellt bei Script-Kompromittierung vollstaendig weg.

### Hoch

#### H-1: Passwortpruefung verwendet effektiv nur drei Zeichen

Der Server-Loginwert wird aus den ersten zwei und dem letzten Zeichen des Passworts gebildet (`js/main.js:36-43`). Alle dazwischenliegenden Zeichen werden ignoriert. Der Server speichert zwar einen `password_hash`, aber nur von dieser schwaechen Ableitung (`src/class_user.php:17-38`). Es gibt weder Rate Limit noch Sperre; Antworten unterscheiden unbekannte von existierenden Stores.

**Folge:** Die effektive Passwortentropie fuer die Anmeldung ist extrem gering, Stores sind aufzaehlbar und brute-force-bar.

#### H-2: KDF hat nur 90 statt `2^88` Iterationen

`static iterations = 2 ^ 88` in `js/se_crypt.class.js:3` ist in JavaScript bitweises XOR und ergibt `90`, nicht Potenzierung. Der PBKDF2-Key wird mit 90 Iterationen und der Store-ID als vorhersehbarem Salt abgeleitet (`:13-20`).

**Folge:** Offline-Passwortangriffe auf bekannte Chiffretexte und Pruefwerte sind guenstig.

#### H-3: Verschluesselung hat keinen Integritaetsschutz

CryptoJS AES mit Key/IV verwendet hier CBC mit Padding; es werden nur Chiffretext und IV gespeichert (`js/se_crypt.class.js:23-35, 106-113`). Es gibt keinen Authentifizierungstag oder MAC. Angreifer koennen Chiffretexte unbemerkt ersetzen oder veraendern.

**Folge:** Selbst nach Behebung von K-1 ist die Integritaet eines verschluesselten Eintrags nicht nachweisbar.

#### H-4: Dynamisches Include ist ein Local-File-Include-Risiko

`ajax.php:11-17` baut den Include-Pfad direkt aus `$_GET['m']`. Das angehaengte `.inc.php` reduziert das Risiko, erlaubt aber weiterhin Traversal zu passenden PHP-Include-Dateien ausserhalb von `ajax/`.

**Folge:** Interne oder kuenftige Handler koennen unbeabsichtigt als Endpoint erreichbar werden; bei beschreibbarem Pfad entsteht Code-Execution-Risiko.

#### H-5: Stored-XSS-Senken gefaehrden alle entschluesselten Daten

Entschluesselter Editorinhalt wird mit `quill.clipboard.dangerouslyPasteHTML()` eingefuegt (`js/entry.class.js:30`). Notizen werden aus `innerHTML` gespeichert (`:40-46`). Statusmeldungen werden mit `.html()` statt `.text()` geschrieben (`js/main.js:131`). Ein eingeschleustes Script kann wegen K-3 alle Keys auslesen.

#### H-6: Sharing ist fachlich und technisch nicht sicher

Die Share-Erstellung scheitert auf frischen Installationen am fehlenden Feld `ts_status` (siehe Datenmodell). `shareaccess1` ist eine identische Kopie von `sharerequestlogin`: es liest nur Shares, aendert keinen Status und erzwingt weder Wartezeit noch Ablehnung (`ajax/shareaccess1.inc.php`). Der Client sendet den neuen Status zudem als Objekt `{msg, iv}` statt als String (`js/share.class.js:114-121`), trifft alle Status- und Zeitentscheidungen selbst, enthaelt TODOs und den Tippfehler `stautus` (`js/share.class.js:112-130`). `sharerequestlogin` liefert ausserdem jedem Anfrager mit bekanntem Store-ID-Hash alle verschluesselten Share-Key-Pakete aus (`ajax/sharerequestlogin.inc.php:3`) — zusammen mit der schwachen KDF aus H-2 sind Offline-Angriffe auf Seed-Phrasen moeglich.

**Folge:** Die UI verspricht einen kontrollierbaren verzogerten Notfallzugriff, den das System nicht implementiert oder durchsetzt.

#### H-7: IV-Wiederverwendung und nicht kryptografische IV-Erzeugung

Beim Speichern eines Eintrags werden Titel und Inhalt mit demselben Key und demselben IV verschluesselt (`js/entry.class.js:58-60`). Beim Anlegen eines Shares teilen sich Key-Paket, Mail, Delay, Status und `ts_status` ebenfalls einen einzigen IV (`js/share.class.js:46-51`). AES-CBC mit wiederverwendetem Key/IV-Paar offenbart identische Klartext-Praefixe verschiedener Felder und Datensaetze. Zusaetzlich wird der IV nicht aus einem CSPRNG erzeugt: `getIV()` leitet ihn per PBKDF2 aus `randomString(8)` ab, das `Math.random()` verwendet (`js/se_crypt.class.js:5-12, 116-124`) — die IVs sind damit potenziell vorhersagbar.

**Folge:** Vertraulichkeitsverlust ueber Muster in Chiffretexten und geschwaechte CBC-Sicherheit; die Zielarchitektur (frischer Zufalls-Nonce je Verschluesselung aus `crypto.getRandomValues`) behebt dies fuer neue Daten, Legacy-Daten bleiben betroffen.

### Mittel und niedrig

| Thema | Beleg | Auswirkung |
| --- | --- | --- |
| Keine Eingabegrenzen, Pagination oder Quoten | `ajax.php:14`, `ajax/save.inc.php`, `ajax/getlist.inc.php` | Speicher-/Datenbank-DoS, unbeschraenkte Antworten |
| Fehleranzeige aktiv | `inc/config.inc.php:2-5` | Pfad-, Schema- und Stack-Leaks; JSON wird durch Warnings kaputt |
| Unsichere generische SQL-Erzeugung | `src/class_dbobject.php:149-154, 200-244, 391-456, 536-547` | Spaetere Call-Sites koennen SQL Injection einfuehren; Identifier und Limits werden interpoliert |
| Kein HTTP-Sicherheitsprofil | `index.php`, `ajax.php` | Fehlende CSP, Clickjacking-/Cache-/MIME-/Referrer-Absicherung |
| Keine DB-Integritaetsregeln | `inc/config.inc.php:24-55` | Doppelte Nutzer/UUIDs, Race Conditions, unklare Updates |
| Default-Zugangsdaten im Formular | `index.php:26, 41-52` | Test-Store/Seed kann versehentlich produktiv verwendet werden |
| Seed-Format nicht kanonisch | `js/share.class.js:36-43, 69-78` | Schwache/mehrdeutige Wiederherstellungsphrasen; Index kann ausserhalb der Wortliste liegen |
| API-Fehler nur im JSON-Feld | `ajax.php:23-24` | HTTP-Clients, Caches und Monitoring erkennen Fehlertypen nicht verlaesslich |
| Unbenutzter oeffentlicher Endpoint | `ajax/userlogin.php` | Direkt aufrufbar, laedt aber keine Konfiguration: Fatal Error mit Fehlerausgabe (Pfad-Leak bei `display_errors=on`); unerwartetes `echo "hier"` |
| Produktionspfade und -URL im Repository | `inc/config.inc.php:5-6` | `ROOTPATH`/`ROOTHTTP` hart kodiert auf das Produktionsdeployment; lokale Entwicklung erfordert manuelles Umbiegen (siehe `AGENTS.md`), Gefahr versehentlicher Prod-Zugriffe |
| jQuery doppelt vorhanden | `index.php:15`, `js/ext/jquery-min.js` | CDN-Version wird geladen, die lokale Kopie liegt ungenutzt im Repo; unklare Versionshoheit |
| Accessibility und Mobilgeraete | `index.php:6`, `css/main.css`, `edit.php` | Zoom ist deaktiviert, Statusmeldungen nicht als Live-Region, keine Lade-/Fehler-/Ungespeichert-Zustaende |

## Funktionale Defekte

Diese Fehler sind keine eigenstaendigen Sicherheitsluecken, belegen aber den ungetesteten Zustand des Codes. Sie werden nicht einzeln gepatcht, sondern muessen beim Neuaufbau der jeweiligen Komponente (Phasen 2, 3 und 5) nachweislich entfallen; die Testfaelle dazu gehoeren in die jeweilige Phase.

- **Share-Lookup per UID findet nie einen Datensatz:** `src/class_share.php:20` liest `$ret["id"]` statt `$ret[0]["id"]`. Der Konstruktor initialisiert daher immer mit der urspruenglichen UID, `initObject` findet nichts, und jedes `createshare` legt einen neuen Datensatz an — ein Share kann nie aktualisiert werden, Duplikate entstehen.
- **Undefinierte Variable in der Share-Antwort:** `ajax/createshare.inc.php:17` gibt `$ajaxRet["data"] = $list;` zurueck; `$list` existiert dort nicht (Notice, `data` ist immer `null`).
- **Toter RSA-Code mit fehlender Bibliothek:** `js/se_crypt.class.js:44-104` (`asyncCrypt`/`asyncDecrypt`) referenziert `forge`, das nirgends geladen wird. Jeder Aufruf wirft einen `ReferenceError`. Entfernen statt mitschleppen.
- **Latenter Schluessellade-Fehler:** `src/class_dbobject.php:49-53` prueft `ROOTPATH . "/inc/c.key"`, liest dann aber `file_get_contents($C["CEK"])` — `$C["CEK"]` ist nirgends definiert. Sobald eine `c.key` existiert, bricht jede Objektinstanziierung.
- **E-Mail-Branch auf nicht vorhandener Spalte:** `src/class_dbobject.php:90-96` schaltet bei einem `@` im Schluesselwert auf `WHERE email = :id` um; keine der drei Tabellen hat eine `email`-Spalte. IDs mit `@` erzeugen einen SQL-Fehler.
- **Dead Code im Script-Aggregator:** `js/js.php:35` referenziert eine nicht vorhandene `scrambled.txt` hinter dem toten Flag `$do`.
- **Globales `alert()` wird ueberschrieben:** `js/main.js:122` ersetzt `window.alert` durch die Statusmeldungsfunktion, waehrend `confirm()` nativ bleibt (`edit.php:126`) — fehleranfaellige Vermischung, im Neuaufbau durch ein explizites Benachrichtigungsmodul ersetzen.

## Zielarchitektur

### Verbindliches Sicherheitsmodell

Die Inhaltsverschluesselung ist zwingend clientseitig und passwortbasiert. Der Entschluesselungsschluessel wird ausschliesslich im Browser aus dem vollstaendigen Nutzerpasswort, einem zufaelligen Store-Salt und versionierten KDF-Parametern abgeleitet. Das Passwort, der abgeleitete Inhalts-/Entschluesselungsschluessel und unverschluesselter Notizinhalt duerfen den Browser weder ueber Netzwerk, Speicher noch Logs verlassen.

Der Server speichert nur Ciphertexte, Nonces, Authentifizierungstags sowie oeffentliche KDF-Metadaten und kann Notizinhalte nicht entschluesseln. Er besitzt und speichert keinen nutzerbezogenen Inhalts- oder Entschluesselungsschluessel. Die notwendige Serverauthentifizierung darf den Inhaltskey weder wiederverwenden noch daraus ableiten.

Die Anmeldung muss daher mit einem etablierten, extern geprueften PAKE-Protokoll, beispielsweise OPAQUE, erfolgen. Der Server speichert dabei nur den PAKE-Verifier und stellt nach erfolgreichem Protokoll eine Session aus. Eine eigene Challenge-Response-Logik, ein uebertragenes Passwort, ein serverseitiger Passwort-Hash oder ein als Login-Secret wiederverwendeter Inhaltskey sind kein akzeptabler Ersatz. Vor Beginn der Implementierung sind Protokoll, Bibliothek, Browser-/PHP-Kompatibilitaet und ein externer Kryptografie-Review verbindlich festzulegen.

### Zielbausteine

- **Webserver und Secrets:** Datenbank, Backups, Logs und Administration ausserhalb des Document Root. Konfiguration aus Umgebungsvariablen/Secret Store; keine produktiven Pfade, Passwoerter oder Debug-Flags im Repository.
- **Backend:** Composer-autoloadbares PHP mit klaren Schichten: HTTP-Routing, Request-Validierung, Authentifizierung/Autorisierung, Services, Repositories, Migrationen. Keine generische SQL-String-API fuer Anwendungscode.
- **Authentifizierung:** PAKE mit dem vollstaendigen Passwort und getrenntem PAKE-Verifier; Passwort und Inhaltskey bleiben im Browser. Nach erfolgreichem PAKE wird eine PHP-Session-ID mit `Secure`, `HttpOnly`, `SameSite=Lax`, Session-Rotation, CSRF-Schutz, Rate Limits und Audit-Log verwendet.
- **Autorisierung:** Store-ID ausschliesslich aus der serverseitigen Session. Jede Datenabfrage scoped mit `store_id`; Updates/Deletes mit `WHERE entry_id = :id AND store_id = :storeId` und einer betroffenen Zeile als Erfolgskriterium.
- **Verschluesselung:** Web Crypto API statt CryptoJS fuer neue Daten; AEAD (AES-GCM oder ChaCha20-Poly1305), zufaelliger eindeutiger Nonce je Verschluesselung, Authenticated Data mindestens aus Schema-Version, Store-ID und Entry-ID. Der Browser leitet den Inhaltskey direkt aus dem vollstaendigen Passwort via Argon2id oder scrypt mit zufaelligem, pro Store gespeichertem Salt und parametrisierter Version ab. Der Key bleibt nur im Speicher; der Server erhaelt ausschliesslich Salt, Parameter und verschluesselte Nutzdaten.
- **Datenmodell:** Versionierte Migrationen, Unique Constraints, Foreign Keys, Checks und Indizes. SQLite kann bleiben, solange Einzelinstanz, Backup/Locking und erwartete Last dazu passen; bei Multi-Instanz oder hohem Parallelzugriff PostgreSQL evaluieren.
- **Frontend:** Modulbasiertes JavaScript/TypeScript, keine Globals und keine serverseitige Script-Aggregation. Datenzugriff hinter API-Client, UI-Komponenten mit klaren Lade-, Fehler-, Offline- und Speicherzustaenden.
- **Sharing:** Bis zur vollstaendigen serverseitigen Implementierung deaktiviert. Danach ein expliziter zustandsbehafteter Workflow mit serverseitiger Zeit, Benachrichtigungen, Widerruf, Audit-Trail und Tests.

### Migrationsprinzipien

- Bestehende Ciphertexte, IVs, Store-ID-Hashes und Sharing-Daten duerfen nie stillschweigend umgedeutet werden.
- Jede neue verschluesselte Nutzlast erhaelt ein explizites `crypto_version`-Feld und dokumentiertes Serialisierungsformat.
- Alte Daten bleiben mit dem Legacy-Decoder lesbar, bis sie nach erfolgreicher Entschluesselung und expliziter Zustimmung im Browser neu verschluesselt wurden.
- Vor jeder Migration: verschluesseltes Datenbankbackup, Wiederherstellungstest, Migrationsprotokoll und Rollback-Plan.
- Die aktuelle KDF ist kryptografisch nicht tragfaehig. Das Aendern ohne Legacy-Pfad sperrt jedoch bestehende Nutzer aus. Der sichere Weg ist ein versionierter Client-Migrationsdialog nach erfolgreichem Legacy-Unlock.

## Umsetzungsplan

### Phase 0: Sofortmassnahmen und Incident-Abklaerung

- [ ] Produktion bis zur Behebung von K-1 fuer externe Nutzer sperren oder mindestens alle schreibenden Endpoints blockieren.
- [ ] `.data/sqladmin.php` aus allen Deployments und Backups entfernen; pruefen, ob sie per HTTP erreichbar war. Bei moeglicher Erreichbarkeit Datenbank als kompromittiert behandeln, Zugriffe auswerten und Store-Nutzer informieren.
- [ ] `.data/`, SQLite-Dateien, Backups und Adminwerkzeuge ausserhalb des Webroot verschieben; im Webserver zusaetzlich den Zugriff darauf explizit verbieten.
- [ ] `display_errors=Off` in Produktion setzen; Fehler in geschuetztes Logging schreiben. Einen einheitlichen JSON-Fehlerhandler einrichten.
- [ ] Default-Store-ID und Default-Seed aus `index.php` entfernen.
- [ ] Den ungenutzten Endpoint `ajax/userlogin.php` entfernen oder dauerhaft ausliefern verhindern.
- [ ] Sharing in der UI ausblenden und seine Endpoints deaktivieren, bis Phase 5 abgeschlossen ist.
- [ ] CSP im Report-only-Modus, TLS/HSTS und Serverzugriffslogs einrichten, um Abhaengigkeiten und potentielle Script-Verstoesse sichtbar zu machen.

**Abnahmekriterien:** Kein Datenbank-/Admin-Pfad ist vom Webserver erreichbar; Fehler enthalten keine Pfade; Share-UI ist nicht erreichbar; externe Requests koennen keine Daten mutieren.

### Phase 1: Fundament, Tooling und Datenbankmigrationen

- [ ] `composer.json` einfuehren und PHP-Version, Autoloading, Entwicklungsabhaengigkeiten und Sicherheitsupdates festlegen.
- [ ] Testbasis schaffen: PHPUnit fuer Services/Repositories, Integrationsdatenbank pro Testlauf und Browser-E2E-Tests fuer Login/Notizen.
- [ ] Statische Analyse und Stilpruefung einrichten, z. B. PHPStan und PHP-CS-Fixer; JavaScript mit ESLint/Prettier oder eine bewusst dokumentierte Alternative.
- [ ] CI fuer `composer validate`, Dependency-Audit, Lint, statische Analyse, Unit-, Integrations- und E2E-Tests erstellen.
- [ ] Migrationswerkzeug einrichten; Schemaerzeugung aus `inc/config.inc.php` herausloesen. Eine Migration darf nur einmal laufen und muss in `schema_migrations` protokolliert werden.
- [ ] Neue Tabellen und Constraints vorbereiten: eindeutige Store-Kennung, `entries(store_id, entry_id)` eindeutig, Foreign Key auf Store, Indizes fuer Listenreihenfolge, Laengen- und Zustands-Checks.
- [ ] Sichere lokale Konfiguration (`.env.example` ohne Geheimnisse) und getrennte Test-/Entwicklungs-/Produktionskonfiguration erstellen; die hart kodierten `ROOTPATH`/`ROOTHTTP` aus `inc/config.inc.php:5-6` dabei abloesen.
- [ ] Die manuell nachgezogene `ts_status`-Spalte der Bestandsdatenbank in der ersten Migration kanonisieren, damit Code-Schema und Laufzeitschema wieder identisch sind.

**Abnahmekriterien:** Eine leere Datenbank laesst sich reproduzierbar migrieren; ein vorhandener Datenbankdump laesst sich in einer Kopie migrieren; alle Tests laufen in CI ohne Produktionspfade.

### Phase 2: API, Session und Autorisierung

- [ ] Den Dispatcher durch eine feste Routing-Tabelle ersetzen; unbekannte Methoden mit HTTP 404 ablehnen. Keine Request-Werte fuer Include-Pfade verwenden.
- [ ] Request-Parser erstellen: nur `application/json`, Body-Groessenlimit, strukturierte Validierungsfehler, erlaubte Felder, Typen, UUIDs, Laengen und Base64-Formate validieren.
- [ ] Login und Registrierung als getrennte Ablaeufe gestalten; keine automatische Store-Erstellung bei einem Loginversuch. Existenzfehler generisch antworten.
- [ ] Den Drei-Zeichen-Hash und die serverseitige Passwortpruefung vollstaendig entfernen. Eine extern gepruefte PAKE-Bibliothek auswaehlen, ihren Verifier serverseitig speichern und eine Session erst nach erfolgreichem PAKE ausstellen. Passwort und Inhaltskey duerfen nicht an den Server gesendet werden.
- [ ] Rate Limiting pro IP und Store, verzogerte Antworten, Fehlversuchsprotokoll und optionaler Missbrauchsschutz implementieren.
- [ ] PHP-Sessions sicher konfigurieren: zufaellige IDs, Rotation nach Login, Ablauf, Logout-Invalidierung, `Secure`, `HttpOnly`, `SameSite=Lax`.
- [ ] CSRF-Token fuer alle zustandsaendernden Requests einrichten, sobald Cookie-basierte Sessions verwendet werden.
- [ ] Alle Repository-Methoden mit prepared statements implementieren. Feldnamen und Sortierungen nur aus internen Allowlists beziehen; Werte, Limits und Offsets parametrisieren bzw. casten.
- [ ] `listEntries`, `createEntry`, `updateEntry`, `deleteEntry` als explizite Use-Cases bauen. Store-ID ausschliesslich aus der Session ermitteln und jede Mutation atomar auf Eigentum pruefen.
- [ ] Richtige HTTP-Codes und ein stabiles Fehlerformat liefern, z. B. `{ "error": { "code", "message", "requestId" } }`; interne Details nur loggen.
- [ ] Paginierung, maximale Payload-/Entry-Groesse, Store-Quoten und Request-Limits implementieren.

**Abnahmekriterien:** Ein eingeloggter Store kann niemals einen fremden Entry lesen, aendern oder loeschen; direkte Requests mit manipuliertem `store_id` scheitern; CSRF-, Rate-Limit- und Validierungstests sind automatisiert.

### Phase 3: Kryptografie und Datenmigration

- [ ] Ein schriftliches Kryptografieformat definieren: Version, Algorithmus, KDF, Salt, KDF-Parameter, Nonce, Ciphertext, Tag und Associated Data. Dieses Format testbar serialisieren und eindeutig vom PAKE-Protokoll trennen.
- [ ] Fuer neue Stores Web Crypto API mit AEAD einsetzen. Der Browser erzeugt pro Store einen zufaelligen Salt, leitet den Inhaltskey allein aus dem vollstaendigen Passwort ab und speichert nur Salt und KDF-Parameter gemeinsam mit der Verschluesselungsversion auf dem Server.
- [ ] Saemtliche IVs/Nonces ausschliesslich mit `crypto.getRandomValues` erzeugen und pro Verschluesselungsvorgang genau einmal verwenden — nie ueber mehrere Felder oder Datensaetze teilen (behebt H-7; `Math.random`-basierte Helfer wie `randomString`/`generateRandomWord` fuer Kryptozwecke entfernen).
- [ ] Ein ungeprueftes eigenes Kryptoprotokoll nicht durch ein neues eigenes Protokoll ersetzen. Bei Argon2id/scrypt Browser-Kompatibilitaet, Wasm-Lieferkette und externe Review vorab klaeren.
- [ ] Den Key nicht in `localStorage`, `sessionStorage`, IndexedDB oder URL speichern. Er bleibt nur fuer die aktive Browser-Sitzung im Speicher und wird bei Logout, Tab-Schliessen und Inaktivitaet verworfen.
- [ ] PAKE-Verifikation und Datenverschluesselung strikt trennen. Der Inhaltskey, Zwischenwerte seiner KDF und Klartext verlassen nie den Browser; der Server verwaltet nur PAKE-Verifier, Session und Ciphertext-Metadaten.
- [ ] Legacy-Decoder nur lesend implementieren. Nach erfolgreichem Unlock eine explizite Migration pro Entry anbieten, die AEAD-Version speichert und erst dann Legacy-Daten ersetzt.
- [ ] Tag-Pruefungsfehler als Manipulationsereignis behandeln: Eintrag nicht rendern, sichere Fehlermeldung anzeigen und Details loggen.
- [ ] Krypto-Testvektoren, Round-Trip-Tests, falscher-Key-/falscher-AAD-/manipulierter-Ciphertext-Tests und Legacy-Migrationstests hinzufuegen.
- [ ] Vor Produktivsetzung externen Kryptografie- und Penetrationstest beauftragen.

**Abnahmekriterien:** Manipulierte Daten werden sicher erkannt; kein Key ist persistent im Browser abgelegt; Passwort, Inhaltskey und Klartext werden in keinem Request, Server-Log oder Datenbankfeld uebertragen bzw. gespeichert; alte Daten bleiben nach verifizierter Migration lesbar; neue Daten nutzen nur das versionierte AEAD-Format.

### Phase 4: Frontend, XSS-Schutz und Benutzbarkeit

- [ ] `js/js.php` und globale Variablen durch modulare Dateien und einen expliziten Build-/Asset-Schritt ersetzen. Fremdbibliotheken lokal versionieren oder mit SRI und CSP ausliefern; die ungenutzte jQuery-Doppelkopie und den toten forge-RSA-Code entfernen.
- [ ] Inline-Handler und Inline-Skripte aus `index.php`/`edit.php` entfernen, um eine restriktive CSP ohne `unsafe-inline` zu ermoeglichen.
- [ ] Quill-Inhalte als Delta oder anderes strukturiertes Format speichern. Beim Rendern eine eng konfigurierte Sanitization-Policy verwenden; `dangerouslyPasteHTML` und generisches `innerHTML` entfernen.
- [ ] Statusmeldungen ueber Textknoten (`.text()`/`textContent`) setzen und als `aria-live` auszeichnen.
- [ ] API-Client zentralisieren: Loading-, Retry-, Netzwerk- und Authentifizierungsfehler konsistent darstellen. Keine versteckten Fehler in der Konsole.
- [ ] Editor-UX verbessern: sichtbarer Gespeichert-/Ungespeichert-Status, Debounce/Autosave mit Konflikterkennung, explizite Loeschbestaetigung mit Entry-Titel, Undo fuer frische Loeschungen, leere Zustande und Such-/Sortierfunktion.
- [ ] Login-UX verbessern: leere Default-Felder, klare Trennung von Anmelden und Store anlegen, Passwortmanager-kompatible `autocomplete`-Attribute, sichtbare Sicherheits- und Recovery-Hinweise.
- [ ] Accessibility und Mobile pruefen: Zoom nicht sperren, echte Labels, Fokusmanagement nach Dialogen/Navigation, Tastaturbedienung, ausreichende Kontraste, semantische Buttons, Touch-Ziele und responsive Editorhoehe.
- [ ] Sensible Klartextdaten nicht in Console, URL, DOM-Attributen oder Fehlermeldungen protokollieren.

**Abnahmekriterien:** CSP kann im Enforce-Modus ohne `unsafe-inline` laufen; gespeicherte Notizen fuehren keinen Script-Code aus; Kernablaeufe sind per Tastatur und Mobilgeraet nutzbar; der Zustand einer Speicherung ist eindeutig.

### Phase 5: Sharing als separates Sicherheitsfeature

- [ ] Produktanforderungen praezisieren: Wer darf einen Share erstellen, anfordern, abbrechen, ablehnen, widerrufen und vollstaendig entfernen? Was geschieht bei Ablauf, Passwortwechsel, Verlust des Seeds und mehreren Empfaengern?
- [ ] Share-Datenmodell mit Migrationen bauen: Share-ID, Owner-Store, verschluesseltes Key-Paket, Empfaenger-Metadaten, serverseitiger Status, `requested_at`, `available_at`, `revoked_at`, `decided_at`, Version und Audit-Events. Die bekannten Defekte des Altcodes (`class_share`-UID-Lookup, `createshare`-Antwort, Objekt-statt-String-Status) entfallen mit dem Neuaufbau; Regressionstests dafuer anlegen.
- [ ] Share-Key-Pakete nicht mehr an jeden Anfrager mit Store-ID-Hash ausliefern: Herausgabe erst nach serverseitig gueltigem Status/Zeitfenster oder mit Proof-of-Knowledge, damit keine Offline-Angriffe auf Seeds moeglich sind.
- [ ] Zustandsautomat serverseitig implementieren und Transitions erlauben: `active -> requested -> granted|denied|revoked|expired`. Serverzeit ist allein massgeblich; der Client liefert nie Status oder Zeitstempel als Autoritaet.
- [ ] Owner- und Empfaenger-Berechtigungen fuer jede Transition definieren und testen. Widerruf muss auch nach einer Anfrage greifen.
- [ ] Benachrichtigungen ueber einen getrennten, getesteten Mail-Adapter mit Queue/Retry gestalten. Keine Mailadressen oder Tokens in Klartext-Logs.
- [ ] Seed als kanonische 12- oder 24-Wort-Phrase mit Wortlistenmitgliedschaft, Worttrennern, Normalisierung und kryptografisch korrekter Indexerzeugung verarbeiten. Eigenen Seed nur nach deutlicher Schwachstellenwarnung zulassen oder nicht anbieten.
- [ ] Share-Key-Pakete mit dem neuen AEAD-Format und eigener `crypto_version` verschluesseln; Widerrufs- und Neuausstellungsstrategie definieren.
- [ ] Erst nach Threat-Model-Review, End-to-End- und manuellen Abuse-Tests in der UI freischalten.

**Abnahmekriterien:** Jede Transition ist serverseitig autorisiert und auditierbar; Zeitverzug und Widerruf lassen sich nicht durch direkte API-Requests umgehen; Share-Erstellung und Notfallzugriff sind durch Tests und Review abgesichert.

### Phase 6: Betrieb und fortlaufende Qualitaet

- [ ] Webserver-Header zentral setzen: HSTS, CSP, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `frame-ancestors 'none'` oder X-Frame-Options sowie `Cache-Control: no-store` fuer sensible Seiten/API-Antworten.
- [ ] TLS, sichere Cookie-Domain, vertrauenswuerdige Proxies und Upload-/Request-Limits in der Deployment-Konfiguration testen.
- [ ] Verschluesselte Backups, Restore-Uebungen, Aufbewahrungsfristen, Zugriffskontrolle und Alarmierung fuer fehlgeschlagene Logins, Rate-Limit-Ereignisse und Entschluesselungsfehler einrichten.
- [ ] Abhaengigkeiten regelmaessig aktualisieren und auf bekannte Schwachstellen pruefen. Veraltete oder doppelt geladene Bibliotheken entfernen.
- [ ] Datenschutz-/Loeschkonzept, Incident-Response-Prozess und verantwortliche Stelle fuer Security-Updates dokumentieren.
- [ ] Vor Release Threat Modeling, Code Review, Dependency Review, OWASP-ASVS-orientierte Pruefung und externen Penetrationstest durchfuehren.

## Empfohlene Reihenfolge und Freigaben

1. Phase 0 sofort abschliessen und Deployment pruefen.
2. Phase 1 und 2 als minimale sichere Datenzugriffsbasis abschliessen, bevor Notizfunktionen wieder extern erreichbar sind.
3. Phase 3 vor einem Sicherheitsversprechen zu Ende-zu-Ende-Verschluesselung abschliessen; bestehende Daten erst nach Backup und Migrationsprototyp migrieren.
4. Phase 4 parallel zu den API-Tests umsetzen, da CSP und XSS-Schutz direkt den Schutz der Client-Keys bestimmen.
5. Phase 5 als separates Release behandeln; Sharing nicht nebenbei an die bestehende Notiz-API anfuegen.
6. Phase 6 ist dauerhafte Betriebsarbeit und Release-Voraussetzung.

## Tests, die vor dem ersten sicheren Release zwingend sind

- Autorisierung: Fremde Store-ID/Entry-ID in jedem Lese-, Schreib- und Loesch-Endpunkt; parallele Requests und direktes API-Fuzzing.
- Session/CSRF: Login-Fixation, Logout-Invalidierung, Cookie-Flags, abgelaufene Session, fehlender/falscher CSRF-Token und Rate-Limit.
- Eingaben: fehlende/zusaetzliche Felder, falsche Content-Type, sehr grosse Bodies, ungueltiges Base64, lange Titel/Deltas, negative und uebergrosse Pagination.
- Kryptografie: feste Testvektoren, falsches Passwort, falscher Salt, falscher AAD, Nonce-Wiederholungsschutz, veraenderter Ciphertext/Tag und Legacy-Migration.
- XSS: boesartige Quill-Delta-/HTML-Inhalte, serverseitige Fehlermeldungen und CSP-Verstossreporting. Tests muessen bestaetigen, dass gespeicherte Inhalte keinen Script-Code ausfuehren und kein Key-Material persistent ist.
- Datenbank: frische Installation, Upgrade einer Kopie der aktuellen Datenbank, Duplicate-UUID/Store, Foreign-Key-Fehler, Rollback und Backup-Restore.
- Sharing: jeder Statusuebergang, Ablauf, Ablehnung, Widerruf, Wiederanfrage, mehrere Empfaenger und direkte API-Umgehungsversuche.
- UX: Desktop und Mobile, Tastatur, Screenreader-Grundfluss, langsames/offline Netzwerk, Konflikt beim parallelen Bearbeiten und Key-Verlust nach Inaktivitaet.

## Nicht akzeptable Abkuerzungen

- Keine Autorisierung nur im JavaScript und kein Vertrauen in `localStorage.userId`.
- Keine neue selbst entwickelte Passwort- oder Challenge-Response-Kryptografie.
- Kein Passwort, Inhaltskey oder KDF-Zwischenwert darf an den Server gesendet oder dort gespeichert werden; ein PAKE-Verifier ist kein Ersatz fuer diese Trennung.
- Den Inhaltskey niemals als Login-Secret, Session-Token oder Eingabe fuer die Serverauthentifizierung wiederverwenden.
- Keine KDF-/AES-Aenderung ohne Versionierung und lesbaren Legacy-Pfad.
- Kein Weiterbetrieb von Datenbankdatei oder Adminwerkzeug innerhalb eines oeffentlich erreichbaren Pfads.
- Kein CSP mit dauerhaftem `unsafe-inline`, um vorhandene Inline-Skripte zu behalten.
- Keine Share-Wartezeit oder Widerrufslogik, die nur im Browser entschieden wird.
- Keine generische SQL-API, die Request-gesteuerte Identifier, Sortierungen oder SQL-Fragmente akzeptiert.
