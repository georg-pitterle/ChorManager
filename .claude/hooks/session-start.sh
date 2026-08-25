#!/bin/bash
#
# SessionStart-Hook für Claude Code im Web.
#
# Der Container ist flüchtig: Das Repo wird bei jedem Sitzungsstart frisch
# geklont, installierte Pakete aus früheren Sitzungen sind weg. Ohne diesen Hook
# fehlt vor allem die Datenbank - PHPUnit-Feature-Tests und `phinx migrate`
# laufen dann nicht, und der Agent kann seine eigenen Änderungen nicht prüfen.
#
# Das Skript ist idempotent: Was schon da ist, wird übersprungen.
set -euo pipefail

# Lokale Entwicklung (DDEV) bringt Datenbank und Abhängigkeiten selbst mit.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
cd "$PROJECT_DIR"

DB_NAME="db"
DB_USER="db"
DB_PASS="db"
DB_HOST="127.0.0.1"
DB_PORT="3306"

log() { printf '[session-start] %s\n' "$1"; }

# ---------------------------------------------------------------- MariaDB ----
if ! command -v mariadbd >/dev/null 2>&1; then
    log "MariaDB installieren"
    # Der Paketindex im Image ist älter als die Spiegel; ohne update schlagen
    # die Downloads mit 404 fehl.
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mariadb-server
else
    log "MariaDB bereits installiert"
fi

if ! mariadb-admin ping >/dev/null 2>&1; then
    log "MariaDB starten"
    # Kein systemd im Container, also direkt über mariadbd-safe.
    install -d -o mysql -g mysql /var/run/mysqld
    nohup mariadbd-safe --user=mysql --skip-syslog >/var/log/mariadb-session-start.log 2>&1 &

    for _ in $(seq 1 60); do
        mariadb-admin ping >/dev/null 2>&1 && break
        sleep 1
    done

    if ! mariadb-admin ping >/dev/null 2>&1; then
        log "FEHLER: MariaDB ist nicht hochgekommen, siehe /var/log/mariadb-session-start.log"
        exit 1
    fi
fi
log "MariaDB läuft"

# Ohne die Zeitzonentabellen kennt MariaDB nur Offsets: benannte Zonen wie
# Europe/Vienna fallen auf +02:00 zurück und CONVERT_TZ() liefert NULL.
# DatabaseTimezoneFeatureTest fällt genau darüber.
if [ "$(mariadb -N -B -e 'SELECT COUNT(*) FROM mysql.time_zone_name;' 2>/dev/null || echo 0)" -lt 100 ]; then
    log "Zeitzonentabellen laden"
    mariadb-tzinfo-to-sql /usr/share/zoneinfo 2>/dev/null | mariadb mysql
fi

log "Datenbank und Benutzer sicherstellen"
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL

# -------------------------------------------------------------------- .env ----
# Tests lesen die Zugangsdaten über Dotenv aus .env, phinx.php dagegen über
# getenv() - beides muss gesetzt sein.
if [ ! -f .env ]; then
    log ".env aus .env.example erzeugen"
    sed -e "s|^DB_HOST=.*|DB_HOST=${DB_HOST}|" .env.example > .env

    # MailCredentialCryptoService wirft ohne gültigen Schlüssel. Wegwerfwerte für
    # den Container - .env ist per .gitignore ausgeschlossen.
    php -r '
        $file = ".env";
        $contents = file_get_contents($file);
        $contents = preg_replace(
            "/^MAIL_CREDENTIAL_KEY=.*$/m",
            "MAIL_CREDENTIAL_KEY=" . base64_encode(random_bytes(32)),
            $contents,
            1
        );
        $contents = preg_replace(
            "/^WEBMAIL_SSO_SECRET=.*$/m",
            "WEBMAIL_SSO_SECRET=" . base64_encode(random_bytes(32)),
            $contents,
            1
        );
        file_put_contents($file, $contents);
    '
else
    log ".env vorhanden, bleibt unverändert"
fi

if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
    {
        echo "export DB_HOST=${DB_HOST}"
        echo "export DB_DATABASE=${DB_NAME}"
        echo "export DB_USERNAME=${DB_USER}"
        echo "export DB_PASSWORD=${DB_PASS}"
        echo "export DB_PORT=${DB_PORT}"
    } >> "$CLAUDE_ENV_FILE"
fi

# ---------------------------------------------------------------- Composer ----
if [ ! -f vendor/autoload.php ]; then
    log "Composer-Abhängigkeiten installieren"
    # composer.json verlangt PHP ^8.5, im Image liegt 8.4. PHP 8.5 käme nur aus
    # dem ondrej/php-PPA, das der Agent-Proxy blockt (403) - deshalb der
    # Plattform-Override statt eines PHP-Upgrades.
    composer install --no-interaction --no-progress --ignore-platform-req=php
else
    log "vendor/ vorhanden"
fi

# -------------------------------------------------------------- Migrationen ----
log "Migrationen ausführen"
DB_HOST="$DB_HOST" DB_DATABASE="$DB_NAME" DB_USERNAME="$DB_USER" \
    DB_PASSWORD="$DB_PASS" DB_PORT="$DB_PORT" \
    ./vendor/bin/phinx migrate

log "Bereit: phpunit, phpcs, twigcs und phinx können laufen."
