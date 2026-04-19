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

MAIL_QUEUE_WORKER_INTERVAL="${MAIL_QUEUE_WORKER_INTERVAL:-20}"

/usr/local/bin/mail-queue-worker.sh &
mail_queue_worker_pid=$!

php-fpm -F &
php_fpm_pid=$!

shutdown() {
  kill "${mail_queue_worker_pid}" 2>/dev/null || true
  kill "${php_fpm_pid}" 2>/dev/null || true
}

trap shutdown INT TERM

wait "${php_fpm_pid}"
