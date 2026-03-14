#!/bin/sh

# Wait for database to be ready
until mariadb-admin ping -h ${DB_HOST} -u ${DB_USERNAME} -p${DB_PASSWORD} --silent; do
  echo "Waiting for database..."
  sleep 2
done

# Run migrations
php vendor/bin/phinx migrate

# Start PHP-FPM
php-fpm