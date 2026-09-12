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

# PHP-Erweiterungen, die das Projekt braucht: pdo_mysql für Eloquent und Phinx,
# sqlite3 für die Tests mit In-Memory-Datenbank, der Rest für PDF, Mail und Twig.
# sodium ist in die sury-Binary eingebaut und braucht kein eigenes Paket.
PHP_REQUIRED_SUFFIXES=(cli common curl gd intl mbstring mysql sqlite3 xml zip)

# Angenehm, aber nicht notwendig - und nicht in jeder Version einzeln paketiert:
# php8.4-opcache gibt es, php8.5-opcache nicht (dort steckt OPcache schon drin).
# Deshalb werden diese übersprungen, wenn die Zielversion sie nicht kennt.
PHP_OPTIONAL_SUFFIXES=(opcache readline)

# Quelle, aus der schon das mitgelieferte PHP 8.4 stammt. Wird nur zur Diagnose
# angefragt, wenn die Installation scheitert.
PHP_PACKAGE_SOURCE_HOST="ppa.launchpadcontent.net"
PHP_PACKAGE_SOURCE="https://${PHP_PACKAGE_SOURCE_HOST}/ondrej/php/ubuntu/dists/noble/InRelease"

APT_UPDATED=0
LOG_DIR="/var/log"

log() { printf '[session-start] %s\n' "$1"; }

ensure_apt_updated() {
    if [ "$APT_UPDATED" -eq 0 ]; then
        # Der Paketindex im Image ist älter als die Spiegel; ohne update schlagen
        # die Downloads mit 404 fehl.
        apt-get update -qq || true
        APT_UPDATED=1
    fi
}

# "8.5" >= "8.4"? Nutzt sort -V, damit 8.10 nicht kleiner als 8.9 wirkt.
version_at_least() {
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]
}

# ---------------------------------------------------------------- MariaDB ----
if ! command -v mariadbd >/dev/null 2>&1; then
    log "MariaDB installieren"
    ensure_apt_updated
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mariadb-server
else
    log "MariaDB bereits installiert"
fi

if ! mariadb-admin ping >/dev/null 2>&1; then
    log "MariaDB starten"
    # Kein systemd im Container, also direkt über mariadbd-safe.
    install -d -o mysql -g mysql /var/run/mysqld
    nohup mariadbd-safe --user=mysql --skip-syslog >"$LOG_DIR/mariadb-session-start.log" 2>&1 &

    for _ in $(seq 1 60); do
        mariadb-admin ping >/dev/null 2>&1 && break
        sleep 1
    done

    if ! mariadb-admin ping >/dev/null 2>&1; then
        log "FEHLER: MariaDB ist nicht hochgekommen, siehe $LOG_DIR/mariadb-session-start.log"
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
# Die Suite läuft nicht gegen die Entwicklungsdatenbank: tests/bootstrap.php
# leitet den Namen ab (`db` wird zu `db_test`) und legt sie bei Bedarf selbst an,
# paratest hängt je Prozess ein Token an (`db_test_1` und so weiter). Beides
# braucht Rechte auf Datenbanken, die es beim Sitzungsstart noch gar nicht gibt -
# deshalb ein Muster statt einzelner Namen.
#
# Das `_` ist als `\_` maskiert, weil es in GRANT sonst als Platzhalter für ein
# beliebiges Zeichen gilt: `db_test%` würde auch `dbXtest` treffen.
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\_test%\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\_test%\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL

# --------------------------------------------------------------------- PHP ----
# Das Image bringt PHP 8.4 aus dem ondrej/php-PPA mit, composer.json verlangt
# aber ^8.5. Dieselbe Paketquelle hätte 8.5, nur blockt die Netzwerkrichtlinie
# der Remote-Umgebung ppa.launchpadcontent.net derzeit mit 403.
#
# Der Hook versucht das Upgrade deshalb bei jedem Start und fällt zurück, wenn
# die Quelle nicht erreichbar ist. Wird der Host in der Umgebung freigegeben,
# zieht die nächste Sitzung PHP 8.5 von selbst - ohne Änderung an diesem Skript.
required_php="$(
    php -r '
        $manifest = json_decode(file_get_contents("composer.json"), true);
        $constraint = $manifest["require"]["php"] ?? "";
        echo preg_match("/(\d+)\.(\d+)/", $constraint, $m) === 1 ? $m[1] . "." . $m[2] : "";
    ' 2>/dev/null || true
)"
current_php="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"

