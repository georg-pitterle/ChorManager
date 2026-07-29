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

# Ensure the backup directory exists and belongs to the PHP-FPM worker user.
# A freshly created named volume is mounted as root:root, so without this the
# app could not write backups into it. Recursive because files copied into the
# volume from outside the app (e.g. a manually restored dump) arrive as root.
BACKUP_DIR="${BACKUP_DIR:-/var/www/html/var/backups}"
mkdir -p "${BACKUP_DIR}"
chown -R www-data:www-data "${BACKUP_DIR}"
chmod 750 "${BACKUP_DIR}"

# PHP keeps session files in the container's writable layer by default, so every
# image update or recreate logs every user out. SESSION_SAVE_PATH moves them into
# a named volume; like the backup volume it arrives as root:root, so it has to be
# handed to the PHP-FPM worker user. An empty value keeps the PHP default, which
# is what local/dev containers use.
SESSION_SAVE_PATH="${SESSION_SAVE_PATH:-}"
if [ -n "${SESSION_SAVE_PATH}" ]; then
  mkdir -p "${SESSION_SAVE_PATH}"
  chown -R www-data:www-data "${SESSION_SAVE_PATH}"
  chmod 700 "${SESSION_SAVE_PATH}"
fi

MAIL_QUEUE_WORKER_INTERVAL="${MAIL_QUEUE_WORKER_INTERVAL:-20}"
REGISTRATION_REMINDER_WORKER_INTERVAL="${REGISTRATION_REMINDER_WORKER_INTERVAL:-3600}"

/usr/local/bin/mail-queue-worker.sh &
mail_queue_worker_pid=$!

/usr/local/bin/registration-reminder-worker.sh &
registration_reminder_worker_pid=$!

php-fpm -F &
php_fpm_pid=$!

shutdown() {
  kill "${mail_queue_worker_pid}" 2>/dev/null || true
  kill "${registration_reminder_worker_pid}" 2>/dev/null || true
  kill "${php_fpm_pid}" 2>/dev/null || true
}

trap shutdown INT TERM

wait "${php_fpm_pid}"
