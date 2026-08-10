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
| `WEBMAIL_SSO_SECRET`      | Shared secret app ⇄ Tachyon plugin (`openssl rand -base64 32`)       | -               | **Yes**  |
| `APP_URL`                 | Public HTTPS URL, used for the webmail SSO redirect                  | -               | **Yes**  |
| `MAIL_ALLOW_PRIVATE_HOSTS`| Allow IMAP hosts on private/loopback networks (SSRF guard opt-out)   | `0`             | No       |
| `WEBMAIL_UPLOAD_MAX_SIZE` | Upload limit inside the webmail container                            | `25M`           | No       |
| `WEBMAIL_MEMORY_LIMIT`    | PHP memory limit inside the webmail container                        | `128M`          | No       |
| `BACKUP_DIR`              | In-app backup directory; must be inside the `backup_data` volume     | `/var/backups/chormanager` | No |
| `BACKUP_MAX_MANUAL`       | Manual backups kept; new ones are refused at the limit               | `5`             | No       |
| `BACKUP_MAX_AUTO`         | Automatic backups kept; the oldest is rotated out                    | `7`             | No       |

SMTP is configured exclusively via environment variables. It is no longer managed in the application UI.

The mailbox / webmail feature is separate from the transactional SMTP above:
`MAIL_CREDENTIAL_KEY` protects each user's stored IMAP credentials, and
`WEBMAIL_SSO_SECRET` secures the single-sign-on hand-off into the Tachyon
container. `MAIL_CREDENTIAL_KEY` is required as soon as the mailbox settings are
visible in the profile, even if you do not deploy the webmail container.

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
the `db_data`/`snappymail_data` volumes (`snappymail_data` is a legacy name,
kept deliberately — see "Migration von SnappyMail auf Tachyon" below) and the
`internal`/`egress` networks are project-prefixed, so they isolate
automatically. The only shared surface is the external `portainer_network`;
the `web` and `webmail` aliases on it are suffixed with `STACK_ID`. So for a
duplicate you only change `.env`:

- give it a unique `STACK_ID` (e.g. `prod2`) — this re-points both aliases,
- set a new `APP_URL` / hostname and fresh secrets,
- add a SWAG proxy-conf whose `$upstream_app` / `$upstream_sm` use the same
  `STACK_ID` suffix (`chormanager-web-prod2`, `chormanager-webmail-prod2`).

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

## Webmail (Tachyon)

The mailbox feature lets each user open a webmail client (Tachyon) that logs
straight into their IMAP mailbox via a short-lived single-sign-on token.
Tachyon is the maintained fork of the discontinued SnappyMail.

> **Optional:** Das Webmail ist per `FEATURE_WEBMAIL` steuerbar (Default `false`).
> Bei `FEATURE_WEBMAIL=false` kann der `webmail`-Service samt
> `WEBMAIL_SSO_SECRET` komplett entfallen; Benutzer können stattdessen im
> Profil eine externe Webmail-URL hinterlegen, auf die das Mail-Badge verlinkt.

- The webmail image `ghcr.io/<owner>/chormanager-webmail:latest` is built
  automatically by the GitHub Actions workflow, alongside `app` and `web`. The
  `chormanager-sso` SSO plugin is baked into it (source: `dist/webmail/`), so
  no host-side bind-mounts are needed - it works from the Portainer web editor.
- Add the `webmail` service to the stack on the `proxy` network (it needs
  outbound access to reach IMAP/SMTP servers, so it must NOT sit on the
  internal-only network), plus the `snappymail_data` named volume (Legacy-Name,
  absichtlich beibehalten - siehe Migrationshinweis unten).
- Set `MAIL_CREDENTIAL_KEY`, `WEBMAIL_SSO_SECRET` and `APP_URL` (see the table
  above). `WEBMAIL_SSO_SECRET` is consumed by both `app` and `webmail`
  from the same variable, so the two sides always match.

Route `/webmail/` to Tachyon in your existing SWAG proxy config (same
`server_name` as the app, so the SSO stays same-origin), before the `location /`
block:

