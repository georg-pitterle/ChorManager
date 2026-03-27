#!/bin/bash

set -euo pipefail

echo "Choir Manager production deployment"
echo "=================================="

if [ ! -f ".env" ]; then
    echo "Error: .env file not found. Copy .env.example to .env first."
    exit 1
fi

set -a
source .env
set +a

required_vars=(
    DB_PASSWORD
    MYSQL_ROOT_PASSWORD
    SMTP_HOST
    SMTP_USERNAME
    SMTP_PASSWORD
    SMTP_FROM_EMAIL
)

for var_name in "${required_vars[@]}"; do
    if [ -z "${!var_name:-}" ]; then
        echo "Error: ${var_name} must be set in .env"
        exit 1
    fi
done

if ! docker info > /dev/null 2>&1; then
    echo "Error: Docker is not running"
    exit 1
fi

if ! docker network inspect portainer_network > /dev/null 2>&1; then
    echo "Error: Docker network 'portainer_network' does not exist."
    echo "Attach SWAG to that network or create it before deploying this stack."
    exit 1
fi

echo "Pulling images..."
docker compose --env-file .env -f docker-compose.prod.yml pull

echo "Starting stack..."
docker compose --env-file .env -f docker-compose.prod.yml up -d --remove-orphans

echo "Waiting for health checks..."
sleep 10

services=("db" "app" "web")
for service in "${services[@]}"; do
    if docker compose --env-file .env -f docker-compose.prod.yml ps "$service" | grep -q "healthy\|running"; then
        echo "OK: $service"
    else
        echo "Service check failed: $service"
        docker compose --env-file .env -f docker-compose.prod.yml ps "$service"
        docker compose --env-file .env -f docker-compose.prod.yml logs "$service"
        exit 1
    fi
done

echo
echo "Deployment completed."
echo "Expose the application through SWAG by proxying to http://chormanager-web:80 on portainer_network."
echo
echo "Useful commands:"
echo "  docker compose --env-file .env -f docker-compose.prod.yml ps"
echo "  docker compose --env-file .env -f docker-compose.prod.yml logs -f web"
echo "  docker compose --env-file .env -f docker-compose.prod.yml restart"