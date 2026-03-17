# Choir Manager - Production Deployment

This directory contains the production deployment configuration for the Choir Manager application.

## Prerequisites

- Docker and Docker Compose installed
- At least 2GB RAM available
- At least 5GB free disk space
- Access to GitHub Container Registry (ghcr.io) - the application image is pulled from there

## Quick Start

1. **Copy environment configuration:**
   ```bash
   cp .env.example .env
   ```

2. **Edit the .env file with your production values:**
   ```bash
   nano .env  # or your preferred editor
   ```

3. **Deploy the application:**
   ```bash
   docker compose -f docker-compose.prod.yml up -d
   ```

4. **Check deployment status:**
   ```bash
   docker compose -f docker-compose.prod.yml ps
   docker compose -f docker-compose.prod.yml logs
   ```

## Environment Variables

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `DB_DATABASE` | MySQL database name | `choir_db` | No |
| `DB_USERNAME` | MySQL user | `choir_user` | No |
| `DB_PASSWORD` | MySQL password | - | **Yes** |
| `DB_PORT` | MySQL port | `3306` | No |
| `MYSQL_ROOT_PASSWORD` | MySQL root password | - | **Yes** |
| `NGINX_PORT` | External nginx port | `80` | No |
| `APP_ENV` | Application environment | `production` | No |

## Application Image

The application uses a pre-built Docker image from GitHub Container Registry:
- **Image**: `ghcr.io/georg-pitterle/chormanager:latest`
- **Source**: Automatically pulled during deployment
- **Build Process**: Handled by GitHub Actions CI/CD pipeline

## Production Features

- **Health Checks**: All services include health checks for monitoring
- **Logging**: JSON logging with size limits (10MB per file, 3 files max)
- **Security**: No exposed database ports, read-only file mounts
- **Reliability**: Proper dependency management and restart policies
- **Resource Management**: Configurable resource limits

## Database Migration

The application will automatically run database migrations on first startup. If you need to run migrations manually:

```bash
docker compose -f docker-compose.prod.yml exec app php vendor/bin/phinx migrate
```

## Monitoring

### Health Checks
- **App**: PHP health check every 30 seconds
- **Database**: MySQL ping check every 30 seconds
- **Nginx**: HTTP health check every 30 seconds

### Logs
View logs for all services:
```bash
docker compose -f docker-compose.prod.yml logs -f
```

View logs for specific service:
```bash
docker compose -f docker-compose.prod.yml logs -f app
```

## Backup

### Database Backup
```bash
docker compose -f docker-compose.prod.yml exec db mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" $DB_DATABASE > backup_$(date +%Y%m%d_%H%M%S).sql
```

### Database Restore
```bash
docker compose -f docker-compose.prod.yml exec -T db mysql -u root -p"$MYSQL_ROOT_PASSWORD" $DB_DATABASE < backup_file.sql
```

## Scaling

The current setup is designed for single-instance deployment. For high-availability:

1. Use external MySQL cluster
2. Add Redis for session storage
3. Implement load balancer for nginx
4. Add monitoring (Prometheus/Grafana)

## Troubleshooting

### Application not accessible
```bash
# Check service status
docker compose -f docker-compose.prod.yml ps

# Check logs
docker compose -f docker-compose.prod.yml logs app
docker compose -f docker-compose.prod.yml logs nginx
```

### Database connection issues
```bash
# Check database health
docker compose -f docker-compose.prod.yml exec db mysqladmin ping -u $DB_USERNAME -p$DB_PASSWORD

# Check database logs
docker compose -f docker-compose.prod.yml logs db
```

### Permission issues
```bash
# Reset permissions
docker compose -f docker-compose.prod.yml exec app chown -R www-data:www-data /var/www/html
```

## Security Notes

- Change all default passwords in `.env`
- Use strong, unique passwords
- Consider using Docker secrets for sensitive data
- Regularly update base images
- Monitor logs for security issues