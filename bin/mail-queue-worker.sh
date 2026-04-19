#!/bin/sh
set -eu

cd /var/www/html

MAIL_QUEUE_WORKER_INTERVAL="${MAIL_QUEUE_WORKER_INTERVAL:-20}"

while true; do
  php bin/process_mail_queue.php || true
  sleep "${MAIL_QUEUE_WORKER_INTERVAL}"
done
