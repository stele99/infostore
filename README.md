# Secure Info Store
Ein selbst gehosteter, verschlüsselter Notizspeicher. Notizinhalte werden im Browser verschlüsselt und erst danach an die JSON-API übertragen. Der Server speichert Ciphertext und Metadaten, aber keinen Schlüssel zur Entschlüsselung der Inhalte.

> **Hinweis zum Projektstatus:** Der aktuelle Branch ist ein sicherheitsorientierter Neuaufbau und noch nicht als fertig geprüftes Produktionsprodukt freigegeben. Vor einem externen Betrieb müssen insbesondere Deployment, TLS/HSTS, Browser-E2E-Tests, CI sowie ein unabhängiger Kryptografie- und Penetrationstest abgeschlossen werden. Details stehen im [Refactoring-Plan](docs/refactoring_plan.md).

## Funktionen

- Store-Registrierung und Anmeldung über eine browserseitige Schlüsselableitung
- Clientseitige Verschlüsselung von Titeln und Inhalten mit AES-256-GCM
- Schlüsselmaterial nur im Speicher des Browsers, kein `localStorage` oder `sessionStorage`
- Strukturierte Rich-Text-Notizen mit Quill-Deltas
- Serverautorisierung über PHP-Sessions, Rollen und CSRF-Schutz
- Optimistische Konflikterkennung beim parallelen Bearbeiten
- Zeitverzögertes Sharing mit 12-Wort-Seed-Phrase
- Serverseitiger Share-Zustandsautomat mit Ablehnung und Widerruf
- SQLite-Datenbank mit versionierten Migrationen
- Strikte Content Security Policy und lokale Auslieferung der Frontend-Abhängigkeiten

## Technischer Überblick

Das Projekt benötigt keine Composer- oder Node-Abhängigkeiten und keinen Build-Schritt.

- `public/` ist der einzige öffentliche Webroot.
- `public/index.php` liefert die Anwendungsshell.
- `public/api.php` ist der JSON-API-Front-Controller mit fester Routing-Tabelle.
- `public/assets/` enthält Vanilla-ES-Module, CSS und die lokal eingebundene Quill-Version.
- `app/` enthält HTTP-Schicht, Sessions, Services, Repositories, Mail-Adapter und Migrationen.
- `migrations/` enthält einmalig ausführbare SQL-Migrationen.
- `tests/` enthält den dependency-freien PHP-Testrunner und die Integrationstests.
- `var/` enthält lokale Laufzeitdaten und ist von Git ausgeschlossen.

### Sicherheitsmodell

Beim Registrieren oder Anmelden leitet der Browser aus Store-ID, Passwort und Salt ein Master-Secret ab. Dieses wird in einen Authentifizierungsanteil und einen Inhaltsanteil geteilt:

1. Der Authentifizierungsanteil wird zur Anmeldung an den Server übertragen und dort mit Argon2id verarbeitet.
2. Der Inhaltsanteil bleibt im Browser und wird für AES-256-GCM verwendet.
3. Passwort, Inhalts-Key und Klartext verlassen den Browser nicht.
4. Nach 15 Minuten Inaktivität oder beim Abmelden wird das Schlüsselmaterial aus dem Anwendungsspeicher entfernt.

Die kryptografischen Nutzdaten sind versioniert. Änderungen am Format benötigen deshalb eine neue Version und einen kompatiblen Migrationspfad.

## Voraussetzungen

- PHP 8.2 oder neuer
- PHP-Erweiterung `pdo_sqlite`
- PHP-Erweiterung `sodium`
- Ein Webserver, dessen Document Root auf `public/` zeigt

Composer, npm und ein Frontend-Build sind nicht erforderlich.

## Lokale Einrichtung

Repository auschecken und in das Projektverzeichnis wechseln:

```sh
cd ai-dev/infostore
```

Für eine lokale Entwicklungsinstanz reicht der PHP-Entwicklungsserver. Er muss aus `public/` gestartet werden, damit ausschließlich der vorgesehene Webroot erreichbar ist:

```sh
cd public
php -S localhost:8000
```

