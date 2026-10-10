#!/usr/bin/env bash
set -euo pipefail

# Setzt eine ChorManager-Datenbank auf den Auslieferungszustand zurück:
# leere, migrierte Datenbank ohne User -> die App leitet auf /setup.
# NUR für Dev/ddev gedacht.
#
#   bash bin/fresh-db.sh               Entwicklungsdatenbank "db"
#   bash bin/fresh-db.sh db_test_e2e   Datenbank des E2E-Hosts (tests/e2e)

database="${1:-db}"

# Der Name landet ungeprüft im SQL - nur schlichte Bezeichner zulassen.
if [[ ! "$database" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "[fresh-db] ungültiger Datenbankname: $database" >&2
    exit 1
fi

echo "[fresh-db] DROP + CREATE DATABASE $database ..."
ddev mysql -e "DROP DATABASE IF EXISTS $database; CREATE DATABASE $database;"

echo "[fresh-db] phinx migrate ($database) ..."
ddev exec env DB_DATABASE="$database" ./vendor/bin/phinx migrate

echo "[fresh-db] fertig: leere migrierte DB $database (keine User)."
