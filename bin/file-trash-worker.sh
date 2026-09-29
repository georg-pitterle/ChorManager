#!/bin/sh
set -eu

cd /var/www/html

# Einmal am Tag reicht: Die Frist im Papierkorb wird in Tagen gemessen.
FILE_TRASH_WORKER_INTERVAL="${FILE_TRASH_WORKER_INTERVAL:-86400}"

while true; do
  php bin/purge_file_trash.php || true
  sleep "${FILE_TRASH_WORKER_INTERVAL}"
done
