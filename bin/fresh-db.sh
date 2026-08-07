#!/usr/bin/env bash
set -euo pipefail

# Setzt die ChorManager-Dev-DB auf den Auslieferungszustand zurück:
# leere, migrierte Datenbank ohne User -> die App leitet auf /setup.
# NUR fuer Dev/ddev gedacht.

echo "[fresh-db] DROP + CREATE DATABASE db ..."
ddev mysql -e "DROP DATABASE IF EXISTS db; CREATE DATABASE db;"

echo "[fresh-db] phinx migrate ..."
ddev exec ./vendor/bin/phinx migrate

echo "[fresh-db] fertig: leere migrierte DB (keine User)."