```nginx
    location /webmail/ {
        include /config/nginx/proxy.conf;
        include /config/nginx/resolver.conf;
        set $upstream_sm chormanager-webmail-prod;
        # SWAG proxies to a variable upstream (for its resolver). With a variable
        # upstream, proxy_pass does NOT strip the /webmail/ prefix via a trailing
        # slash the way a literal upstream would — it forwards /webmail/?/... to
        # Tachyon unchanged (and can even drop the query), so Tachyon replies
        # with its HTML shell and its JSON/AJAX calls fail with
        # "Invalid Content-Type 'text/html'". Strip the prefix explicitly instead:
        rewrite ^/webmail/(.*) /$1 break;
        proxy_pass http://$upstream_sm:8888;
    }

    location /tachyon/ {
        include /config/nginx/proxy.conf;
        include /config/nginx/resolver.conf;
        set $upstream_sm chormanager-webmail-prod;
        # No URI part on proxy_pass, so the original /tachyon/... asset path is
        # forwarded unchanged (again, don't rely on a trailing slash with a
        # variable upstream).
        proxy_pass http://$upstream_sm:8888;
    }
```

`/webmail/` serves the Tachyon shell (the `/webmail/` prefix stripped by the
`rewrite`); `/tachyon/` passes its version-pinned static assets straight
through. The admin password is auto-generated on first boot inside the volume;
retrieve it if needed with:

```bash
docker compose -f docker-compose.prod.yml exec webmail \
  cat /var/lib/tachyon/_data_/_default_/admin_password.txt
```

### Migration von SnappyMail auf Tachyon

Diese Schritte sind **nicht** durch das Deployment abgedeckt und müssen beim
Umstieg einmalig von Hand erledigt werden. Die Reihenfolge ist bewusst so
gewählt, dass die Anwendung dabei nie ungeplant komplett offline geht.

1. **Datensicherung (empfohlen):** Der erste Start von Tachyon gegen ein von
   SnappyMail 2.38.2 beschriebenes Volume ist der einzige an dieser Migration
   schwer umkehrbare Schritt und wurde lokal nur gegen ein frisches, leeres
   Volume verifiziert. Vor dem Deploy sichern:

   ```bash
   docker run --rm -v <stack>_snappymail_data:/data -v "$PWD":/b alpine tar czf /b/webmail-vol.tgz -C /data .
   ```

   `<stack>` ist dabei der tatsächliche Portainer-Stackname. Das Volume selbst
   wird danach unverändert weiterverwendet (siehe Schritt 5) — es ist weiterhin
   kein Datenexport und keine Volume-Migration nötig, die Sicherung ist nur ein
   Rücksprungpunkt für den Fall, dass Tachyon die bestehenden Daten nicht sauber
   übernimmt.
2. **GHCR-Paket-Sichtbarkeit:** `chormanager-webmail` ist ein neues GHCR-Paket.
   Neue GHCR-Pakete sind standardmäßig privat, daher schlägt der erste Pull auf
   dem Produktionshost mit `denied` fehl. Die Sichtbarkeit muss deshalb einmalig
   genauso gesetzt werden wie zuvor beim Paket `chormanager-snappymail` — ein
   erneuter Pull-Versuch behebt das nicht. Nach dem ersten erfolgreichen
   Workflow-Lauf auf GitHub: Profil bzw. Organisation → **Packages** →
   `chormanager-webmail` → **Package settings** → **Change visibility** → auf
   denselben Wert setzen wie beim alten Paket. Alternativ per CLI:

   ```bash
   gh api -X PATCH /user/packages/container/chormanager-webmail \
     -f visibility=public
   ```

   Danach auf dem Host verifizieren, dass der Pull durchgeht — noch bevor der
   Stack aktualisiert wird:

   ```bash
   docker pull ghcr.io/<owner>/chormanager-webmail:latest
   ```