composer_platform_args=()

install_php_version() {
    local version="$1"
    local packages=() skipped=() suffix package

    ensure_apt_updated

    for suffix in "${PHP_REQUIRED_SUFFIXES[@]}"; do
        packages+=("php${version}-${suffix}")
    done

    # Optionale Pakete nur mitnehmen, wenn es sie für diese Version gibt - sonst
    # bricht apt am ersten unbekannten Namen ab und das ganze Upgrade fällt aus.
    for suffix in "${PHP_OPTIONAL_SUFFIXES[@]}"; do
        package="php${version}-${suffix}"
        if apt-cache show "$package" >/dev/null 2>&1; then
            packages+=("$package")
        else
            skipped+=("$package")
        fi
    done

    if [ "${#skipped[@]}" -gt 0 ]; then
        log "Nicht paketiert für PHP ${version}, wird übersprungen: ${skipped[*]}"
    fi

    if ! DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${packages[@]}" \
        >"$LOG_DIR/php-upgrade-session-start.log" 2>&1; then
        return 1
    fi

    activate_php_version "$version"
}

# Schaltet /usr/bin/php auf die Zielversion um und prüft, dass sie auch trägt.
# Eine falsche Version oder eine fehlende Erweiterung würde sonst jeden Testlauf
# der Sitzung kippen - dann lieber zurück auf die alte Binary.
activate_php_version() {
    local version="$1" previous
    previous="$(readlink -f /usr/bin/php || true)"

    if ! update-alternatives --set php "/usr/bin/php${version}" >/dev/null 2>&1; then
        return 1
    fi

    if ! php -r '
        $wanted = $argv[1];
        if (PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION !== $wanted) {
            fwrite(STDERR, "unerwartete Version: " . PHP_VERSION . "\n");
            exit(1);
        }
        foreach (["pdo_mysql", "sqlite3", "mbstring", "xml", "curl", "sodium"] as $extension) {
            if (!extension_loaded($extension)) {
                fwrite(STDERR, "fehlende Erweiterung: {$extension}\n");
                exit(1);
            }
        }
    ' "$version" 2>>"$LOG_DIR/php-upgrade-session-start.log"; then
        [ -n "$previous" ] && update-alternatives --set php "$previous" >/dev/null 2>&1 || true
        return 1
    fi

    return 0
}

php_fallback_notice() {
    log "Composer läuft solange mit --ignore-platform-req=php (PHP $(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;') statt $1)."
    composer_platform_args+=(--ignore-platform-req=php)
}

if [ -z "$required_php" ]; then
    log "PHP-Anforderung aus composer.json nicht lesbar, bleibe bei PHP $current_php"
elif version_at_least "$current_php" "$required_php"; then
    log "PHP $current_php erfüllt die Anforderung (>= $required_php)"
elif command -v "php${required_php}" >/dev/null 2>&1; then
    log "PHP $required_php ist installiert, wird aktiviert"
    if activate_php_version "$required_php"; then
        log "PHP $(php -r 'echo PHP_VERSION;') aktiv"
    else
        log "PHP $required_php ließ sich nicht aktivieren (siehe $LOG_DIR/php-upgrade-session-start.log)."
        php_fallback_notice "$required_php"
    fi
else
    log "PHP $current_php < $required_php - versuche PHP $required_php zu installieren"
    if install_php_version "$required_php"; then
        log "PHP $(php -r 'echo PHP_VERSION;') aktiv"
    else
        # Nicht raten, warum: Die Paketquelle einmal direkt anfragen. Ein 403 auf
        # dem CONNECT-Tunnel heißt Netzwerkrichtlinie, alles andere ist ein echtes
        # Paketproblem und gehört anders behandelt.
        if curl --silent --show-error --max-time 20 --output /dev/null \
            "$PHP_PACKAGE_SOURCE" >/dev/null 2>&1; then
            log "PHP $required_php nicht installierbar, obwohl die Paketquelle erreichbar ist."
            log "Das ist kein Netzproblem - siehe $LOG_DIR/php-upgrade-session-start.log."
        else
            log "PHP $required_php nicht installierbar: $PHP_PACKAGE_SOURCE_HOST ist von hier nicht erreichbar."
            log "Die Netzwerkrichtlinie der Umgebung muss den Host freigeben, dann zieht die nächste Sitzung PHP $required_php von selbst."
        fi
        php_fallback_notice "$required_php"
    fi
