
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
ddev php bin/copy-assets.php
```

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
- Nach `npm ci` sollte bei Paket-Änderungen erneut `php bin/copy-assets.php` ausgeführt werden.
- Wenn `composer install` mit aktivierten Scripts läuft, werden Frontend-Assets nicht automatisch kopiert.