Danach die Anwendung unter <http://localhost:8000> öffnen.

Beim ersten Request werden die SQLite-Datenbank und die erforderlichen Migrationen automatisch angelegt. Standardmäßig liegen Laufzeitdaten unter `var/`; dieser Pfad darf in einer echten Bereitstellung nicht öffentlich erreichbar sein.

### Konfiguration

Die verfügbaren Umgebungsvariablen sind in [`config/env.example`](config/env.example) beschrieben. Für lokale Anpassungen kann zusätzlich eine nicht versionierte `config/local.php` verwendet werden, die ein PHP-Array zurückgibt.

Wichtige Variablen:

| Variable | Zweck | Standard |
| --- | --- | --- |
| `INFOSTORE_DB` | Pfad zur SQLite-Datenbank | `var/data.sqlite3` |
| `INFOSTORE_LOG_DIR` | Geschütztes Logverzeichnis | `var/log` |
| `INFOSTORE_SECRET_FILE` | Servergeheimnis für Decoy-Salts | `var/app_secret` |
| `INFOSTORE_MAIL` | Mail-Transport: `file` oder `native` | `file` |
| `INFOSTORE_MAIL_DIR` | Ablage von Test-Mails beim File-Transport | `var/mail` |
| `INFOSTORE_MAIL_FROM` | Absenderadresse | `infostore@localhost` |

Für den lokalen File-Mailer werden erzeugte Nachrichten als `.eml` in `INFOSTORE_MAIL_DIR` abgelegt. Für produktiven Mailversand kann `INFOSTORE_MAIL=native` gesetzt werden, sofern PHP `mail()` korrekt konfiguriert ist.

## Tests und Qualitätsprüfungen

Dependency-freie Tests mit einer frischen SQLite-Datei pro Lauf:

```sh
php tests/run.php
```

PHP-Syntax aller versionierten PHP-Dateien prüfen:

```sh
for f in $(git ls-files '*.php'); do php -l "$f" || exit 1; done
```

## API-Überblick

Die API wird unter `/api.php` ausgeliefert und verwendet feste Methoden- und Pfadmuster. Relevante Gruppen sind:

- `/auth/*`: Registrierung, KDF-Parameter, Login, Logout und Sessionstatus
- `/entries`: geschützte Auflistung und Verwaltung verschlüsselter Notizen
- `/shares`: Share-Verwaltung für Store-Inhaber
- `/share-access/*`: öffentliche Anfrage nach einem freigegebenen Lesezugriff

Authentifizierte Mutationen benötigen die PHP-Session sowie den CSRF-Header `X-CSRF-Token`. Store- und Eigentümerberechtigungen werden serverseitig aus der Session bestimmt und nicht aus frei übergebenen Request-Werten übernommen.

## Projektstruktur

```text
app/                    Backend und Anwendungslogik
config/                 Beispiel- und lokale Konfiguration
docs/                   Architektur- und Refactoring-Dokumentation
migrations/             Versionierte SQLite-Migrationen
public/                 Einziger Webroot und Frontend
tests/                  Dependency-freie Testsuite
var/                    Lokale Laufzeitdaten, nicht versioniert
```

## Betriebshinweise

- In Produktion ausschließlich `public/` als Document Root konfigurieren.
- Datenbank, Logs, Secret-Datei, Backups und Mailablage außerhalb des öffentlichen Webroots speichern.
- TLS, sichere Cookie-Flags, HSTS, Request-Limits und Fehlerlogging auf Webserver- und PHP-Ebene konfigurieren.
- `display_errors` in Produktion deaktivieren.
- Das Passwort sicher verwahren: Es gibt keine serverseitige Wiederherstellung des Inhalts-Keys.
- Vor einer Freigabe den vollständigen [Refactoring-Plan](docs/refactoring_plan.md) und insbesondere die offenen Sicherheitsprüfungen beachten.

## Lizenz

Im Repository ist derzeit keine Lizenzdatei enthalten. Eine Lizenz muss vor einer Veröffentlichung ergänzt werden.
Concept and Implementation by stele99