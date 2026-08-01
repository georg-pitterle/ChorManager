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
| `STACK_ID`             | Suffix for the shared-network aliases  | `prod`          | No       |
| `DB_DATABASE`          | MySQL database name                    | -               | **Yes**  |
| `DB_USERNAME`          | MySQL user                             | -               | **Yes**  |
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
| `TZ`                   | Container timezone                     | `Europe/Vienna` | No       |
| `MAIL_CREDENTIAL_KEY`     | Encrypts stored IMAP passwords at rest (`openssl rand -base64 32`)   | -               | **Yes**  |
| `SNAPPYMAIL_SSO_SECRET`   | Shared secret app ⇄ SnappyMail plugin (`openssl rand -base64 32`)    | -               | **Yes**  |
| `APP_URL`                 | Public HTTPS URL, used for the webmail SSO redirect                  | -               | **Yes**  |
| `MAIL_ALLOW_PRIVATE_HOSTS`| Allow IMAP hosts on private/loopback networks (SSRF guard opt-out)   | `0`             | No       |
| `SNAPPYMAIL_UPLOAD_MAX_SIZE` | Upload limit inside the SnappyMail container                     | `25M`           | No       |
| `SNAPPYMAIL_MEMORY_LIMIT` | PHP memory limit inside the SnappyMail container                     | `128M`          | No       |
| `BACKUP_DIR`              | In-app backup directory; must be inside the `backup_data` volume     | `/var/backups/chormanager` | No |
| `BACKUP_MAX_MANUAL`       | Manual backups kept; new ones are refused at the limit               | `5`             | No       |
| `BACKUP_MAX_AUTO`         | Automatic backups kept; the oldest is rotated out                    | `7`             | No       |

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

Adjust `server_name` to your real hostname and reload SWAG afterwards. If you set
a custom `STACK_ID`, use `chormanager-web-<STACK_ID>` as the `$upstream_app`.

## Duplicating the Stack on the Same Host

Deploy a second stack under a **different Portainer stack name**. Container names,
the `db_data`/`snappymail_data` volumes and the `internal`/`egress` networks are
project-prefixed, so they isolate automatically. The only shared surface is the
external `portainer_network`; the `web` and `snappymail` aliases on it are
suffixed with `STACK_ID`. So for a duplicate you only change `.env`:

- give it a unique `STACK_ID` (e.g. `prod2`) — this re-points both aliases,
- set a new `APP_URL` / hostname and fresh secrets,
- add a SWAG proxy-conf whose `$upstream_app` / `$upstream_sm` use the same
  `STACK_ID` suffix (`chormanager-web-prod2`, `chormanager-snappymail-prod2`).

No compose edits are needed.

### Troubleshooting a duplicated stack

`app` restarting with `Access denied for user '<user>'@'<ip>'` while `db` reports
healthy: MySQL only evaluates `MYSQL_USER` / `MYSQL_PASSWORD` on the very first
start with an empty data directory. If the stack was deployed once before with
different credentials, the volume keeps the old password and later env changes
are ignored silently. Either fix the user in place:

```bash
docker exec -it <stack>-db-1 sh -c '
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
    ALTER USER \"$MYSQL_USER\"@\"%\" IDENTIFIED BY \"$MYSQL_PASSWORD\";
    FLUSH PRIVILEGES;"'
```

or remove the stack's `db_data` volume and redeploy (destroys that stack's
database). Note that `$` in a password is interpolated by Portainer and Compose;
escape it as `$$` or avoid the character.

A failing `app` no longer takes the rest of the stack down: `web` resolves the
FastCGI upstream at request time, so it stays up and answers 502 until `app`
recovers.

The `web` service's own Nginx layer has a fixed `client_max_body_size 100m;` baked
into the image (`nginx.conf`). Keep the SWAG `client_max_body_size` at or below
that value — the effective upload limit is the smallest limit in the proxy chain.

## Webmail (SnappyMail)

The mailbox feature lets each user open a webmail client (SnappyMail) that logs
straight into their IMAP mailbox via a short-lived single-sign-on token.

> **Optional:** SnappyMail ist per `FEATURE_WEBMAIL` steuerbar (Default `false`).
> Bei `FEATURE_WEBMAIL=false` kann der `snappymail`-Service samt
> `SNAPPYMAIL_SSO_SECRET` komplett entfallen; Benutzer können stattdessen im
> Profil eine externe Webmail-URL hinterlegen, auf die das Mail-Badge verlinkt.

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
        # SWAG proxies to a variable upstream (for its resolver). With a variable
        # upstream, proxy_pass does NOT strip the /webmail/ prefix via a trailing
        # slash the way a literal upstream would — it forwards /webmail/?/... to
        # SnappyMail unchanged (and can even drop the query), so SnappyMail replies
        # with its HTML shell and its JSON/AJAX calls fail with
        # "Invalid Content-Type 'text/html'". Strip the prefix explicitly instead:
        rewrite ^/webmail/(.*) /$1 break;
        proxy_pass http://$upstream_sm:8888;
    }

    location /snappymail/ {
        include /config/nginx/proxy.conf;
        include /config/nginx/resolver.conf;
        set $upstream_sm chormanager-snappymail-prod;
        # No URI part on proxy_pass, so the original /snappymail/... asset path is
        # forwarded unchanged (again, don't rely on a trailing slash with a
        # variable upstream).
        proxy_pass http://$upstream_sm:8888;
    }
