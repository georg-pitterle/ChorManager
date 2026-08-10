
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


## Webmail-Integration (Tachyon)

Pro Benutzer konfigurierbarer IMAP-Webmail-Zugang via [Tachyon](https://github.com/kimusan/Tachyon), eingebettet unter `/webmail`. Nach Konfiguration im Benutzerprofil (`/profile`) öffnet ein Klick die Inbox ohne zweiten Login-Dialog — ChorManager stellt ein kurzlebiges, signiertes Token aus, das der Webmail-Container automatisch konsumiert. Ein Ungelesen-Badge in der Navigation zeigt die Anzahl ungelesener Nachrichten. Nachrichteninhalte werden niemals in der ChorManager-Datenbank gespeichert.

Tachyon ist der gepflegte Fork des eingestellten SnappyMail; Migrationsentscheidungen stehen in `docs/superpowers/specs/2026-08-09-tachyon-migration-design.md`.

### ENV-Variablen

- `MAIL_CREDENTIAL_KEY` — Base64-kodierter 32-Byte-Schlüssel; verschlüsselt gespeicherte IMAP-Passwörter in der Datenbank (symmetrisch, libsodium). Generierung: `php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"`
- `WEBMAIL_SSO_SECRET` — Separater Base64-kodierter 32-Byte-Schlüssel; verschlüsselt den kurzlebigen Auto-Login-Token für das Tachyon-Plugin. **Muss identisch** in ChorManagers `.env` und im Webmail-Container gesetzt sein (siehe `.ddev/.env.webmail` für das lokale Dev-Wiring). Gleiche Generierung wie `MAIL_CREDENTIAL_KEY`. Darf nie gleich `MAIL_CREDENTIAL_KEY` sein.
- `WEBMAIL_UPLOAD_MAX_SIZE` (Dev-Standard: `25M`) — PHP `upload_max_filesize` im Webmail-Container
- `WEBMAIL_MEMORY_LIMIT` (Dev-Standard: `128M`) — PHP `memory_limit` im Webmail-Container

Beide Credential-Variablen sind in `.env.example` bewusst leer gelassen; echte Werte gehören ausschließlich in `.env` bzw. `.ddev/.env.webmail` (gitignored).

### Infrastruktur (DDEV / lokal)

Der Webmail-Container läuft als DDEV-Add-on-Service (`.ddev/docker-compose.webmail.yaml`, Image `ghcr.io/kimusan/tachyon:v3.2.2`). DDEV routet `/webmail/` via `.ddev/nginx_full/nginx-site.conf` per Reverse-Proxy an den Container, `/tachyon/` liefert dessen statische Assets. Das Auto-Login-Plugin liegt in `.ddev/webmail-plugins/chormanager-sso/` und wird beim Container-Start automatisch aktiviert. Details stehen direkt in diesen Dateien.

**Wichtig:** DDEV liest beim `docker compose`-Interpolation `${VAR}` **nicht** die project-eigene `.env`. `WEBMAIL_SSO_SECRET` wird daher über `.ddev/.env.webmail` (gitignored) in den Container gebracht. Diese Datei muss lokal angelegt werden; Vorlage: `.env.example`.

### Produktiv-Deployment

Die DDEV-Konfiguration (`.ddev/docker-compose.webmail.yaml`, nginx add-on) ist **ausschließlich für lokale Entwicklung**. Für Staging und Produktion muss ein eigener Webmail-Service in die produktive `docker-compose.yml` eingetragen und über den zuständigen Reverse-Proxy auf `/webmail/` geroutet werden. 

### Secret Rotation

**`MAIL_CREDENTIAL_KEY`**: Der Schlüssel lässt sich ohne Datenverlust tauschen. Gespeicherte Werte tragen die Kennung des Schlüssels, mit dem sie verschlüsselt wurden (`v2:<keyId>:<base64>`), sodass ein Rotationslauf sie gezielt neu verschlüsseln kann.

**Wichtig bei Kompromittierung:** Wer den Schlüssel *und* einen Datenbank-Dump besitzt, kennt die IMAP-Passwörter bereits im Klartext. Ein Schlüsseltausch schützt rückwirkend nichts. Reihenfolge im Ernstfall:

1. **IMAP-Passwörter beim Mailserver ändern** — das sind die kompromittierten Geheimnisse.
2. Schlüssel rotieren (siehe unten).
3. Alte Backups bewerten: Jeder Dump, der vor der Rotation gezogen wurde, ist mit dem alten Schlüssel lesbar. Die Metadatei jedes Backups nennt unter `mail_key_id`, welcher Schlüssel dazugehört.

Ablauf der Rotation:

```bash
# 1. Neuen Schlüssel erzeugen
ddev php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;"

# 2. In .env: neuen Wert als MAIL_CREDENTIAL_KEY, bisherigen als MAIL_CREDENTIAL_KEY_PREVIOUS

# 3. Probelauf (schreibt nichts)
ddev php bin/rotate_mail_key.php --dry-run

# 4. Echter Lauf
ddev php bin/rotate_mail_key.php

# 5. MAIL_CREDENTIAL_KEY_PREVIOUS wieder aus .env entfernen
```

Der Lauf ist idempotent — bereits migrierte Datensätze werden übersprungen. Meldet er `fehlgeschlagen: n > 0`, sind `n` Datensätze mit keinem der beiden Schlüssel lesbar; die betroffenen Benutzer müssen ihr IMAP-Passwort im Profil (`/profile`) neu speichern. Details stehen in den `mail_credential.rotate.failed`-Log-Events.

**Restore eines alten Backups:** Enthält die Metadatei eine `mail_key_id`, die nicht zum aktuellen Schlüssel passt, muss der damalige Schlüssel als `MAIL_CREDENTIAL_KEY_PREVIOUS` gesetzt und nach dem Restore einmal `bin/rotate_mail_key.php` ausgeführt werden.

**`WEBMAIL_SSO_SECRET`**: Niedrigeres Risiko — der Schlüssel sichert nur kurzlebige (45-Sekunden-TTL) Token ohne gespeicherten Zustand. Eine Rotation macht maximal in-flight-Tokens ungültig; betroffene Benutzer landen auf dem normalen Tachyon-Login-Screen (kein Datenverlust). Der neue Schlüssel muss **gleichzeitig** in ChorManagers `.env` und in `.ddev/.env.webmail` (bzw. dem Produktiv-Container-Env) gesetzt werden — ein Mismatch schlägt fail-closed.


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

