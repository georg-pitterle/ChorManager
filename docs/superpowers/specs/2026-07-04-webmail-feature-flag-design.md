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
3. Mail-Badge (Ungelesen-Zähler in der Navbar) bleibt bei abgeschaltetem Flag sichtbar,
   aber ohne Klick-Ziel (reiner Indikator). Der Zähler kommt direkt via IMAP und braucht
   SnappyMail nicht.
4. Kein DB-Schema-Change, keine Migration, kein Seed-Update.

## Architektur

Vier Berührungspunkte, alle über das bestehende `modules`-Settings-Array gesteuert:

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

### 3. Profil-Button (`templates/profile/index.twig`)

Der "Webmail öffnen"-Button rendert nur bei
`settings.modules.webmail and webmail_available`. Das Twig-Global `settings` existiert
bereits (Dependencies.php). Kein Controller-Umbau: ProfileController ist autowired,
Settings-Array-Injection würde eine DI-Definition erfordern; der Template-Check ist
konsistent zum Route-Gate.

Die Mailbox-Konfigurationssektion im Profil bleibt unverändert sichtbar — IMAP-Zugang
wird weiterhin für den Mail-Badge genutzt.

### 4. Mail-Badge (`templates/partials/navigation/user_menu.twig`)

- Flag an: wie bisher — Badge als Formular-Button, Klick öffnet Webmail via SSO.
- Flag aus: Badge als reiner Indikator (Umschlag-Icon + Zähler,
  `title="Ungelesene Nachrichten"`), kein `<form>`, kein Klick-Ziel.

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

## Fehlerbehandlung

- Flag aus + direkter POST auf `/profile/webmail/start` → 404 (Route existiert nicht).
- Fehlende Variable in `.env` → Default `false`, kein Fehler.

## Tests (TDD, Stil wie bestehende Feature-Tests)

1. `Settings.php` enthält `modules.webmail`-Eintrag mit `FEATURE_WEBMAIL`-Env-Read
   und Default `false`.
2. `Routes.php` registriert die Webmail-Route nur innerhalb des Flag-Gates.
3. `templates/profile/index.twig` prüft `settings.modules.webmail`.
4. `templates/partials/navigation/user_menu.twig` rendert den Badge-Indikator ohne
   Formular, wenn das Flag aus ist, und mit Formular, wenn es an ist.
