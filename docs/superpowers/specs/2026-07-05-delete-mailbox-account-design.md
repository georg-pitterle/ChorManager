# Mailbox-Zugang entfernen Design

Datum: 2026-07-05
Status: Freigegeben für Planerstellung

## Ziel

Im Profil lässt sich ein gespeicherter Mailbox-Zugang (IMAP/SMTP-Zugangsdaten
sowie die optionale externe Webmail-URL) bisher nicht entfernen — nur
überschreiben. Ein eigener Button macht das vollständige Löschen möglich.

## Festgelegte Entscheidungen

1. Löscht die komplette `user_mail_accounts`-Zeile: IMAP/SMTP-Zugangsdaten UND
   die externe Webmail-URL zusammen. Kein Teil-Löschen.
2. Eigenes, separates Formular unterhalb des bestehenden Mailbox-Formulars —
   keine Integration in das asynchrone Save/Test-Formular
   (`public/js/profile-mailbox.js`), keine neue JS-Datei.
3. Bestätigung über das bereits bestehende globale `data-confirm`-Pattern in
   `public/js/common.js` (Capture-Phase-`submit`-Listener, zeigt
   `confirm()` und bricht bei Ablehnung via `preventDefault()` ab).
4. Synchrone Übertragung (POST + Redirect + Session-Flash), kein JSON-Zweig —
   analog zu anderen Lösch-Aktionen im Projekt (z. B. Attachment-Löschungen).
5. Kein Schema-Change, keine Migration, kein Seed-Update.

## Architektur

### 1. Backend (`src/Controllers/ProfileController.php`)

Neue Methode `deleteMailbox()`:

```php
public function deleteMailbox(Request $request, Response $response): Response
{
    $userId = (int)$_SESSION['user_id'];

    $account = UserMailAccount::where('user_id', $userId)->first();
    if ($account) {
        $account->delete();
        $_SESSION['success'] = 'Mailbox-Zugang wurde entfernt.';
    }

    return $response->withHeader('Location', '/profile')->withStatus(302);
}
```

Kein Account vorhanden → stiller No-op (kein Fehler, kein Erfolgstext),
redirect wie gehabt. Der Button ist ohnehin nur sichtbar, wenn ein Account
existiert (siehe Template), daher ist dieser Zweig defensive Programmierung,
kein regulärer Nutzerpfad.

### 2. Route (`src/Routes.php`)

Nach der bestehenden Zeile

```php
$group->post('/profile/mailbox/test', [ProfileController::class, 'testMailboxConnection']);
```

ergänzen:

```php
$group->post('/profile/mailbox/delete', [ProfileController::class, 'deleteMailbox']);
```

Gleiche Route-Gruppe wie die anderen `/profile/mailbox*`-Routen (kein
zusätzliches Middleware-Gate — Profil-Routen sind bereits über die
umgebende Auth-Middleware geschützt).

### 3. Template (`templates/profile/index.twig`)

Neues, eigenständiges Formular direkt nach dem schließenden `</form>` des
bestehenden Mailbox-Formulars (vor dem SSO-/externen-Webmail-Link-Block),
nur gerendert wenn `has_saved_account` true ist:

```twig
{% if has_saved_account %}
    <form action="/profile/mailbox/delete"
          method="post"
          class="mt-3"
          data-confirm="Mailbox-Zugang wirklich entfernen? Diese Aktion kann nicht rückgängig gemacht werden.">
        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash"></i> Zugang entfernen
            </button>
        </div>
    </form>
{% endif %}
```

`common.js` fügt dem Formular automatisch das CSRF-Hidden-Field hinzu (über
den bereits bestehenden `ensureCsrfField()`-Mechanismus, der auf jedes
`form[method="post"]` ohne eigenes `_csrf`-Feld angewendet wird), und der
`data-confirm`-Listener zeigt vor dem Absenden einen Bestätigungsdialog.

## Effekt nach Löschung (automatisch, kein Zusatzcode)

- `ProfileController::index()` liefert `mail_account = null` (da
  `$user->mailAccount` nicht mehr existiert) → Formular rendert komplett
  leer, `has_saved_account` wird `false`.
- Mail-Badge in der Navigation (`src/Dependencies.php`) verschwindet, da der
  `UserMailAccount`-Lookup keinen Treffer mehr liefert.
- Der SSO-Webmail-Button im Profil verschwindet, da `webmail_available`
  (`$mailAccount !== null && ...`) `false` wird.

## Fehlerbehandlung

- Kein Account vorhanden → No-op, kein Fehler (siehe oben).
- Kein DB-Fehler-Pfad vorgesehen: `Model::delete()` auf eine bereits
  geladene, existierende Instanz ist hier nicht sinnvoll fehlschlagend genug,
  um einen eigenen try/catch zu rechtfertigen (kein anderer
  Lösch-Endpunkt im Projekt behandelt das gesondert).

## Tests

TDD, Erweiterung von `tests/Feature/ProfileMailboxFeatureTest.php`:

1. `deleteMailbox()` löscht einen bestehenden Account und setzt die
   Erfolgsmeldung.
2. `deleteMailbox()` ohne bestehenden Account: redirectet ohne Fehler, kein
   Success-Flash (No-op).
3. Nach dem Löschen liefert `index()` `mail_account = null` und
   `has_saved_account = false` (Regressionsschutz für die Template-Logik).

Template-Test (neu oder Erweiterung von `WebmailFeatureFlagTest.php` /
`ProfileMailboxAsyncTemplateTest.php`):

4. Das Lösch-Formular mit `data-confirm` und der Route
   `/profile/mailbox/delete` ist im Template vorhanden.