fi

# -------------------------------------------------------------------- .env ----
# Tests lesen die Zugangsdaten über Dotenv aus .env, phinx.php dagegen über
# getenv() - beides muss gesetzt sein.
if [ ! -f .env ]; then
    log ".env aus .env.example erzeugen"
    sed -e "s|^DB_HOST=.*|DB_HOST=${DB_HOST}|" .env.example > .env
else
    log ".env vorhanden, gesetzte Werte bleiben"
fi

# Nach einem Pull kann .env.example Schlüssel mitbringen, die es beim Anlegen der
# .env noch nicht gab. Fehlt so einer, läuft die Anwendung in einen Fehler, der
# nach allem Möglichen aussieht, nur nicht nach einer unvollständigen .env.
#
# Ergänzt werden ausschließlich fehlende Schlüssel; ein bereits gesetzter Wert
# wird nie überschrieben. Danach bekommen die beiden Geheimnisse einen
# Wegwerfwert, falls sie leer sind - MailCredentialCryptoService wirft sonst.
# .env ist per .gitignore ausgeschlossen.
php -r '
    $target = ".env";
    $contents = file_get_contents($target);

    $keysIn = static function (string $text): array {
        $keys = [];
        foreach (preg_split("/\R/", $text) as $line) {
            if (preg_match("/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/", $line, $match) === 1) {
                $keys[$match[1]] = $line;
            }
        }
        return $keys;
    };

    $present = $keysIn($contents);
    $missing = array_diff_key($keysIn(file_get_contents(".env.example")), $present);

    if ($missing !== []) {
        $contents = rtrim($contents, "\n") . "\n" . implode("\n", $missing) . "\n";
        echo "[session-start] .env um fehlende Schlüssel ergänzt: ", implode(", ", array_keys($missing)), "\n";
    }

    foreach (["MAIL_CREDENTIAL_KEY", "WEBMAIL_SSO_SECRET"] as $secret) {
        $pattern = "/^" . $secret . "=[ \t]*$/m";
        if (preg_match($pattern, $contents) === 1) {
            $contents = preg_replace($pattern, $secret . "=" . base64_encode(random_bytes(32)), $contents, 1);
            echo "[session-start] ", $secret, " mit einem Wegwerfwert belegt\n";
        }
    }

    file_put_contents($target, $contents);
'

if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
    {
        echo "export DB_HOST=${DB_HOST}"
        echo "export DB_DATABASE=${DB_NAME}"
        echo "export DB_USERNAME=${DB_USER}"
        echo "export DB_PASSWORD=${DB_PASS}"
        echo "export DB_PORT=${DB_PORT}"
        # Der Container läuft als root; ohne das Flag bricht jeder Composer-Aufruf
        # in der Sitzung mit "Do not run Composer as root/super user" ab.
        # Begründung samt Plugin-Folgen steht im Composer-Abschnitt weiter unten.
        echo "export COMPOSER_ALLOW_SUPERUSER=1"
    } >> "$CLAUDE_ENV_FILE"
fi

# ---------------------------------------------------------------- Composer ----
# Der Container läuft als root. Ohne dieses Flag schaltet Composer alle Plugins
# ab ("Composer plugins have been disabled for safety in this non-interactive
# session") - und cweagans/composer-patches ist genau so ein Plugin. Es steht in
# `allow-plugins` und liest patches/patches.json.
#
# Die Liste dort ist im Moment leer, das Flag ändert also heute nichts am
# Ergebnis. Es geht um den Tag, an dem der erste Patch dazukommt: Ohne das Flag
# würde er im Container stillschweigend nicht angewendet, und der Unterschied zur
# lokalen DDEV-Umgebung wäre von Hand kaum zu finden.
#
# Gilt für den Rest des Skripts; in die Sitzungsumgebung geht dieselbe Variable
# weiter oben über CLAUDE_ENV_FILE, damit `composer test`, `composer phpcs` und
# `composer test:parallel` im Container ohne Präfix laufen.
export COMPOSER_ALLOW_SUPERUSER=1