3. **Portainer-Env:** `WEBMAIL_SSO_SECRET` mit dem bisherigen Wert von
   `SNAPPYMAIL_SSO_SECRET` anlegen — die `SNAPPYMAIL_*`-Variablen dabei
   **noch nicht entfernen**. Portainer speichert Stack-Env-Änderungen, indem es
   einen Redeploy mit dem aktuell hinterlegten Compose-File auslöst, und dieses
   alte Compose-File verlangt `SNAPPYMAIL_SSO_SECRET` weiterhin zwingend über
   `:?set in Portainer` — sowohl für `app` als auch für `webmail`. Wird die
   Variable schon jetzt gelöscht, bricht genau dieser Redeploy ab und reißt die
   gesamte Anwendung mit, nicht nur das Webmail. Der Wert muss auf App- und
   Webmail-Seite identisch bleiben. Wer stattdessen über `--env-file` bzw. die
   `dist/.env`-Datei deployt (siehe "Portainer Deployment" oben), nimmt dieselbe
   Umbenennung dort in der `.env`-Datei vor.
4. **SWAG-Config:** `location /snappymail/` in `location /tachyon/` umbenennen
   und in **beiden** Location-Blöcken den Upstream von
   `chormanager-snappymail-prod` auf `chormanager-webmail-prod` umstellen. Das
   kann vor oder direkt nach dem nächsten Schritt passieren: vorher zeigt
   `/webmail/` kurz auf ein noch nicht existierendes Ziel, direkt danach fehlen
   bis zur Anpassung CSS/JS — in beiden Fällen ist die Lücke kurz und bleibt auf
   das Webmail beschränkt.
5. **Stack neu deployen** mit dem neuen `docker-compose.prod.yml`. Aktiviere
   dabei in Portainer die Option **„Prune services“** (oder entferne den alten
   `snappymail`-Container danach manuell) — Portainer entfernt Services, die aus
   dem Compose-File verschwunden sind, sonst nicht automatisch. Der alte
   Container läuft mit `restart: unless-stopped` einfach weiter, behält seinen
   Netzwerk-Alias und mountet über `/var/lib/snappymail` weiterhin dasselbe
   Volume wie das neue `webmail` unter `/var/lib/tachyon`. Zwei
   Mailserver-Hauptversionen, die gleichzeitig in dasselbe
   `_data_/_default_/`-Verzeichnis schreiben, riskieren eine korrupte
   Konfiguration. Das Volume selbst wird unverändert weiterverwendet, nur unter
   dem neuen Mountpfad `/var/lib/tachyon` — es ist kein Datenexport und keine
   Volume-Migration nötig. Admin-Passwort, Domain-Konfigurationen und
   Benutzereinstellungen bleiben erhalten.
6. **Verifizieren:** `/webmail/` öffnet die Tachyon-Oberfläche, der SSO-Login
   aus dem Profil funktioniert, und `docker compose -f docker-compose.prod.yml
   ps` zeigt keinen `snappymail`-Container mehr.
7. **Aufräumen:** Erst jetzt die nicht mehr benötigten `SNAPPYMAIL_*`-Variablen
   (`SNAPPYMAIL_SSO_SECRET`, `SNAPPYMAIL_UPLOAD_MAX_SIZE`,
   `SNAPPYMAIL_MEMORY_LIMIT`) aus der Portainer-Stack-Env bzw. der `.env`-Datei
   entfernen.
8. **Grafana:** Das Dashboard `dist/grafana/chormanager-logs.json` erneut
   importieren. Ohne den Re-Import bleibt der alte Service-Filter des
   Rohlog-Panels aktiv und die Logzeilen des neuen `webmail`-Containers
   verschwinden dort stillschweigend.

## Operational Notes

- The stack copies the image contents into a named volume before startup so `app` and `web` serve the exact same code.
- The copy step clears the target volume first. That avoids stale files during image updates, which is important for Portainer-based redeployments.
- Database migrations run automatically when `app` starts.
- The internal web service exposes `/healthz`, served by Nginx itself and used for the `web` container health check. The application's own `/health` route is served by PHP and therefore also reports on `app`.

## Maintenance Mode

The `web` container keeps serving while `app` is being replaced, so the maintenance page lives in Nginx, not in the application. There are two layers.

### Automatic, during every update

No action required. While `app` is gone - boot, database wait and Phinx migrations all happen before php-fpm accepts connections - Nginx answers `502`/`504` with a self-contained maintenance page baked into the web image. The status code is preserved, so search engines never index it as content and the response is not cached.

