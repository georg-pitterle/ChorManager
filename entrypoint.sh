#!/bin/sh
set -eu

DB_PORT="${DB_PORT:-3306}"
MAX_ATTEMPTS="${DB_WAIT_MAX_ATTEMPTS:-90}"
ATTEMPTS=0

until mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" --ssl=0; do
  ATTEMPTS=$((ATTEMPTS + 1))
  echo "Waiting for database at ${DB_HOST}:${DB_PORT} (attempt ${ATTEMPTS}/${MAX_ATTEMPTS})..."
  if [ "${ATTEMPTS}" -ge "${MAX_ATTEMPTS}" ]; then
    echo "Database did not become ready in time. Exiting."
    exit 1
  fi
  sleep 2
done

# Run migrations
php vendor/bin/phinx migrate

# Ensure public vendor assets are present for static delivery
php bin/copy-assets.php

# Start PHP-FPM
php-fpm