# `composer install` läuft bei jedem Start, nicht nur wenn vendor/ ganz fehlt.
#
# Die alte Prüfung auf vendor/autoload.php konnte nur "gar keine Abhängigkeiten"
# erkennen, nicht "vendor/ ist gegenüber composer.lock veraltet". Das Image
# bringt ein vorgebackenes vendor/ mit; kommt im Repo eine Abhängigkeit dazu,
# fehlt sie in jeder Sitzung, ohne dass es auffällt. Genau so fehlte paratest,
# nachdem es mit e7a3e43 dazukam - die volle Suite lief seitdem nur sequenziell
# und damit ohne den strengeren parallelen Lauf.
#
# Der Aufruf ist billig, wenn nichts zu tun ist: Composer vergleicht zuerst
# lokal gegen vendor/composer/installed.json und geht nur ins Netz, wenn
# tatsächlich ein Paket fehlt.
if [ -f vendor/autoload.php ]; then
    log "Composer-Abhängigkeiten abgleichen"
    # Ein fehlgeschlagener Abgleich darf die Sitzung nicht kippen - das
    # vorhandene vendor/ trägt in aller Regel weiter. Ohne vendor/ ist derselbe
    # Fehler dagegen das Ende, siehe unten.
    if ! composer install --no-interaction --no-progress \
        "${composer_platform_args[@]+"${composer_platform_args[@]}"}"; then
        log "WARNUNG: Abgleich fehlgeschlagen, Sitzung läuft mit dem vorhandenen vendor/ weiter."
    fi
else
    log "Composer-Abhängigkeiten installieren"
    composer install --no-interaction --no-progress "${composer_platform_args[@]+"${composer_platform_args[@]}"}"
fi

# -------------------------------------------------------- Frontend-Assets ----
# Die Oberfläche lädt Bootstrap, FullCalendar, TinyMCE und PDF.js ausschließlich
# aus public/vendor - instructions/template-hygiene.md verbietet CDNs. Dorthin
# kommen sie über bin/copy-assets.php aus node_modules; public/vendor ist nicht
# eingecheckt. Ohne diesen Schritt startet die Anwendung zwar, zeigt aber eine
# Seite ohne Stile und ohne Kalender.
#
# `composer install` ruft copy-assets.php als Post-Install-Skript bereits auf -
# es steigt ohne node_modules nur aus. Deshalb hier: erst npm, dann die Assets.
#
# --omit=dev lässt @playwright/test weg: Das ist die einzige devDependency, die
# e2e-Suite braucht ohnehin eigene Browser und wird bewusst von Hand gestartet.
if [ -f package-lock.json ]; then
    if [ ! -d node_modules ]; then
        log "npm-Pakete installieren"
    else
        log "npm-Pakete abgleichen"
    fi

    # Wie bei Composer: Ein Fehler hier darf die Sitzung nicht kippen. PHPUnit,
    # phpcs und phinx laufen auch ohne Frontend-Assets.
    if npm ci --omit=dev --no-audit --no-fund >"$LOG_DIR/npm-session-start.log" 2>&1; then
        if php bin/copy-assets.php >>"$LOG_DIR/npm-session-start.log" 2>&1; then
            log "Frontend-Assets liegen in public/vendor"
        else
            log "WARNUNG: Assets ließen sich nicht kopieren, siehe $LOG_DIR/npm-session-start.log"
        fi
    else
        log "WARNUNG: npm ci fehlgeschlagen, Oberfläche bleibt ohne Assets (siehe $LOG_DIR/npm-session-start.log)"
    fi
fi

# -------------------------------------------------------------- Migrationen ----
log "Migrationen ausführen"
DB_HOST="$DB_HOST" DB_DATABASE="$DB_NAME" DB_USERNAME="$DB_USER" \
    DB_PASSWORD="$DB_PASS" DB_PORT="$DB_PORT" \
    ./vendor/bin/phinx migrate

log "Bereit: phpunit, paratest, phpcs, twigcs und phinx können laufen; Oberfläche hat ihre Assets."
