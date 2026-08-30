#!/bin/sh
set -eu

cd /var/www/html

NOTIFICATION_REMINDER_WORKER_INTERVAL="${NOTIFICATION_REMINDER_WORKER_INTERVAL:-3600}"

while true; do
  php bin/send_notification_reminders.php || true
  sleep "${NOTIFICATION_REMINDER_WORKER_INTERVAL}"
done
