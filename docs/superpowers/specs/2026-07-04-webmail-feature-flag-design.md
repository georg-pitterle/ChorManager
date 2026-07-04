# Webmail-Feature-Flag Design (SnappyMail optional)

Datum: 2026-07-04
Status: Freigegeben für Planerstellung

## Ziel

ChorManager funktioniert vollständig ohne SnappyMail-Container. Die Webmail-Integration
wird per `.env`-Flag `FEATURE_WEBMAIL` zu- oder abgeschaltet. Bei abgeschaltetem Flag ist
der SnappyMail-Container gar nicht nötig — z. B. beim Betrieb mit plain PHP ohne
Container-Stack.

## Festgelegte Entscheidungen

1. Steuerung über `.env`-Variable `FEATURE_WEBMAIL` (bestehendes `FEATURE_*`-Muster).
2. Default: `false` — konsistent mit `FEATURE_SHEET_ARCHIVE` und `FEATURE_BUDGET`.
   Bestehende Installationen mit Webmail müssen nach dem Update `FEATURE_WEBMAIL=true` setzen.
3. Mail-Badge (Ungelesen-Zähler in der Navbar) bleibt bei abgeschaltetem Flag sichtbar.
   Der Zähler kommt direkt via IMAP und braucht SnappyMail nicht.
4. Bei abgeschaltetem Flag kann der Benutzer im Profil optional die URL eines externen
   Webmail-Clients hinterlegen. Ist sie gesetzt, verlinkt das Badge dorthin (neuer Tab);
   ist sie leer, ist das Badge ein reiner Indikator ohne Klick-Ziel.
5. Schema-Change: neue nullable Spalte `external_webmail_url` auf `user_mail_accounts`
   → Phinx-Migration + Seed-Update erforderlich.

## Architektur

Fünf Berührungspunkte, gesteuert über das bestehende `modules`-Settings-Array und die
neue Profil-Einstellung:

### 1. Settings (`src/Settings.php`)

Neuer Eintrag im `modules`-Array:

```php
'webmail' => EnvHelper::read('FEATURE_WEBMAIL', 'false') === 'true',
```

### 2. Route (`src/Routes.php`)

`POST /profile/webmail/start` wird nur registriert, wenn
`$settings['modules']['webmail']` wahr ist (gleiches Gate-Muster wie Budget- und
Sheet-Archive-Routen). Flag aus → 404. `WebmailController` und
`SnappymailSsoTokenService` bleiben unverändert; sie werden ohne Route schlicht nie
instanziiert.

### 3. Datenmodell: externe Webmail-URL

Neue nullable Spalte `external_webmail_url` (varchar 255) auf `user_mail_accounts`,
per Phinx-Migration. `UserMailAccount`-Model: Spalte in `$fillable` aufnehmen.

Validierung beim Speichern (ProfileController, Mailbox-Formular):

- leer erlaubt (Feature optional),
- sonst muss die URL mit `https://` oder `http://` beginnen und
  `filter_var(..., FILTER_VALIDATE_URL)` bestehen; ungültige Eingabe → Fehlermeldung,
  Formularwerte bleiben erhalten (bestehender `mailbox_form_old`-Mechanismus).

### 4. Profil (`templates/profile/index.twig`, ProfileController)

- Neues optionales Eingabefeld "Externe Webmail-URL" in der Mailbox-Sektion,
  nur gerendert wenn `settings.modules.webmail` aus ist (bei aktivem SnappyMail
  übernimmt SSO das Öffnen, das Feld wäre irreführend).
- "Webmail öffnen"-Button:
  - Flag an: wie bisher SSO-Formular, Bedingung `settings.modules.webmail and webmail_available`.
  - Flag aus + URL gesetzt: einfacher Link-Button auf die externe URL
    (`target="_blank" rel="noopener noreferrer"`).
- Das Twig-Global `settings` existiert bereits (Dependencies.php). Kein
  Controller-Umbau für das Flag; ProfileController liefert nur zusätzlich die
  gespeicherte externe URL an die View (steckt bereits im `mail_account`-Objekt).

Die Mailbox-Konfigurationssektion bleibt unverändert sichtbar — IMAP-Zugang wird
weiterhin für den Mail-Badge genutzt.

### 5. Mail-Badge (`templates/partials/navigation/user_menu.twig`)

- Flag an: wie bisher — Badge als Formular-Button, Klick öffnet Webmail via SSO.
- Flag aus + externe URL gesetzt: Badge als `<a>`-Link auf die externe URL
  (`target="_blank" rel="noopener noreferrer"`, `title="Postfach öffnen"`).
- Flag aus + keine URL: Badge als reiner Indikator (Umschlag-Icon + Zähler,
  `title="Ungelesene Nachrichten"`), kein Klick-Ziel.

Die externe URL wird dafür als Twig-Global neben `mail_badge_unseen_count`
bereitgestellt (gleicher Codepfad in Dependencies.php, der bereits den
`UserMailAccount` des eingeloggten Benutzers lädt).

## Dokumentation

- `.env.example`: `FEATURE_WEBMAIL` dokumentieren; Hinweis, dass SnappyMail-Container
  und `SNAPPYMAIL_SSO_SECRET` nur bei `true` gebraucht werden.
- `dist/.env.example`: analog für Produktion.
- `dist/README.md`: Hinweis, dass der `snappymail`-Service im Compose-Stack optional
  ist und bei `FEATURE_WEBMAIL=false` entfallen kann.

## Bewusst außerhalb des Scopes

- Entfernen/Optionalisieren des `snappymail`-Services in `docker-compose.prod.yml`
  selbst — Deployment-Entscheidung des Betreibers. Die App funktioniert mit Flag aus
  auch dann, wenn der Container läuft (er wird nur nicht verlinkt).
- Direktaufruf von `/webmail/` bei fehlendem Container ergibt einen Proxy-Fehler des
  Webservers (502) — kein App-Problem, da die App nie dorthin verlinkt.
- Kein Laufzeit-Toggle über Admin-UI (explizit `.env`-gesteuert gewünscht).

## Seed-Daten

`seedUserMailAccounts()` in `DevSeedService`: für einen Teil der gesetzten Accounts
eine realistische externe Webmail-URL setzen (z. B. `https://webmail.example.org/`),
damit der Badge-Link-Fall im Dev sofort testbar ist. Kein neuer Zähler nötig
(bestehender `user_mail_accounts`-Zähler deckt die Zeilen ab).

## Fehlerbehandlung

- Flag aus + direkter POST auf `/profile/webmail/start` → 404 (Route existiert nicht).
- Fehlende Variable in `.env` → Default `false`, kein Fehler.
- Ungültige externe URL beim Speichern → Fehlermeldung, keine Persistierung.

## Tests (TDD, Stil wie bestehende Feature-Tests)

1. `Settings.php` enthält `modules.webmail`-Eintrag mit `FEATURE_WEBMAIL`-Env-Read
   und Default `false`.
2. `Routes.php` registriert die Webmail-Route nur innerhalb des Flag-Gates.
3. `templates/profile/index.twig` prüft `settings.modules.webmail` und rendert das
   URL-Feld nur bei abgeschaltetem Flag.
4. `templates/partials/navigation/user_menu.twig` deckt alle drei Badge-Fälle ab:
   SSO-Formular (Flag an), externer Link (Flag aus + URL), reiner Indikator
   (Flag aus, keine URL).
5. Mailbox-Speichern: gültige externe URL wird persistiert, ungültige abgelehnt,
   leere löscht den Wert.
6. Migration vorhanden und `external_webmail_url` in `$fillable` des Models.
