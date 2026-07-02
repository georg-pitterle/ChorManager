
# ChorManager

ChorManager ist eine webbasierte Verwaltungsplattform für Chöre und Vereine. Die Anwendung deckt zentrale
Organisationsprozesse ab, von Mitglieder- und Rollenverwaltung bis zu Terminen, Anwesenheiten, Finanzen,
Newslettern und auswertbaren Berichten.

## Wichtigste Features

- Mitglieder-, Rollen- und Rechteverwaltung für typische Vereinsrollen.
- Termin- und Veranstaltungsmanagement inklusive Anwesenheitserfassung.
- Finanz- und Auswertungsfunktionen für den laufenden Vereinsbetrieb.
- Newsletter- und Kommunikationsfunktionen für interne Abläufe.
- Entwicklungsfreundliche Dev-Seed-Daten für reproduzierbare Testszenarien.
- SMTP-Konfiguration über Umgebungsvariablen statt UI-Settings.

## Schnellstart (DDEV empfohlen)

1. DDEV starten:

```bash
ddev start
```

2. Abhängigkeiten installieren:

```bash
ddev npm ci --omit=dev
ddev composer install
```

`composer install` kopiert die Frontend-Assets über den `post-install-cmd`-Hook automatisch nach `public/vendor` (siehe `bin/copy-assets.php`). `npm ci` muss daher vorher gelaufen sein.

3. Konfiguration anlegen:

```bash
cp .env.example .env
```

4. Datenbank migrieren:

```bash
ddev php vendor/bin/phinx migrate
```

5. Anwendung im Browser öffnen (URL wird von DDEV ausgegeben).

## Datenbank-Migration

```bash
ddev php vendor/bin/phinx migrate
```

## Entwicklungs-Seed-Daten

Für lokale Entwicklung und Feature-Validierung gibt es einen Dev-only Seed-Befehl.

### Sicherheitsregeln

- Seeding ist nur erlaubt, wenn `APP_ENV` auf `development`, `dev` oder `local` steht.
- `ALLOW_DEV_SEED=1` muss explizit gesetzt sein.
- Fehlt eine der Bedingungen, wird der Seed-Lauf abgebrochen.

### Seed ausführen

Empfohlen mit DDEV:

```bash
ddev exec APP_ENV=development ALLOW_DEV_SEED=1 php bin/dev_seed.php --mode=reset-and-seed --years=3 --seed=20260321
```

Alternative (Composer-Skript):

```bash
ddev exec APP_ENV=development ALLOW_DEV_SEED=1 composer seed:dev -- --mode=append --years=3 --seed=20260321
```

Verfügbare Modi:

- `append`: fügt weitere Seed-Daten hinzu.
- `reset-and-seed`: leert seed-relevante Tabellen und erzeugt einen frischen Datensatz (nur Dev).

### Seed-Report-Zugangsdaten

Der Seed-Report enthält `credentials_by_role` mit einem Demo-Login je Rolle:

- Admin
- Vorstand
- Chorleitung
- Stimmvertretung
- Ersatzvertretung
- Mitglied

Jeder Eintrag enthält `role`, `email`, `password_plain` und `user_id`.
Diese Zugangsdaten sind ausschließlich für Dev-Workflows gedacht und dürfen nie in Produktion genutzt werden.

## SMTP-Konfiguration per ENV

SMTP-Einstellungen werden über Umgebungsvariablen gesetzt und nicht mehr in Stammdaten/App-Einstellungen gepflegt.

Verfügbare Variablen:

- `SMTP_HOST` (Dev-Standard: ``)
- `SMTP_PORT` (Dev-Standard: ``)
- `SMTP_AUTH` (`1/0`, `true/false`; in Dev standardmäßig `0`)
- `SMTP_USERNAME` (in Produktion typischerweise erforderlich)
- `SMTP_PASSWORD` (in Produktion erforderlich)
- `SMTP_ENCRYPTION` (`tls`, `ssl`, `none`; Dev-Standard: `none`)
- `SMTP_FROM_EMAIL` (Dev-Standard: `noreply@chor.local`)
- `SMTP_FROM_NAME` (Dev-Standard: `Chor-Manager`)


## SnappyMail / Webmail-Integration