This covers the entire window of a normal image update: pull the new images in Portainer and redeploy, nothing else to do.

### Manual flag, for planned work

For a longer window - database restore, manual repairs, anything not covered by a plain redeploy - set the flag before you start. `maintenance_data` is a named volume mounted at `/maintenance` in the `web` container, so the flag survives recreates:

```bash
docker exec <web-container> touch /maintenance/on   # maintenance page for everyone
docker exec <web-container> rm -f  /maintenance/on  # back to normal
```

Every request then gets `503` plus the maintenance page. `/healthz` stays exempt, so the container does not turn unhealthy while the flag is set.

In Portainer this works without a shell on the host: *Containers → chormanager-web → Console → Connect*, then run the same command.

### Smoke-testing while the flag is set

Create a token file and send its name back as a cookie. The token exists only inside the volume, never in the repository or in an environment variable:

```bash
docker exec <web-container> sh -c 'touch /maintenance/bypass-$(head -c16 /dev/urandom | od -An -tx1 | tr -d " \n")'
docker exec <web-container> ls /maintenance
```

Set the cookie `cm_maint=<token>` in your browser for the site's domain and you reach the application normally while everyone else sees the maintenance page. Remove the token file afterwards:

```bash
docker exec <web-container> rm -f /maintenance/bypass-<token>
```

### Caveat

A failed migration leaves `app` restarting and every visitor on the maintenance page indefinitely, with no other visible symptom - the page is friendly either way. That blind spot is covered by the alert rules below; without them, check `logs -f app` after every update that ships a migration.

## Alerting

### Boot events

The entrypoint writes its lifecycle into the same JSON log stream as the application, so Loki can be queried for the state of a start:

| Event                      | Level    | Meaning                                                  |
|----------------------------|----------|----------------------------------------------------------|
| `app.boot.started`         | INFO     | Container start begun, waiting for the database.          |
| `app.boot.db_wait_timeout` | CRITICAL | Database not reachable in time, start aborted.            |
| `app.boot.migration_failed`| CRITICAL | Phinx migration failed, start aborted. Carries `exit_code`.|
| `app.boot.completed`       | INFO     | php-fpm accepts requests.                                 |

These events ignore `APP_LOG_LEVEL` on purpose: they carry the alerting, and a restrictive log level would otherwise switch off start monitoring unnoticed.

### Rules

`grafana/chormanager-alerts.yaml` provisions three rules into the Grafana instance that already holds the dashboard:

| Rule                                          | Fires when                                                                     |
|-----------------------------------------------|--------------------------------------------------------------------------------|
| `ChorManager App startet wiederholt neu`      | 3+ `app.boot.started` within 15 min - crash loop, whatever the cause.           |
| `ChorManager App-Start abgebrochen`           | Any `app.boot.migration_failed` or `app.boot.db_wait_timeout` within 10 min.    |
| `ChorManager Anwendung dauerhaft nicht erreichbar` | Nginx logs upstream failures continuously for 10 min.                      |

The first two read the boot events. The third reads the `web` error log instead and is the net for the case where `app` dies so early that it ships nothing at all - a broken image, a missing environment variable. Its 5-minute window plus `for: 10m` keeps an ordinary update quiet: those errors stop as soon as the new app is up.

Install:

1. Replace `LOKI_DATASOURCE_UID` in the file with the UID of your Loki data source (*Connections → Data sources → Loki*, the UID is in the URL).
2. Mount the file into `/etc/grafana/provisioning/alerting/` of the Grafana container and restart it. Grafana creates the `ChorManager` folder itself.
3. Check *Alerting → Alert rules*; all three must show up as provisioned.

Routing is left to the existing notification policy - the rules only carry `severity: critical` and `app: chormanager` labels. This file deliberately ships no policy of its own, because a provisioned notification policy replaces the entire tree of the instance.

### Not covered

A maintenance flag left switched on. `web` runs with `access_log off;`, so a fully working stack behind the flag produces no log line to alert on. Remove the flag as the last step of planned work.

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
| `logs.service` | `app` \| `web` \| `db` \| `webmail` | Stable across stacks, unlike the container name. |
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
