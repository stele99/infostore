<?php
// App-Shell. Setzt HTTP-Sicherheitsheader; keine Inline-Skripte, keine CDNs.
// 'wasm-unsafe-eval' erlaubt ausschliesslich das Kompilieren von WebAssembly
// (fuer die Argon2id-Bibliothek); klassisches eval() bleibt verboten.
header("Content-Security-Policy: default-src 'none'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline'; "
    . "connect-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
?>
<!DOCTYPE html>
<html lang="de" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#0b0f17">
    <title>Secure Info Store</title>
    <link rel="stylesheet" href="assets/vendor/quill.snow.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <script src="assets/vendor/quill.js" defer></script>
    <script src="assets/vendor/argon2.umd.min.js" defer></script>
    <script src="assets/js/app.js" type="module"></script>
</head>

<body>
    <a class="skip-link" href="#main-content">Zum Inhalt springen</a>

    <button type="button" id="bt-theme" class="theme-toggle" aria-label="Design umschalten" title="Design umschalten">
        <svg class="icon-moon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M21 14.5A8.5 8.5 0 0 1 9.5 3 7 7 0 1 0 21 14.5z"></path>
        </svg>
        <svg class="icon-sun" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="4"></circle>
            <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
        </svg>
    </button>

    <!-- ================= Login / Registrierung ================= -->
    <section id="view-login" class="view" aria-labelledby="login-title">
        <div class="auth-wrap">
            <div class="brand">
                <div class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <rect x="5" y="10" width="14" height="11" rx="2"></rect>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                        <circle cx="12" cy="15.5" r="1.2"></circle>
                    </svg>
                </div>
                <div class="brand-text">
                    <strong>Secure Info Store</strong>
                    <span>Clientseitig sicher verschlüsselte Notizen</span>
                </div>
            </div>

            <div class="card">
                <h1 id="login-title">Zugang</h1>
                <p class="card-lead">Inhalte werden im Browser verschlüsselt. Passwort und Schlüssel verlassen dein Gerät nicht.</p>
                <p id="login-notice" class="notice" hidden data-kind="warn"></p>

                <div class="auth-tabs" role="tablist" aria-label="Anmelden oder Store anlegen">
                    <button type="button" class="auth-tab" id="tab-login" role="tab" aria-selected="true" aria-controls="form-login">Anmelden</button>
                    <button type="button" class="auth-tab" id="tab-register" role="tab" aria-selected="false" aria-controls="form-login">Store anlegen</button>
                </div>

                <form id="form-login">
                    <div class="form-group">
                        <label for="login-store">Store-ID</label>
                        <input type="text" id="login-store" name="username" autocomplete="username"
                            minlength="5" maxlength="64" pattern="[A-Za-z0-9._\-]{5,64}" required
                            placeholder="z. B. mein-vault">
                    </div>
                    <div class="form-group">
                        <label for="login-password">Passwort</label>
                        <div class="input-wrap">
                            <input type="password" id="login-password" name="password"
                                autocomplete="current-password" minlength="12" required
                                placeholder="Mindestens 12 Zeichen">
                            <button type="button" class="input-action" id="bt-pw-toggle" aria-label="Passwort anzeigen" title="Passwort anzeigen">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                        <small id="login-pw-hint">Mindestens 12 Zeichen. Ohne Passwort sind die Daten unwiederbringlich verloren.</small>
                    </div>
                    <div class="button-row">
                        <button type="submit" class="primary" id="bt-login">
                            <span class="spinner" hidden aria-hidden="true"></span>
                            <span class="btn-label">Anmelden</span>
                        </button>
                    </div>
                </form>
                <p class="alt-link"><a href="#" id="link-shareaccess">Notfallzugriff mit Seed-Phrase</a></p>
            </div>
        </div>
    </section>

    <!-- ================= Notfallzugriff (Empfänger) ================= -->
    <section id="view-shareaccess" class="view" hidden aria-labelledby="sa-title">
        <div class="auth-wrap">
            <div class="brand">
                <div class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 3l8 4v5c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V7l8-4z"></path>
                        <path d="M9.5 12l1.8 1.8L15 10"></path>
                    </svg>
                </div>
                <div class="brand-text">
                    <strong>Notfallzugriff</strong>
                    <span>Zeitverzögerter Lesezugriff</span>
                </div>
            </div>
            <div class="card">
                <h1 id="sa-title">Seed-Phrase eingeben</h1>
                <p class="card-lead">Je nach Einstellung wird der Zugriff erst nach einer Wartezeit freigegeben.
                    Der Inhaber wird benachrichtigt und kann ablehnen.</p>
                <form id="form-shareaccess">
                    <div class="form-group">
                        <label for="sa-store">Store-ID</label>
                        <input type="text" id="sa-store" minlength="5" maxlength="64" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="sa-seed">Seed-Phrase (12 Wörter)</label>
                        <textarea id="sa-seed" rows="3" required spellcheck="false" autocomplete="off"
                            placeholder="zwölf wörter durch leerzeichen getrennt"></textarea>
                        <div class="seed-meta">
                            <span id="sa-wordcount">0 / 12 Wörter</span>
                        </div>
                    </div>
                    <div class="button-row">
                        <button type="submit" class="primary" id="bt-sa-submit">
                            <span class="spinner" hidden aria-hidden="true"></span>
                            <span class="btn-label">Zugriff anfordern</span>
                        </button>
                        <button type="button" class="ghost" id="bt-sa-back">Zurück</button>
                    </div>
                </form>
                <p id="sa-status" class="notice" hidden aria-live="polite"></p>
            </div>
        </div>
    </section>

    <!-- ================= Hauptansicht ================= -->
    <section id="view-main" class="view" hidden>
        <header class="topbar">
            <button type="button" id="bt-drawer" class="icon-btn mobile-only" aria-label="Notizen öffnen" title="Notizen" aria-expanded="false" aria-controls="sidebar">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16"></path>
                </svg>
            </button>
            <div class="topbar-meta">
                <span id="main-store" class="storename"></span>
                <span id="role-badge" class="badge badge-accent" hidden></span>
                <span id="save-state" class="badge" aria-live="polite"></span>
            </div>
            <span class="spacer"></span>
            <div class="topbar-actions">
                <button type="button" id="bt-shares" class="ghost" hidden title="Shares verwalten">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="18" cy="5" r="2.5"></circle>
                        <circle cx="6" cy="12" r="2.5"></circle>
                        <circle cx="18" cy="19" r="2.5"></circle>
                        <path d="M8.4 13.1l7.2 4.2M15.6 6.7l-7.2 4.2"></path>
                    </svg>
                    <span class="btn-text btn-text-hide-sm">Shares</span>
                </button>
                <button type="button" id="bt-logout" class="ghost" title="Abmelden">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M10 7V5a2 2 0 0 1 2-2h7v18h-7a2 2 0 0 1-2-2v-2"></path>
                        <path d="M3 12h11M10 8l4 4-4 4"></path>
                    </svg>
                    <span class="btn-text btn-text-hide-sm">Abmelden</span>
                </button>
            </div>
        </header>

        <div class="sidebar-backdrop" id="sidebar-backdrop" hidden></div>

        <div class="main-layout" id="main-content">
            <aside class="sidebar" id="sidebar" aria-label="Notizliste">
                <div class="sidebar-head">
                    <input type="search" id="entry-search" placeholder="Suchen…" aria-label="Einträge durchsuchen">
                    <button type="button" id="bt-new-side" class="icon-btn primary" title="Neuer Eintrag" aria-label="Neuer Eintrag" hidden>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 5v14M5 12h14"></path>
                        </svg>
                    </button>
                </div>
                <div id="entry-list-loading" class="skeleton-list" hidden aria-hidden="true">
                    <div class="skeleton-line"></div>
                    <div class="skeleton-line"></div>
                    <div class="skeleton-line"></div>
                    <div class="skeleton-line"></div>
                </div>
                <ul id="entry-list" class="entry-list" role="listbox" aria-label="Einträge"></ul>
            </aside>

            <main class="editor-area" aria-label="Editor">
                <div id="recipient-banner" class="recipient-banner" hidden>
                    Nur Lesezugriff – Änderungen sind nicht möglich.
                </div>
                <div class="title-field">
                    <label for="entry-title" class="title-label">Titel</label>
                    <input type="text" id="entry-title" placeholder="Titel der Notiz eingeben…" maxlength="500" class="title-input">
                </div>
                <div class="meta" id="entry-meta"></div>
                <div class="editor-shell">
                    <div id="editor"></div>
                </div>
                <div class="editor-actions" id="editor-actions">
                    <button type="button" class="primary" id="bt-save" hidden>
                        <span class="spinner" hidden aria-hidden="true"></span>
                        <span class="btn-label">Speichern</span>
                    </button>
                    <button type="button" id="bt-new" class="ghost" hidden>Neuer Eintrag</button>
                    <span class="spacer"></span>
                    <button type="button" class="danger" id="bt-delete" hidden>Löschen</button>
                </div>
            </main>
        </div>
    </section>

    <!-- ================= Share-Verwaltung (Owner) ================= -->
    <section id="view-shares" class="view" hidden aria-labelledby="shares-title">
        <div class="card wide">
            <div class="card-header">
                <h1 id="shares-title">Notfallzugriff verwalten</h1>
                <button type="button" id="bt-shares-close" class="icon-btn card-close" aria-label="Schließen" title="Schließen">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18"></path>
                    </svg>
                </button>
            </div>
            <p class="card-lead">Ein Share erlaubt einer Vertrauensperson mit einer 12-Wort-Seed-Phrase
                Lesezugriff – auf Wunsch erst nach einer Wartezeit, in der du ablehnen kannst.</p>
            <form id="form-share-create">
                <div class="form-group">
                    <label for="share-mail">Deine E-Mail (Benachrichtigung bei Anfrage)</label>
                    <input type="email" id="share-mail" maxlength="254" required autocomplete="email">
                </div>
                <div class="form-group">
                    <label for="share-delay">Wartezeit in Stunden (0 = sofort)</label>
                    <input type="number" id="share-delay" min="0" max="8760" value="72" required>
                </div>
                <div class="form-group">
                    <label>Seed-Phrase <span class="field-hint">(einmalig anzeigen und sicher übergeben)</span></label>
                    <output id="share-seed" class="seedbox"></output>
                    <div class="button-row">
                        <button type="button" id="bt-seed-new" class="ghost">Neue Phrase</button>
                        <button type="button" id="bt-seed-copy" class="ghost">Kopieren</button>
                        <button type="button" id="bt-seed-print" class="ghost">Drucken</button>
                    </div>
                </div>
                <div class="button-row">
                    <button type="submit" class="primary" id="bt-share-create">
                        <span class="spinner" hidden aria-hidden="true"></span>
                        <span class="btn-label">Share einrichten</span>
                    </button>
                    <button type="button" class="ghost" id="bt-shares-back">Zurück</button>
                </div>
            </form>
            <h2>Bestehende Shares</h2>
            <ul id="share-list" class="share-list"></ul>
        </div>
    </section>

    <!-- ================= Druckvorlage Notfallzugriff ================= -->
    <div id="print-sheet">
        <h1>Notfallzugriff – Secure Info Store</h1>
        <p>
            Diese Seite enthält die Zugangsdaten für einen Notfallzugriff auf einen
            verschlüsselten Info-Store. Bewahre sie so sicher auf wie ein Passwort –
            getrennt von digitalen Kopien, z.&nbsp;B. in einem verschlossenen Umschlag
            oder Safe.
        </p>
        <table>
            <tr>
                <th>Store-ID</th>
                <td id="ps-store"></td>
            </tr>
            <tr>
                <th>Adresse</th>
                <td id="ps-url"></td>
            </tr>
            <tr>
                <th>Wartezeit bis Freigabe</th>
                <td id="ps-delay"></td>
            </tr>
            <tr>
                <th>Benachrichtigung an</th>
                <td id="ps-mail"></td>
            </tr>
            <tr>
                <th>Ausgestellt am</th>
                <td id="ps-date"></td>
            </tr>
        </table>
        <h2>Seed-Phrase (12 Wörter, in dieser Reihenfolge)</h2>
        <ol id="ps-seed-list" class="ps-seed-list"></ol>
        <h2>Anleitung für den Notfallzugriff</h2>
        <ol>
            <li>Im Browser die oben stehende Adresse aufrufen.</li>
            <li>Auf der Anmeldeseite den Link „Notfallzugriff mit Seed-Phrase“ anklicken.</li>
            <li>Store-ID sowie die 12 Wörter oben eingeben (Reihenfolge beachten, durch Leerzeichen getrennt).</li>
            <li>
                Ist eine Wartezeit hinterlegt, wird der Inhaber per E-Mail benachrichtigt
                und kann den Zugriff ablehnen. Ohne Widerspruch wird der Zugriff nach
                Ablauf der Wartezeit automatisch freigeschaltet.
            </li>
        </ol>
        <p class="ps-warning">
            Wichtig: Diese Phrase gewährt Lesezugriff auf alle Notizen dieses Stores.
            Wird ein neuer Share eingerichtet oder dieser widerrufen, verliert dieser
            Ausdruck seine Gültigkeit.
        </p>
    </div>

    <!-- ================= Confirm-Dialog ================= -->
    <div id="dialog-backdrop" class="dialog-backdrop" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="dialog-title" aria-describedby="dialog-body">
            <h2 id="dialog-title">Bestätigen</h2>
            <p id="dialog-body"></p>
            <div class="dialog-actions">
                <button type="button" class="ghost" id="dialog-cancel">Abbrechen</button>
                <button type="button" class="danger" id="dialog-confirm">Bestätigen</button>
            </div>
        </div>
    </div>

    <div class="toast-host" aria-live="polite" aria-relevant="additions text">
        <div id="statusmsg" class="toast" role="status" hidden>
            <span class="toast-dot" aria-hidden="true"></span>
            <span id="statusmsg-text" class="toast-text"></span>
        </div>
    </div>
</body>

</html>