Pro Benutzer konfigurierbarer IMAP-Webmail-Zugang via [SnappyMail](https://snappymail.eu/), eingebettet unter `/webmail`. Nach Konfiguration im Benutzerprofil (`/profile`) öffnet ein Klick die Inbox ohne zweiten Login-Dialog — ChorManager stellt ein kurzlebiges, signiertes Token aus, das der SnappyMail-Container automatisch konsumiert. Ein Ungelesen-Badge in der Navigation zeigt die Anzahl ungelesener Nachrichten. Nachrichteninhalte werden niemals in der ChorManager-Datenbank gespeichert.

Vollständige Spezifikation: `docs/superpowers/specs/2026-05-12-snappymail-integration-plan.md`

### ENV-Variablen

- `MAIL_CREDENTIAL_KEY` — Base64-kodierter 32-Byte-Schlüssel; verschlüsselt gespeicherte IMAP-Passwörter in der Datenbank (symmetrisch, libsodium). Generierung: `php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"`
- `SNAPPYMAIL_SSO_SECRET` — Separater Base64-kodierter 32-Byte-Schlüssel; signiert/verschlüsselt den kurzlebigen Auto-Login-Token für das SnappyMail-Plugin. **Muss identisch** in ChorManagers `.env` und im SnappyMail-Container gesetzt sein (siehe `.ddev/.env.snappymail` für das lokale Dev-Wiring). Gleiche Generierung wie `MAIL_CREDENTIAL_KEY`. Darf nie gleich `MAIL_CREDENTIAL_KEY` sein.
- `SNAPPYMAIL_UPLOAD_MAX_SIZE` (Dev-Standard: `25M`) — PHP `upload_max_filesize` im SnappyMail-Container
- `SNAPPYMAIL_MEMORY_LIMIT` (Dev-Standard: `128M`) — PHP `memory_limit` im SnappyMail-Container

Beide Credential-Variablen sind in `.env.example` bewusst leer gelassen; echte Werte gehören ausschließlich in `.env` bzw. `.ddev/.env.snappymail` (gitignored).

### Infrastruktur (DDEV / lokal)

Der SnappyMail-Container läuft als DDEV-Add-on-Service (`.ddev/docker-compose.snappymail.yaml`, Image `djmaze/snappymail:v2.38.2`). DDEV routet `/webmail/` via `.ddev/nginx_full/nginx-site.conf` per Reverse-Proxy an den Container. Das Auto-Login-Plugin liegt in `.ddev/snappymail-plugins/chormanager-sso/` und wird beim Container-Start automatisch aktiviert. Details stehen direkt in diesen Dateien.

**Wichtig:** DDEV liest beim `docker compose`-Interpolation `${VAR}` **nicht** die project-eigene `.env`. `SNAPPYMAIL_SSO_SECRET` wird daher über `.ddev/.env.snappymail` (gitignored) in den Container gebracht. Diese Datei muss lokal angelegt werden; Vorlage: `.env.example`.

### Produktiv-Deployment

Die DDEV-Konfiguration (`.ddev/docker-compose.snappymail.yaml`, nginx add-on) ist **ausschließlich für lokale Entwicklung**. Für Staging und Produktion muss ein eigener SnappyMail-Service in die produktive `docker-compose.yml` eingetragen und über den zuständigen Reverse-Proxy auf `/webmail/` geroutet werden. Dieser Schritt ist **vor dem Go-Live dieses Features zwingend erforderlich** — das Feature ist noch nicht produktionsbereit, solange kein Produktiv-Container existiert.

### Secret Rotation

**`MAIL_CREDENTIAL_KEY`**: Rotation dieses Schlüssels macht alle bestehenden `imap_password_enc`-Einträge dauerhaft unleserlich (der Crypto-Service ist fail-closed — er wirft eine Exception, statt still zu korrumpieren). Es gibt keine automatische Re-Verschlüsselung. Nach einer Rotation müssen **alle Benutzer ihr IMAP-Passwort** im Profil (`/profile`) neu speichern.

**`SNAPPYMAIL_SSO_SECRET`**: Niedrigeres Risiko — der Schlüssel sichert nur kurzlebige (45-Sekunden-TTL) Token ohne gespeicherten Zustand. Eine Rotation macht maximal in-flight-Tokens ungültig; betroffene Benutzer landen auf dem normalen SnappyMail-Login-Screen (kein Datenverlust). Der neue Schlüssel muss **gleichzeitig** in ChorManagers `.env` und in `.ddev/.env.snappymail` (bzw. dem Produktiv-Container-Env) gesetzt werden — ein Mismatch schlägt fail-closed.

### Monitoring / Log-Events

Diese Feature-Komponenten loggen via `Psr\Log\LoggerInterface` (JSON zu stderr). Das SnappyMail-Plugin loggt über SnappyMails eigenem Logger (kein PSR). Keines dieser Events erfordert einen Alarm — es handelt sich ausschließlich um benutzerseitig konfigurierbare Mailbox-Zugänge, kein gemeinsam genutzter kritischer Pfad.

| Event-Key | Quelle | Bedeutung |
|-----------|--------|-----------|
| `mail_credential.decrypt.failed` | `MailCredentialCryptoService` | Gespeichertes IMAP-Passwort konnte nicht entschlüsselt werden (falscher Key, korrupte Daten). Erwartet gelegentlich nach Key-Rotation. |
| `mail_account.update.failed` | `ProfileController` | DB-Fehler beim Speichern der Mailbox-Einstellungen im Profil. |
| `webmail.start.decrypt_failed` | `WebmailController` | SSO-Start fehlgeschlagen weil Passwort nicht entschlüsselt werden konnte (→ Benutzer zum Profil weitergeleitet). |
| `webmail.start.redirected` | `WebmailController` | SSO-Token ausgestellt, Benutzer zu SnappyMail weitergeleitet. |
| `mail_badge.refresh.failed` | `MailBadgeService` | IMAP-STATUS-Abfrage fehlgeschlagen (Netzwerk, Auth, falscher Host). Erwartet häufig wenn Mailbox unerreichbar. |
| `mail_badge.middleware.failed` | `MailBadgeRefreshMiddleware` | Unerwarteter Fehler im Middleware-Wrapper (sollte nicht vorkommen, da `MailBadgeService::refresh()` intern bereits alle Fehler fängt). |
| `chormanager_sso.missing_token` | SnappyMail-Plugin | SSO-Request ohne Token-Parameter. |
| `chormanager_sso.misconfigured` | SnappyMail-Plugin | `SNAPPYMAIL_SSO_SECRET` fehlt oder hat falsche Länge im Container. |
| `chormanager_sso.invalid_token` | SnappyMail-Plugin | Token nicht entschlüsselbar, fehlende Felder oder ungültige JTI. |
| `chormanager_sso.expired` | SnappyMail-Plugin | Token-TTL abgelaufen (45 Sekunden). Erwartet bei langsamem Netzwerk oder mehrfachem Klick. |
| `chormanager_sso.replay` | SnappyMail-Plugin | Token bereits verwendet (Replay-Schutz aktiv). Erwartet bei Browser-Back/Reload nach SSO. |
| `chormanager_sso.login_attempted` | SnappyMail-Plugin | `LoginProcess()` aufgerufen (Erfolg oder IMAP-seitiger Fehler folgt im SnappyMail-Log). |
| `chormanager_sso.login_failed` | SnappyMail-Plugin | Exception in `LoginProcess()`. |
| `chormanager_sso.unexpected_error` | SnappyMail-Plugin | Unerwarteter Fehler im Plugin-Hook. |

### Rollout-Checkliste

#### Dev (abgeschlossen mit diesem Branch)

- [x] DDEV-Add-on-Service (`.ddev/docker-compose.snappymail.yaml`) läuft
- [x] `/webmail/` per nginx geroutet (`.ddev/nginx_full/nginx-site.conf`)
- [x] Plugin aktiviert (`.ddev/snappymail-plugins/chormanager-sso/`)
- [x] `MAIL_CREDENTIAL_KEY` und `SNAPPYMAIL_SSO_SECRET` in `.ddev/.env.snappymail` gesetzt
- [x] Migration gelaufen (`user_mail_accounts`-Tabelle vorhanden)
- [x] Dev-Seed enthält `user_mail_accounts`-Einträge

#### Staging

- [ ] Separaten SnappyMail-Service in Staging-`docker-compose.yml` eintragen und `/webmail/` routen
- [ ] `MAIL_CREDENTIAL_KEY` und `SNAPPYMAIL_SSO_SECRET` frisch für Staging generieren (nie Dev-Werte wiederverwenden)
- [ ] Reales (minimales) IMAP-Testpostfach aufsetzen
- [ ] Mailbox-Einstellungen für Testbenutzer unter `/profile` konfigurieren
- [ ] Vollständigen Auto-Login-Flow testen: Klick → SnappyMail-Inbox ohne zweiten Login-Dialog
- [ ] Badge zeigt reale Ungelesen-Zahl aus dem Testpostfach
- [ ] Negativpfad-Sicherheitstests: abgelaufenes Token → Redirect zu Login; wiederverwendetes Token → Replay-Fehler; manipuliertes Token → Decrypt-Fehler
- [ ] Phinx-Migration auf Staging-DB gelaufen

#### Produktion

- [ ] Produktiv-SnappyMail-Service in `docker-compose.yml` eintragen (neues Kapitel in Deployment-Doku)
- [ ] `MAIL_CREDENTIAL_KEY` und `SNAPPYMAIL_SSO_SECRET` frisch für Produktion generieren
- [ ] `ALLOW_DEV_SEED=0` bzw. nicht gesetzt (Dev-Seed-Zugangsdaten dürfen nie in die Produktionsdatenbank)
- [ ] Feature nach Staging-Abnahme für ausgewählte Benutzer freischalten (gestaffeltes Rollout gemäß Planempfehlung)
- [ ] Migrations-Rollout auf Produktionsdatenbank abgeschlossen


## Deployment

### Docker

```bash
docker-compose up --build
```

Danach ist die Anwendung unter http://localhost erreichbar.

### Installation ohne Docker

Die Anwendung kann auch klassisch mit Nginx oder Apache betrieben werden.

#### Voraussetzungen

- PHP 8.5
- Composer 2
- Node.js 24+ und npm
- MySQL oder MariaDB
- Webserver mit PHP-FPM oder Apache (Rewrite-Unterstützung)

Erforderliche PHP-Erweiterungen:

- mbstring
- pdo_mysql
- gd
- zip
- bcmath

#### 1. Projekt klonen

```bash
git clone <REPOSITORY-URL>
cd ChorManager
```

#### 2. Abhängigkeiten installieren

```bash
npm ci --omit=dev
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
php bin/copy-assets.php
```

#### 3. Konfiguration anlegen

```bash
cp .env.example .env
```

Beispiel für zentrale `.env`-Werte:

```dotenv
APP_ENV=production
APP_TIMEZONE=Europe/Vienna

DB_HOST=127.0.0.1
DB_DATABASE=chormanager
DB_USERNAME=chormanager
DB_PASSWORD=change_me
DB_PORT=3306

SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_AUTH=1
SMTP_USERNAME=smtp-user
SMTP_PASSWORD=change_me
SMTP_ENCRYPTION=tls
SMTP_FROM_EMAIL=noreply@example.com
SMTP_FROM_NAME=Chor-Manager
```

Hinweis: Standardmäßig wird Port `3306` für die Datenbank verwendet.

#### 4. Datenbank migrieren

```bash
php vendor/bin/phinx migrate
```

#### 5. Webserver konfigurieren

Das Web-Root muss auf das Verzeichnis `public` zeigen.

Beispiel für Nginx:

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/chormanager/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

#### 6. Ersten Administrator anlegen

Nach dem ersten Start kann unter `/setup` ein Administrator-Account erstellt werden.

## Hinweise

- In Produktion sollte die Anwendung ausschließlich über HTTPS bereitgestellt werden.
- Frontend-Assets aus npm-Paketen werden mit `bin/copy-assets.php` nach `public/vendor` kopiert.
- `composer install`/`composer update` lösen das automatisch über den `post-install-cmd`/`post-update-cmd`-Hook aus (Voraussetzung: `npm ci` ist vorher gelaufen). Fehlt `node_modules`, wird der Kopiervorgang übersprungen statt den Composer-Lauf abzubrechen.
- Da der Produktions-Setup-Befehl oben bewusst `--no-scripts` verwendet, muss dort weiterhin `php bin/copy-assets.php` explizit ausgeführt werden.
- Nach `npm ci` sollte bei Paket-Änderungen erneut `php bin/copy-assets.php` ausgeführt werden (bzw. `composer install`/`composer update` erneut laufen lassen).

