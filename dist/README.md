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

| Variable               | Description                            | Default         | Required |
| ---------------------- | -------------------------------------- | --------------- | -------- |
| `APP_IMAGE_TAG`        | Image tag from GHCR                    | `latest`        | No       |
| `DB_DATABASE`          | MySQL database name                    | `choir_db`      | No       |
| `DB_USERNAME`          | MySQL user                             | `choir_user`    | No       |
| `DB_PASSWORD`          | MySQL password                         | -               | **Yes**  |
| `DB_PORT`              | Database port used by the app config   | `3306`          | No       |
| `MYSQL_ROOT_PASSWORD`  | MySQL root password                    | -               | **Yes**  |
| `SMTP_HOST`            | SMTP server host                       | -               | **Yes**  |
| `SMTP_PORT`            | SMTP server port                       | `587`           | No       |
| `SMTP_AUTH`            | SMTP authentication enabled (`1/0`)    | `1`             | No       |
| `SMTP_USERNAME`        | SMTP username                          | -               | **Yes**  |
| `SMTP_PASSWORD`        | SMTP password                          | -               | **Yes**  |
| `SMTP_ENCRYPTION`      | SMTP encryption (`tls`, `ssl`, `none`) | `tls`           | No       |
| `SMTP_FROM_EMAIL`      | Sender email address                   | -               | **Yes**  |
| `SMTP_FROM_NAME`       | Sender display name                    | `Chor-Manager`  | No       |
| `REMEMBER_ME_DAYS`     | Remember-me cookie lifetime in days    | `30`            | No       |
| `CLIENT_MAX_BODY_SIZE` | Nginx request body limit for uploads   | `100m`          | No       |
| `TZ`                   | Container timezone                     | `Europe/Vienna` | No       |
| `MAIL_CREDENTIAL_KEY`     | Encrypts stored IMAP passwords at rest (`openssl rand -base64 32`)   | -               | **Yes**  |
| `SNAPPYMAIL_SSO_SECRET`   | Shared secret app ⇄ SnappyMail plugin (`openssl rand -base64 32`)    | -               | **Yes**  |
| `APP_URL`                 | Public HTTPS URL, used for the webmail SSO redirect                  | -               | **Yes**  |
| `MAIL_ALLOW_PRIVATE_HOSTS`| Allow IMAP hosts on private/loopback networks (SSRF guard opt-out)   | `0`             | No       |
| `SNAPPYMAIL_UPLOAD_MAX_SIZE` | Upload limit inside the SnappyMail container                     | `25M`           | No       |
| `SNAPPYMAIL_MEMORY_LIMIT` | PHP memory limit inside the SnappyMail container                     | `128M`          | No       |

SMTP is configured exclusively via environment variables. It is no longer managed in the application UI.

The mailbox / webmail feature is separate from the transactional SMTP above:
`MAIL_CREDENTIAL_KEY` protects each user's stored IMAP credentials, and
`SNAPPYMAIL_SSO_SECRET` secures the single-sign-on hand-off into the SnappyMail
container. `MAIL_CREDENTIAL_KEY` is required as soon as the mailbox settings are
visible in the profile, even if you do not deploy the SnappyMail container.

## SWAG Reverse Proxy Example

Create a SWAG config such as `/config/nginx/proxy-confs/chormanager.subdomain.conf`:

```nginx
server {
    listen 443 ssl;
    listen [::]:443 ssl;

    server_name choir.example.com;

    include /config/nginx/ssl.conf;

    client_max_body_size 100m;

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

If you set `CLIENT_MAX_BODY_SIZE` in this stack, keep the SWAG `client_max_body_size` at least as high.
The effective upload limit is the smallest limit in the proxy chain.

## Webmail (SnappyMail)

The mailbox feature lets each user open a webmail client (SnappyMail) that logs
straight into their IMAP mailbox via a short-lived single-sign-on token.

- The SnappyMail image `ghcr.io/<owner>/chormanager-snappymail:latest` is built
  automatically by the GitHub Actions workflow, alongside `app` and `web`. The
  `chormanager-sso` SSO plugin is baked into it (source: `dist/snappymail/`), so
  no host-side bind-mounts are needed - it works from the Portainer web editor.
- Add the `snappymail` service to the stack on the `proxy` network (it needs
  outbound access to reach IMAP/SMTP servers, so it must NOT sit on the
  internal-only network), plus a `snappymail_data` named volume.
- Set `MAIL_CREDENTIAL_KEY`, `SNAPPYMAIL_SSO_SECRET` and `APP_URL` (see the table
  above). `SNAPPYMAIL_SSO_SECRET` is consumed by both `app` and `snappymail`
  from the same variable, so the two sides always match.

Route `/webmail/` to SnappyMail in your existing SWAG proxy config (same
`server_name` as the app, so the SSO stays same-origin), before the `location /`
block:

```nginx
    location /webmail/ {
        include /config/nginx/proxy.conf;
        include /config/nginx/resolver.conf;
        set $upstream_sm chormanager-snappymail-prod;
        proxy_pass http://$upstream_sm:8888/;
    }

    location /snappymail/ {
        include /config/nginx/proxy.conf;
        include /config/nginx/resolver.conf;
        set $upstream_sm chormanager-snappymail-prod;
        proxy_pass http://$upstream_sm:8888/snappymail/;
    }
```

`/webmail/` serves the SnappyMail shell (prefix stripped); `/snappymail/` passes
its version-pinned static assets straight through. The SnappyMail admin password
is auto-generated on first boot inside the volume; retrieve it if needed with:

```bash
docker compose -f docker-compose.prod.yml exec snappymail \
  cat /var/lib/snappymail/_data_/_default_/admin_password.txt
```

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
