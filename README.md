

## Database Migration
./vendor/bin/phinx migrate

## Development Seed Data

For local development and feature validation, the project provides a Dev-only seed command.

### Security Guards

- Seeding is only allowed when `APP_ENV` is set to `development`, `dev`, or `local`.
- `ALLOW_DEV_SEED=1` must be set explicitly.
- Without both conditions, the seed command aborts.

### Run Seed Data

Recommended with DDEV:

```bash
ddev exec APP_ENV=development ALLOW_DEV_SEED=1 php bin/dev_seed.php --mode=reset-and-seed --years=3 --seed=20260321
```

Alternative (composer script):

```bash
ddev exec APP_ENV=development ALLOW_DEV_SEED=1 composer seed:dev -- --mode=append --years=3 --seed=20260321
```

Available modes:

- `append`: add additional seed data.
- `reset-and-seed`: truncate seed-relevant tables and create a fresh dataset (Dev only).

### Seed Report Credentials

The seed report now includes `credentials_by_role` with one demo login per role:

- Admin
- Vorstand
- Chorleitung
- Stimmvertretung
- Ersatzvertretung
- Mitglied

Each entry contains `role`, `email`, `password_plain`, and `user_id`.
These credentials are generated for Dev-only workflows and must never be used in production.

## SMTP Configuration via ENV

SMTP settings are configured via environment variables and are no longer managed in the Stammdaten/App-Einstellungen UI.

Required/available variables:

- `SMTP_HOST` (Dev default: `mailhog`)
- `SMTP_PORT` (Dev default: `1025`)
- `SMTP_AUTH` (`1/0`, `true/false`; default `0` in Dev)
- `SMTP_USERNAME` (typically required in production)
- `SMTP_PASSWORD` (required in production)
- `SMTP_ENCRYPTION` (`tls`, `ssl`, `none`; Dev default: `none`)
- `SMTP_FROM_EMAIL` (Dev default: `noreply@chor.local`)
- `SMTP_FROM_NAME` (Dev default: `Chor-Manager`)

Example for local Mailhog setups:

```bash
SMTP_HOST=mailhog
SMTP_PORT=1025
SMTP_AUTH=0
SMTP_ENCRYPTION=none
SMTP_FROM_EMAIL=noreply@chor.local
SMTP_FROM_NAME="Chor-Manager"
```

# Deployment

## Docker

To deploy the application using Docker:

1. Build and run the containers:
   ```bash
   docker-compose up --build
   ```

2. The application will be available at http://localhost

## Installation without Docker

The application can be run as a traditional PHP application with Nginx or Apache.

### Requirements

- PHP 8.5
- Composer 2
- Node.js 24+ and npm
- MySQL or MariaDB
- Web server with PHP-FPM or Apache with rewrite support

Required PHP extensions:

- mbstring
- pdo_mysql
- gd
- zip
- bcmath

### 1. Clone the project

```bash
git clone <REPOSITORY-URL>
cd ChorManager
```

### 2. Install dependencies

```bash
npm ci --omit=dev
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
php bin/copy-assets.php
```

### 3. Create the configuration

```bash
cp .env.example .env
```

Example of the most important values in `.env`:

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

Note: The application should currently be able to reach the database via the default port `3306`.

### 4. Run database migrations

```bash
php vendor/bin/phinx migrate
```

### 5. Configure the web server

The web root must point to the `public` directory.

Example Nginx configuration:

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

### 6. Create the first administrator account

After the first start, an administrator account can be created via `/setup`.

### Notes

- For production use, the application should only be served over HTTPS.
- Frontend assets from npm packages are copied to `public/vendor` via `bin/copy-assets.php`.
- After `npm ci`, run `php bin/copy-assets.php` whenever frontend packages are updated.
- If `composer install` is run with scripts enabled, no frontend assets are copied automatically:

```bash
php bin/copy-assets.php
```

