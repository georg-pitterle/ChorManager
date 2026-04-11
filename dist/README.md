# Choir Manager - Production Deployment with Portainer and SWAG

This directory contains a production stack for Portainer. The web container is not published directly and is intended to be reached through an existing linuxserver.io SWAG instance on the shared Docker network `portainer_network`.

## Deployment Model

- `db` is only reachable on an internal Docker network.
- `app` runs PHP-FPM and database migrations automatically on startup.
- `web` serves the application over HTTP only inside Docker.
- `web` is attached to the external network `portainer_network` so SWAG can reverse proxy to it.
- No public ports are opened by this stack.

## Prerequisites

- Portainer stack deployment or Docker Compose on the target host
- An existing SWAG container attached to `portainer_network`
- At least 2 GB RAM and 5 GB free disk space
- Access to GitHub Container Registry `ghcr.io`

## Portainer Deployment

1. Copy the values from `.env.example` into Portainer stack environment variables.
2. Deploy the stack with `docker-compose.prod.yml`.
3. Ensure the external Docker network `portainer_network` already exists and SWAG is attached to it.
4. Create a SWAG proxy config that forwards your hostname to `http://chormanager-web-prod:80`.

If you deploy without Portainer, create the external network first:

```bash
docker network create portainer_network
docker compose --env-file .env -f docker-compose.prod.yml up -d
```

## Environment Variables

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `APP_IMAGE_TAG` | Image tag from GHCR | `latest` | No |
| `DB_DATABASE` | MySQL database name | `choir_db` | No |
| `DB_USERNAME` | MySQL user | `choir_user` | No |
| `DB_PASSWORD` | MySQL password | - | **Yes** |
| `DB_PORT` | Database port used by the app config | `3306` | No |
| `MYSQL_ROOT_PASSWORD` | MySQL root password | - | **Yes** |
| `SMTP_HOST` | SMTP server host | - | **Yes** |
| `SMTP_PORT` | SMTP server port | `587` | No |
| `SMTP_AUTH` | SMTP authentication enabled (`1/0`) | `1` | No |
| `SMTP_USERNAME` | SMTP username | - | **Yes** |
| `SMTP_PASSWORD` | SMTP password | - | **Yes** |
| `SMTP_ENCRYPTION` | SMTP encryption (`tls`, `ssl`, `none`) | `tls` | No |
| `SMTP_FROM_EMAIL` | Sender email address | - | **Yes** |
| `SMTP_FROM_NAME` | Sender display name | `Chor-Manager` | No |
| `REMEMBER_ME_DAYS` | Remember-me cookie lifetime in days | `30` | No |
| `TZ` | Container timezone | `Europe/Vienna` | No |

SMTP is configured exclusively via environment variables. It is no longer managed in the application UI.

## SWAG Reverse Proxy Example

Create a SWAG config such as `/config/nginx/proxy-confs/chormanager.subdomain.conf`:

```nginx
server {
    listen 443 ssl;
    listen [::]:443 ssl;

    server_name choir.example.com;

    include /config/nginx/ssl.conf;

    client_max_body_size 26m;

    location / {
        include /config/nginx/proxy.conf;
        include /config/nginx/resolver.conf;
        set $upstream_app chormanager-web-prod;
        set $upstream_port 80;
        set $upstream_proto http;
        proxy_pass $upstream_proto://$upstream_app:$upstream_port;
    }
}
```

Adjust `server_name` to your real hostname and reload SWAG afterwards.

## Operational Notes

- The stack copies the image contents into a named volume before startup so `app` and `web` serve the exact same code.
- The copy step clears the target volume first. That avoids stale files during image updates, which is important for Portainer-based redeployments.
- Database migrations run automatically when `app` starts.
- The internal web service exposes `/health`, which is used for container health checks.

## Logs and Health

```bash
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f web
docker compose -f docker-compose.prod.yml logs -f app
docker compose -f docker-compose.prod.yml logs -f db
```

## Backup

```bash
docker compose -f docker-compose.prod.yml exec db \
  mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" "$DB_DATABASE" > backup.sql
```

Restore:

```bash
docker compose -f docker-compose.prod.yml exec -T db \
  mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$DB_DATABASE" < backup.sql
```

## Security Notes

- Keep all secrets in Portainer stack variables or an external secret store, never in Git.
- Do not publish MySQL or the internal web container directly to the host.
- Terminate TLS in SWAG and serve the app only through HTTPS.
- Update image tags deliberately instead of relying on long-unpatched `latest` deployments.