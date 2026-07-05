# Async Mailbox-Formular (Verbindungstest + Speichern) Design

Datum: 2026-07-05
Status: Freigegeben für Planerstellung

## Ziel

Im Profil verliert das Mailbox-Formular nach "Verbindung testen" alle noch nicht
gespeicherten Eingaben (insbesondere das Passwort, das aus Sicherheitsgründen nie
zurückgespielt wird). Bei Erstanlage eines Mailbox-Zugangs (noch kein gespeicherter
Account) zwingt das den Benutzer, das Passwort vor dem eigentlichen Speichern erneut
einzugeben. Derselbe Effekt betrifft auch "Mailbox speichern" selbst: schlägt die
serverseitige Validierung fehl (z. B. ungültiger Host), gehen ebenfalls alle Felder
verloren, da hierfür kein Präfill-Mechanismus existiert.

Beide Aktionen werden auf asynchrone Übertragung umgestellt, sodass der Browser die
eingegebenen Werte nativ im DOM behält und kein Seiten-Reload mehr nötig ist, außer
nach einem erfolgreichen Speichern (um abgeleiteten Server-State wie den
"Gespeichert"-Hinweis oder den Webmail-Button zu aktualisieren).

## Festgelegte Entscheidungen

1. Content-Negotiation statt Endpoint-Umbau: beide betroffenen Controller-Methoden
   (`testMailboxConnection`, `updateMailbox`) bekommen einen zusätzlichen JSON-Zweig,
   der nur greift, wenn der Request `Accept: application/json` sendet. Der bestehende
   Redirect+Session-Flash-Pfad bleibt für Nicht-JS-Clients unverändert erhalten.
2. Grund für Content-Negotiation statt vollständiger Umstellung: 13 bestehende Tests
   in `tests/Feature/ProfileMailboxFeatureTest.php` hängen am Redirect-Verhalten
   beider Endpunkte. Eine Umstellung auf ausschließlich JSON würde alle brechen.
   Content-Negotiation lässt sie unverändert grün; nur der neue JSON-Zweig bekommt
   neue Tests.
3. Kein neues CSRF-Handling nötig: `HtmlFormCsrfInjectorMiddleware` injiziert das
   versteckte `_csrf`-Feld bereits serverseitig ins gerenderte Formular;
   `new FormData(form)` im Browser liest es automatisch mit.
4. Kein Schema-Change, keine Migration, kein Seed-Update.

## Architektur

### 1. Backend (`src/Controllers/ProfileController.php`)

Neue private Methode:

```php
private function wantsJsonResponse(Request $request): bool
{
    return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
}
```

**`testMailboxConnection()`** — an jedem bestehenden Exit-Point wird bei
`wantsJsonResponse($request) === true` ein JSON-Body statt Session+Redirect
zurückgegeben:

| Fall | Nicht-JSON (unverändert) | JSON (neu) |
|---|---|---|
| Validierungsfehler (Host/Port/Verschlüsselung) | Session-Error + Redirect | 422, `{success:false, message}` |
| SSRF-geblockter Host | Session-Error + Redirect | 200, `{success:false, message}` (generische Meldung bleibt identisch) |
| Socket-Verbindung erfolgreich | Session-Success + Redirect | 200, `{success:true, message:"Verbindung erfolgreich."}` |
| Socket-Verbindung fehlgeschlagen / keine gültige IMAP-Antwort | Session-Error + Redirect | 200, `{success:false, message}` |

Im JSON-Zweig wird `$_SESSION['mailbox_form_old']` nicht gesetzt (kein Reload, daher
kein Präfill nötig).

**`updateMailbox()`** — gleiche Behandlung an seinen beiden Exit-Points:

| Fall | Nicht-JSON (unverändert) | JSON (neu) |
|---|---|---|
| Validierungsfehler | Session-Error + Redirect | 422, `{success:false, message}` |
| Erfolg | Session-Success + Redirect | 200, `{success:true, message:"Mailbox-Einstellungen wurden gespeichert."}` |
| DB-Exception | Session-Error + Redirect | 500, `{success:false, message}` (generisch, kein Interna-Leak) |

