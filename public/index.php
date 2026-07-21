<?php
// App-Shell. Setzt HTTP-Sicherheitsheader; keine Inline-Skripte, keine CDNs.
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
    . "connect-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Secure Info Store</title>
    <link rel="stylesheet" href="assets/vendor/quill.snow.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <script src="assets/vendor/quill.js" defer></script>
    <script src="assets/js/app.js" type="module"></script>
</head>

<body>
    <!-- ================= Login / Registrierung ================= -->
    <section id="view-login" class="view">
        <div class="card">
            <h1>Secure Info Store</h1>
            <p id="login-notice" class="notice" hidden></p>
            <form id="form-login">
                <div class="form-group">
                    <label for="login-store">Store-ID</label>
                    <input type="text" id="login-store" name="username" autocomplete="username"
                        minlength="5" maxlength="64" pattern="[A-Za-z0-9._\-]{5,64}" required>
                </div>
                <div class="form-group">
                    <label for="login-password">Passwort</label>
                    <input type="password" id="login-password" name="password"
                        autocomplete="current-password" minlength="12" required>
                    <small>Mindestens 12 Zeichen. Das Passwort verlaesst den Browser nie -
                        ohne Passwort sind die Daten unwiederbringlich verloren.</small>
                </div>
                <div class="button-row">
                    <button type="submit" class="primary" id="bt-login">Anmelden</button>
                    <button type="button" id="bt-register">Neuen Store anlegen</button>
                </div>
            </form>
            <p class="alt-link"><a href="#" id="link-shareaccess">Notfallzugriff mit Seed-Phrase</a></p>
        </div>
    </section>

    <!-- ================= Notfallzugriff (Empfaenger) ================= -->
    <section id="view-shareaccess" class="view" hidden>
        <div class="card">
            <h1>Notfallzugriff</h1>
            <p>Gib die Store-ID und die 12-Wort-Seed-Phrase ein, die du erhalten hast.
                Je nach Einstellung des Inhabers wird der Zugriff erst nach einer Wartezeit
                freigegeben; der Inhaber wird benachrichtigt und kann ablehnen.</p>
            <form id="form-shareaccess">
                <div class="form-group">
                    <label for="sa-store">Store-ID</label>
                    <input type="text" id="sa-store" minlength="5" maxlength="64" required>
                </div>
                <div class="form-group">
                    <label for="sa-seed">Seed-Phrase (12 Woerter, durch Leerzeichen getrennt)</label>
                    <textarea id="sa-seed" rows="3" required spellcheck="false" autocomplete="off"></textarea>
                </div>
                <div class="button-row">
                    <button type="submit" class="primary">Zugriff anfordern / pruefen</button>
                    <button type="button" class="linklike" id="bt-sa-back">Zurueck zum Login</button>
                </div>
            </form>
            <p id="sa-status" class="notice" hidden aria-live="polite"></p>
        </div>
    </section>

    <!-- ================= Hauptansicht ================= -->
    <section id="view-main" class="view" hidden>
        <header class="topbar">
            <span id="main-store" class="storename"></span>
            <span id="save-state" aria-live="polite"></span>
            <span class="spacer"></span>
            <button id="bt-shares" hidden>Shares verwalten</button>
            <button id="bt-logout">Abmelden</button>
        </header>
        <div class="main-layout">
            <aside class="sidebar">
                <input type="search" id="entry-search" placeholder="Suchen..." aria-label="Eintraege durchsuchen">
                <ul id="entry-list" class="entry-list"></ul>
            </aside>
            <div class="editor-area">
                <input type="text" id="entry-title" placeholder="Titel" maxlength="500" class="title-input">
                <div class="meta" id="entry-meta"></div>
                <div id="editor"></div>
                <div class="button-row">
                    <button class="primary" id="bt-save">Speichern</button>
                    <button id="bt-new">Neuer Eintrag</button>
                    <button class="danger" id="bt-delete">Loeschen</button>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= Share-Verwaltung (Owner) ================= -->
    <section id="view-shares" class="view" hidden>
        <div class="card wide">
            <h1>Notfallzugriff verwalten</h1>
            <p>Ein Share erlaubt einer Vertrauensperson mit einer 12-Wort-Seed-Phrase
                Lesezugriff auf diesen Store - auf Wunsch erst nach einer Wartezeit,
                in der du die Anfrage ablehnen kannst.</p>
            <form id="form-share-create">
                <div class="form-group">
                    <label for="share-mail">Deine E-Mail (Benachrichtigung bei Anfrage)</label>
                    <input type="email" id="share-mail" maxlength="254" required autocomplete="email">
                </div>
                <div class="form-group">
                    <label for="share-delay">Wartezeit in Stunden (0 = sofortiger Zugriff)</label>
                    <input type="number" id="share-delay" min="0" max="8760" value="72" required>
                </div>
                <div class="form-group">
                    <label>Seed-Phrase (einmalig anzeigen und sicher uebergeben!)</label>
                    <output id="share-seed" class="seedbox"></output>
                    <div class="button-row">
                        <button type="button" id="bt-seed-new">Neue Phrase erzeugen</button>
                    </div>
                </div>
                <div class="button-row">
                    <button type="submit" class="primary">Share einrichten</button>
                    <button type="button" id="bt-shares-back">Zurueck</button>
                </div>
            </form>
            <h2>Bestehende Shares</h2>
            <ul id="share-list" class="share-list"></ul>
        </div>
    </section>

    <div class="statusbar">
        <div id="statusmsg" role="status" aria-live="polite" hidden></div>
    </div>
</body>

</html>
