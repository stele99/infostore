# Security Review: Secure Info Store (Komplett-Review)

**Stand:** 2026-07-21, Branch `refactor/secure-rewrite`, Commit `20dfcc2`.
**Umfang:** Gesamter Erstanbieter-Code (`app/`, `public/`, `migrations/`, `tests/`, Konfiguration und Deployment-Artefakte). Die vendorten Bibliotheken `quill.js`/`quill.snow.css` und die BIP39-Wortliste wurden als Fremdcode nur stichprobenartig geprüft.
**Methodik:** Manuelle Code-Analyse entlang der Angriffsflächen (API, Session, Krypto, Client), Verifikation aller Befunde direkt im Code, gestützt durch die bestehende Testsuite (24 Integrationstests, alle grün) und den E2E-Lauf mit echter Client-Kryptografie.

---

## 1. Kernfrage: Was kann ein Angreifer mit dem gestohlenen Server wiederherstellen?

Bedrohungsmodell: Der Angreifer besitzt eine vollständige Kopie des Servers — `data.sqlite3`, `var/app_secret`, `var/mail/`, Quellcode, PHP-Session-Dateien. Er hat unbegrenzt Zeit und Offline-Rechenleistung.

### 1.1 Notizinhalte (Tabelle `entries`)

Gespeichert sind ausschließlich AES-256-GCM-Chiffretexte (`title_ct`, `body_ct`) mit IVs. Der Schlüssel wird nur im Browser gehalten und ist auf zwei Wegen ableitbar:

| Weg | Material auf dem Server | Offline-Angriff |
| --- | --- | --- |
| Passwort | `kdf_salt`, `kdf_iterations` (Klartext, notwendig) | Brute-Force über PBKDF2-SHA256 mit 600.000 Iterationen pro Versuch |
| Seed-Phrase (Share) | `shares.wrapped_key`, `kdf_salt` | Brute-Force über 12 BIP39-Wörter = 132 Bit Entropie — **praktisch unmöglich** |

**Bewertung:** Der einzige realistische Wiederherstellungsweg ist das Erraten des Nutzerpassworts. Ein GCM-Tag verrät sofort, ob ein Kandidat stimmt; der Angreifer braucht also weder `auth_hash` noch den Server — nur Salt, Iterationszahl und einen Chiffretext. Das serverseitige Argon2id über dem Auth-Key schützt in diesem Szenario **nicht** die Inhalte, sondern nur den Login.

Konkrete Größenordnung: PBKDF2-SHA256 ist GPU-freundlich. Mit aktueller Consumer-Hardware sind bei 600k Iterationen grob 10³–10⁴ Passwortkandidaten pro Sekunde und GPU realistisch. Ein zufälliges 12-Zeichen-Passwort ist damit außer Reichweite; ein schwaches 12-Zeichen-Passwort nach Muster („Sommer2026!!") fällt in Stunden bis Tagen. **Die Vertraulichkeit bei Server-Diebstahl steht und fällt mit der Passwortqualität** — das ist bei jedem passwortbasierten E2EE-System so (Bitwarden, Standard Notes), muss aber dem Nutzer klar kommuniziert werden (siehe F-7, F-9).

### 1.2 Login-Geheimnisse (Tabelle `stores`)

`auth_hash` ist Argon2id über einem HKDF-abgeleiteten 32-Byte-Wert. Selbst wenn der Angreifer den Auth-Key vollständig wiederherstellen könnte, ist daraus wegen der HKDF-Domänentrennung (`infostore/v1/auth` vs. `infostore/v1/enc`) kein Inhaltsschlüssel ableitbar. **Nicht wiederherstellbar.**

### 1.3 Share-Schlüsselpakete (Tabelle `shares`)

`wrapped_key` ist der mit dem Seed-Key (AES-GCM, AAD-gebunden an die Share-UID) verschlüsselte Content-Key. 132 Bit Seed-Entropie, kanonische Normalisierung, verzerrungsfreie Worterzeugung (2048 = 2¹¹, Maskierung ohne Modulo-Bias). **Nicht wiederherstellbar.**

### 1.4 Was der Angreifer im Klartext bekommt (Metadaten)

Diese Daten sind bei Diebstahl **offen lesbar** und müssen als kompromittiert gelten:

- **Store-Namen** (frei gewählte Identifikatoren — können Rückschlüsse auf Personen zulassen),
- **`shares.owner_mail` im Klartext** (siehe F-4) sowie alle `.eml`-Dateien in `var/mail/` mit Empfängeradresse und Anfragezeitpunkt,
- Share-Status, Wartezeiten, alle Zeitstempel, Audit-Events,
- Anzahl und Größenordnung der Einträge je Store, Erstell-/Änderungszeiten,
- `login_attempts`-Subjekte (HMAC mit `app_secret`; mit gestohlenem Secret per Wörterbuch über Namen/IP-Bereiche de-anonymisierbar, F-13).

**Fazit Kernfrage:** Notizinhalte und Schlüsselmaterial sind bei Server-Diebstahl nicht wiederherstellbar, *sofern das Passwort stark ist*; die Seed-Schiene ist auch gegen unbegrenzte Offline-Angriffe sicher. Metadaten (v. a. E-Mail-Adressen und Store-Namen) liegen jedoch im Klartext — hier besteht Handlungsbedarf, wenn der Anspruch „keine Daten wiederherstellbar" wörtlich gelten soll.

---

## 2. Befunde

### F-1 (Hoch): Widerruf beendet aktive Empfänger-Sessions nicht

Nach `granted` erhält der Empfänger eine PHP-Session mit `role=recipient` (`public/api.php:196`). Widerruft der Inhaber den Share anschließend (`ShareService::revoke`), bleibt diese Session bis zum Ablauf (bis zu 1 h, `gc_maxlifetime=3600`) gültig — der Empfänger kann weiter alle Einträge abrufen. Die Session ist nicht an den Share gebunden; kein Guard prüft den Share-Status erneut.

**Empfehlung:** `share_uid` in die Empfänger-Session schreiben und im `auth`-Guard bei `role=recipient` den Share-Status pro Request gegen die Datenbank prüfen (`status='granted'`), sonst 401 und Session invalidieren.

### F-2 (Mittel): KDF-Downgrade — Client übernimmt Iterationszahl ungeprüft vom Server

Beim Login verwendet der Client `kdf_iterations` aus der Server-Antwort ohne Untergrenze (`public/assets/js/app.js:104-107`). Ein kompromittierter Server oder MITM (ohne TLS) kann z. B. `100000` oder weniger liefern und so die Offline-Angriffskosten auf abgefangene oder gestohlene Chiffretexte drastisch senken. Zwar kann ein kompromittierter Server ohnehin bösartiges JS ausliefern, aber die Untergrenze ist eine billige Verteidigungslinie und schützt auch gegen manipulierte API-Antworten allein.

**Empfehlung:** Client l