`index()` und `mailboxViewFromOldInput()` bleiben unverändert (weiterhin nötig für
den Nicht-JS-Fallback-Pfad).

### 2. Frontend (neu: `public/js/profile-mailbox.js`)

- `submit`-Listener auf dem Formular (`#mailboxForm`).
- `event.submitter` (Standard-DOM-API) bestimmt, welcher Button ausgelöst hat, und
  liefert dessen Ziel-URL über `formAction` (Save-Button: Formular-`action`,
  Test-Button: sein `formaction`-Attribut, beides bereits im Markup vorhanden).
- `e.preventDefault()`, dann `fetch(url, {method:"POST", headers:{Accept:"application/json"}, body:new FormData(form)})`.
- Response wird als JSON geparst (`{success, message}`); bei Netzwerk-/Parse-Fehler
  generische Fehlermeldung.
- Inline-Feedback in `#mailboxFormFeedback` (Bootstrap-Alert, `alert-success` bei
  `success:true`, sonst `alert-danger`), analog zum bestehenden Muster in
  `public/js/users.js`.
- Button während der Anfrage deaktiviert, Text auf "Wird gesendet…" (gleiches Muster
  wie `users.js`).
- **Verbindungstest:** nie Reload, unabhängig vom Ergebnis.
- **Speichern, Erfolg:** `window.location.reload()`, um abgeleiteten Server-State zu
  aktualisieren (z. B. "Gespeichert – leer lassen…"-Hinweis, Sichtbarkeit des
  Webmail-Buttons).
- **Speichern, Fehler:** kein Reload, alle Felder bleiben im DOM unverändert
  (inklusive Passwort — löst das Kernproblem).

### 3. Template (`templates/profile/index.twig`)

- `id="mailboxForm"` am `<form action="/profile/mailbox" method="post">`-Tag.
- Neues `<div id="mailboxFormFeedback" class="d-none mb-3" role="alert"></div>`
  direkt nach dem öffnenden `<form>`-Tag.
- `{% block scripts %}<script src="/js/profile-mailbox.js"></script>{% endblock scripts %}`
  am Ende der Datei (exaktes Muster wie `templates/budget/index.twig`).

## Fehlerbehandlung

- Fetch-Netzwerkfehler (z. B. Server nicht erreichbar) und nicht-JSON-parsebare
  Antworten → identische Inline-Meldung "Verbindung zum Server fehlgeschlagen.
  Bitte versuche es erneut.", kein Reload, keine Daten verloren.

## Tests

TDD, Erweiterung von `tests/Feature/ProfileMailboxFeatureTest.php`:

1. `testMailboxConnection()` mit `Accept: application/json` liefert bei gültiger
   Verbindung `200 {success:true, ...}`.
2. `testMailboxConnection()` mit `Accept: application/json` liefert bei
   Validierungsfehler `422 {success:false, ...}` und setzt `mailbox_form_old` nicht.
3. `testMailboxConnection()` mit `Accept: application/json` liefert beim
   SSRF-geblockten Host `200 {success:false, ...}` mit der bestehenden generischen
   Meldung.
4. `updateMailbox()` mit `Accept: application/json` liefert bei Erfolg
   `200 {success:true, ...}` und persistiert wie gehabt.
5. `updateMailbox()` mit `Accept: application/json` liefert bei Validierungsfehler
   `422 {success:false, ...}` ohne Persistierung.
6. Bestehende 13 Tests in `ProfileMailboxFeatureTest.php` bleiben unverändert grün
   (kein `Accept`-Header gesetzt → alter Redirect-Pfad).

Frontend-Verifikation: echter Browser-Durchlauf (Playwright), da UI-Interaktionsänderung
(Projektregel für Frontend-Änderungen) — Verbindungstest ohne Reload, Passwort bleibt
im Feld, fehlgeschlagenes Speichern ohne Datenverlust, erfolgreiches Speichern mit
Reload.