```

`/webmail/` serves the SnappyMail shell (the `/webmail/` prefix stripped by the
`rewrite`); `/snappymail/` passes its version-pinned static assets straight
through. The SnappyMail admin password
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

### Central log shipping (Grafana Alloy)

A Grafana Alloy instance running in the Loki stack discovers containers
host-wide through the Docker socket and ships their logs to Loki. This stack does
**not** run its own Alloy: Docker discovery is host-wide, so a second instance
would see the same containers and write every line twice.

Each service opts in through Docker labels:

| Label          | Value                 | Purpose                                            |
|----------------|-----------------------|----------------------------------------------------|
| `logs.job`     | `chormanager`         | Opt-in switch. Without it, Alloy skips the rules.   |
| `logs.stack`   | `${STACK_ID:-prod}`   | Separates duplicated stacks (prod vs. test).        |
| `logs.service` | `app` \| `web` \| `db` \| `snappymail` | Stable across stacks, unlike the container name. |
| `logs.format`  | `monolog` \| `raw`    | Selects the parsing stage. Only `app` writes JSON.  |

`logs.service` is deliberately not the container name: that name is unique per
stack (`chormanager-prod-app-1`) and therefore useless for comparing prod against
test.

Query the result in Grafana with `{job="chormanager"}`, narrow it down with
`{job="chormanager", service="app", level="ERROR"}`. The log line stays full JSON,
so the Monolog context remains reachable via `| json | event="..."`.

**The logging driver of every service must stay `json-file`.** Alloy reads the
logs through the Docker API; any other driver makes these containers invisible to
it and the stack silently disappears from Loki.

Request logging is switched off on both request-handling services, because the
reverse proxy already logs every request with the real client IP:

- `web` sets `access_log off;` in `nginx.conf`, so only its error log is shipped.
- `app` sets `access.log = /dev/null` in `php-fpm.d/zz-access-log.conf`, which
  overrides the base image's `docker.conf`. Without it, the FastCGI access log
  would write a plain-text line per request into the same stream as the Monolog
  JSON.

## Backup

### Where in-app backups live

Backups created in the application are stored in the `backup_data` volume,
mounted at `BACKUP_DIR` (default `/var/backups/chormanager`) on the `app`
service. The volume is what makes them survive image updates and stack
recreations — a backup directory in the container's writable layer is discarded
on every recreate, and the application would silently start over with an empty
list. The entrypoint runs `mkdir -p` plus `chown -R www-data:www-data` on that
path at every start, so a freshly created (root-owned) volume needs no manual
preparation.

Upgrading a stack that predates the volume: the existing backups are inside the
old container and are lost on redeploy. Download the ones you want to keep
**before** applying the new compose file.

### Restoring a downloaded backup

The download only yields the `.sql.gz` dump, not its `.json` metadata sidecar,
which the application needs to list and verify the backup. To make a downloaded
dump restorable again, put both files back into `BACKUP_DIR` under their
original names and recreate the sidecar if you no longer have it:

```bash
docker cp backup_manual_<timestamp>_<hash>.sql.gz <stack>-app-1:/var/backups/chormanager/
docker cp backup_manual_<timestamp>_<hash>.json   <stack>-app-1:/var/backups/chormanager/
docker exec <stack>-app-1 chown www-data:www-data /var/backups/chormanager/backup_manual_<timestamp>_<hash>.*
```

The `sha256` in the sidecar must match the dump (`sha256sum`), otherwise the
restore aborts with an integrity error. Stored IMAP passwords only decrypt if
`MAIL_CREDENTIAL_KEY` still matches the `mail_key_id` recorded in the sidecar;
see the key rotation section in the main `README.md`.

### Manual dump / restore

```bash
docker compose -f docker-compose.prod.yml exec db \
  mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" "$DB_DATABASE" > backup.sql
```

Restore:

```bash
docker compose -f docker-compose.prod.yml exec -T db \
  mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$DB_DATABASE" < backup.sql
```

### In-app backup and the auth plugin

The in-app backup feature shells out to `mysqldump` from the `app` image. That
image is Alpine-based, whose `mysql-client` is really the MariaDB client. It
cannot load the `caching_sha2_password` client plugin, so if the DB user
authenticates with `caching_sha2_password` (the MySQL 8.4 default), backups fail
with `error 1045 ... Plugin caching_sha2_password could not be loaded`.

The compose file pins `--authentication-policy=mysql_native_password`, so users
created on a **fresh** `db_data` volume use `mysql_native_password` and backups
work out of the box. A volume initialised **before** this setting was added keeps
the old plugin; fix the existing user once:

```bash
docker exec -it <stack>-db-1 sh -c '
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
    ALTER USER \"$MYSQL_USER\"@\"%\" IDENTIFIED WITH mysql_native_password BY \"$MYSQL_PASSWORD\";
    FLUSH PRIVILEGES;"'
```

Verify with `SELECT user, host, plugin FROM mysql.user WHERE user = \"$MYSQL_USER\";`
— `plugin` must read `mysql_native_password`.

## Security Notes

- Keep all secrets in Portainer stack variables or an external secret store, never in Git.
- Do not publish MySQL or the internal web container directly to the host.
- Terminate TLS in SWAG and serve the app only through HTTPS.
- Update image tags deliberately instead of relying on long-unpatched `latest` deployments.
