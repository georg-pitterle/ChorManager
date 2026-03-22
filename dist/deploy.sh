#!/bin/bash

# Choir Manager Production Deployment Script

set -e

echo "🚀 Choir Manager Production Deployment"
echo "====================================="

# Check if .env file exists
if [ ! -f ".env" ]; then
    echo "❌ Error: .env file not found!"
    echo "Please copy .env.example to .env and configure your settings."
    exit 1
fi

# Load environment variables
set -a
source .env
set +a

# Validate required variables
if [ -z "$DB_PASSWORD" ]; then
    echo "❌ Error: DB_PASSWORD is required in .env file"
    exit 1
fi

if [ -z "$MYSQL_ROOT_PASSWORD" ]; then
    echo "❌ Error: MYSQL_ROOT_PASSWORD is required in .env file"
    exit 1
fi

echo "✅ Configuration validated"

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "❌ Error: Docker is not running"
    exit 1
fi

APP_VOLUME="chormanager_app_data"

echo "✅ Docker is running"

# Pull latest images
echo "📥 Pulling latest images..."
docker compose -f docker-compose.prod.yml pull

# Start services
echo "🏗️  Starting services..."
docker compose -f docker-compose.prod.yml down
docker volume rm -f "${APP_VOLUME}" || true

echo "🏗️ Starting services with fresh app volume..."
docker compose -f docker-compose.prod.yml up -d --force-recreate

# Wait for services to be healthy
echo "⏳ Waiting for services to be healthy..."
sleep 10

# Check service health
echo "🔍 Checking service health..."
services=("db" "app" "nginx")
for service in "${services[@]}"; do
    if docker compose -f docker-compose.prod.yml ps $service | grep -q "healthy\|running"; then
        echo "✅ $service is healthy"
    else
        echo "❌ $service is not healthy"
        echo "📋 Service status:"
        docker compose -f docker-compose.prod.yml ps $service
        echo "📋 Service logs:"
        docker compose -f docker-compose.prod.yml logs $service
        exit 1
    fi
done

echo ""
echo "🎉 Deployment successful!"
echo "🌐 Application is available at: http://localhost:${NGINX_PORT:-80}"
echo ""
echo "📊 Service Status:"
docker compose -f docker-compose.prod.yml ps
echo ""
echo "📋 Useful commands:"
echo "  View logs: docker compose -f docker-compose.prod.yml logs -f"
echo "  Stop services: docker compose -f docker-compose.prod.yml down"
echo "  Restart services: docker compose -f docker-compose.prod.yml restart"