#!/bin/sh
set -eu

cd /var/www/html

REGISTRATION_REMINDER_WORKER_INTERVAL="${REGISTRATION_REMINDER_WORKER_INTERVAL:-3600}"

while true; do
  php bin/send_registration_reminders.php || true
  sleep "${REGISTRATION_REMINDER_WORKER_INTERVAL}"
done
