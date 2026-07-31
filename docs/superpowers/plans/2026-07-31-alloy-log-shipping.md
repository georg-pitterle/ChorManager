# Alloy-Log-Shipping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Logs des ChorManager-Prod-Stacks laufen strukturiert und nach Stack, Service und Log-Level filterbar in Loki auf.

**Architecture:** Der Stack markiert seine Container per Docker-Label als Log-Quelle und deklariert dabei sein Log-Format. Das bereits vorhandene, host-weite Grafana Alloy im Loki-Stack übersetzt diese Labels in Loki-Labels und bricht das Monolog-JSON nur bei den so markierten Containern auf. Es entsteht kein zweiter Alloy-Container und kein zweiter Docker-Socket-Mount.

**Tech Stack:** Docker Compose (Portainer), Grafana Alloy (`discovery.docker`, `loki.process`), Loki, Nginx 1.28, Monolog `JsonFormatter`.

**Spec:** `docs/superpowers/specs/2026-07-31-alloy-log-shipping-design.md`

## Global Constraints

- Der Docker-Logging-Driver aller Services bleibt `json-file`. `loki.source.docker` liest über die Docker-API; ein anderer Driver macht die Container für Alloy unsichtbar.
- Es wird **kein** Alloy-Service in den ChorManager-Stack aufgenommen und **kein** `/var/run/docker.sock` in diesen Stack gemountet.
- Label-Präfix ist `logs.`, die vier Keys heißen exakt `logs.job`, `logs.stack`, `logs.service`, `logs.format`.
- `logs.job` ist immer `chormanager`, `logs.stack` immer `${STACK_ID:-prod}`.
- `logs.format` ist `monolog` ausschließlich beim `app`-Service, bei allen anderen `raw`.
- Repo-Textdateien werden mit LF-Zeilenenden geschrieben (`instructions/line-endings.md`). Ausgenommen sind nur `.bat`, `.cmd`, `.ps1`.
- Kommentare in `nginx.conf` und in der Alloy-Config werden auf Englisch geschrieben, passend zum Bestand dieser Dateien. Der Spec und dieser Plan sind Deutsch.
- Es entsteht kein PHP-Code, keine Migration und keine Seed-Änderung. `instructions/feature-tests.md` und `instructions/seed.md` greifen hier nicht — Begründung im Spec unter „Nicht Teil dieses Specs".

## Aufgabenüberblick

| Task | Deliverable | Repo? |
|------|-------------|-------|
| 1 | Container-Labels an allen vier Prod-Services | ja |
| 2 | Nginx-Access-Log abgeschaltet | ja |
| 3 | `dist/README.md` dokumentiert Labels und Driver-Zwang | ja |
| 4 | `config.alloy` am Host ersetzt und neu geladen | nein, Host |
| 5 | Stack-Redeploy und Verifikation in Loki | nein, Host |

Task 1–3 sind reine Repo-Änderungen und deployen nichts. Task 4 muss **vor** Task 5 laufen: die neuen Relabel-Regeln sind ohne Container-Labels ein No-op, die Alloy-Änderung kann also gefahrlos vorlaufen und bleibt bei einem Fehler isoliert sichtbar.

---

### Task 1: Container-Labels im Prod-Stack

**Files:**
- Modify: `dist/docker-compose.prod.yml` (vier `labels:`-Blöcke, je nach dem `restart: unless-stopped` des Services)

**Interfaces:**
- Consumes: nichts
- Produces: die Docker-Labels `logs.job`, `logs.stack`, `logs.service`, `logs.format`. Task 4 liest sie in Alloy als `__meta_docker_container_label_logs_job`, `__meta_docker_container_label_logs_stack`, `__meta_docker_container_label_logs_service`, `__meta_docker_container_label_logs_format` — Punkte werden dabei zu Unterstrichen.

- [ ] **Step 1: Labels am `app`-Service ergänzen**

Suchen:

```yaml
  app:
    image: ghcr.io/georg-pitterle/chormanager-app:latest
    restart: unless-stopped
    working_dir: /var/www/html
```

Ersetzen durch:

```yaml
  app:
    image: ghcr.io/georg-pitterle/chormanager-app:latest
    restart: unless-stopped
    # Opt-in for the central Grafana Alloy running in the Loki stack. Alloy
    # discovers containers host-wide via the Docker socket; these labels tell it
    # which streams belong together and how to parse them. logs.format=monolog
    # switches on JSON parsing for this container only.
    labels:
      logs.job: "chormanager"
      logs.stack: "${STACK_ID:-prod}"
      logs.service: "app"
      logs.format: "monolog"
    working_dir: /var/www/html
```

- [ ] **Step 2: Labels am `db`-Service ergänzen**

Suchen:

```yaml
  db:
    image: mysql:8.4
    restart: unless-stopped
    command:
```

Ersetzen durch:

```yaml
  db:
    image: mysql:8.4
    restart: unless-stopped
    labels:
      logs.job: "chormanager"
      logs.stack: "${STACK_ID:-prod}"
      logs.service: "db"
      logs.format: "raw"
    command:
```

- [ ] **Step 3: Labels am `web`-Service ergänzen**

Suchen:

```yaml
    hostname: chormanager-web-${STACK_ID:-prod}
    restart: unless-stopped
    depends_on:
      - app
```

Ersetzen durch:

```yaml
    hostname: chormanager-web-${STACK_ID:-prod}
    restart: unless-stopped
    labels:
      logs.job: "chormanager"
      logs.stack: "${STACK_ID:-prod}"
      logs.service: "web"
      logs.format: "raw"
    depends_on:
      - app
```

- [ ] **Step 4: Labels am `snappymail`-Service ergänzen**

Suchen:

```yaml
  snappymail:
    image: ghcr.io/georg-pitterle/chormanager-snappymail:latest
    restart: unless-stopped
    environment:
```

Ersetzen durch:

```yaml
  snappymail:
    image: ghcr.io/georg-pitterle/chormanager-snappymail:latest
    restart: unless-stopped
    labels:
      logs.job: "chormanager"
      logs.stack: "${STACK_ID:-prod}"
      logs.service: "snappymail"
      logs.format: "raw"
    environment:
```

- [ ] **Step 5: Compose-Datei validieren**

Die Datei nutzt `${VAR:?set in Portainer}`, ist also ohne gesetzte Variablen nicht auflösbar. Für den reinen Syntax- und Label-Check reichen Dummy-Werte.

PowerShell im Repo-Root:

```powershell
$env:DB_DATABASE="x"; $env:DB_USERNAME="x"; $env:DB_PASSWORD="x"
$env:SMTP_HOST="x"; $env:SMTP_USERNAME="x"; $env:SMTP_PASSWORD="x"
$env:SMTP_FROM_EMAIL="x@example.org"; $env:MAIL_CREDENTIAL_KEY="x"
$env:SNAPPYMAIL_SSO_SECRET="x"; $env:APP_URL="https://example.org"
$env:MYSQL_ROOT_PASSWORD="x"
docker compose -f dist/docker-compose.prod.yml config
```

Erwartet: YAML-Ausgabe ohne Fehler. Im Output muss bei **jedem** der vier Services ein `labels:`-Block stehen, und `logs.stack` muss zu `prod` aufgelöst sein (weil `STACK_ID` nicht gesetzt ist, greift der Default).

Gegenprobe, dass `STACK_ID` wirklich durchschlägt:

```powershell
$env:STACK_ID="test"; docker compose -f dist/docker-compose.prod.yml config | Select-String "logs.stack"
```

Erwartet: vier Zeilen, alle mit dem Wert `test`. Danach `$env:STACK_ID=""` zurücksetzen.

Schlägt `docker compose` mit `services.app.labels must be a mapping` fehl, ist die Einrückung des `labels:`-Blocks um eine Ebene verrutscht.

- [ ] **Step 6: Zeilenenden normalisieren**

```powershell
$f = "d:\Proggen\ChorManager\dist\docker-compose.prod.yml"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
```

- [ ] **Step 7: Commit**

```bash
git add dist/docker-compose.prod.yml
git commit -m "feat(ops): Log-Labels fuer Alloy an Prod-Services"
```

---

### Task 2: Nginx-Access-Log abschalten

**Files:**
- Modify: `nginx.conf:1-6` (Direktive im `server`-Block)

**Interfaces:**
- Consumes: nichts
- Produces: der `web`-Container schreibt nur noch seinen Error-Log auf stderr. Task 5 verifiziert, dass in Loki unter `service="web"` keine Access-Zeilen mehr ankommen.

Hintergrund: Vor dem `web`-Container steht ein Proxy, der Requests bereits loggt — und zwar mit der echten Client-IP. Der Access-Log hier sieht als `$remote_addr` nur die Proxy-IP, ist also ein ärmeres Duplikat. Der Error-Log dagegen bleibt und ist der eigentliche Wert: `upstream timed out` gegen `fastcgi_read_timeout 120s`, `client intended to send too large body` bei 413 durch `client_max_body_size 100m`, Connect-Fehler gegen `chormanager-fpm`.

- [ ] **Step 1: Direktive ergänzen**

Suchen:

```nginx
server {
    listen 80;
    server_name localhost;
    client_max_body_size 100m;
```

Ersetzen durch:

```nginx
server {
    listen 80;
    server_name localhost;

    # The reverse proxy in front of this container already logs every request,
    # including the real client IP. Here $remote_addr is only ever the proxy, so
    # the access log is a poorer duplicate and is dropped at the source instead
    # of being filtered out later in the log pipeline. The error log stays: it
    # carries the upstream timeouts, 413 rejections and FastCGI connect errors
    # that the proxy only sees as bare status codes.
    access_log off;

    client_max_body_size 100m;
```

- [ ] **Step 2: Nginx-Konfiguration validieren**

PowerShell im Repo-Root:

```powershell
docker run --rm -v "${PWD}/nginx.conf:/etc/nginx/conf.d/default.conf:ro" nginx:1.28-alpine nginx -t
```

Erwartet:

```
nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
nginx: configuration file /etc/nginx/nginx.conf test is successful
```

Kommt stattdessen `"access_log" directive is not allowed here`, steht die Direktive außerhalb des `server`-Blocks.

- [ ] **Step 3: Zeilenenden normalisieren**

```powershell
$f = "d:\Proggen\ChorManager\nginx.conf"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
```

- [ ] **Step 4: Commit**

```bash
git add nginx.conf
git commit -m "feat(ops): Nginx-Access-Log abschalten, Proxy loggt Requests bereits"
```

---

### Task 3: Deployment-Dokumentation

**Files:**
- Modify: `dist/README.md:212-219` (Abschnitt `## Logs and Health`)

**Interfaces:**
- Consumes: das Label-Schema aus Task 1
- Produces: nichts, wovon spätere Tasks abhängen

- [ ] **Step 1: Unterabschnitt ergänzen**

Suchen:

```markdown
## Logs and Health

```bash
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f web
docker compose -f docker-compose.prod.yml logs -f app
docker compose -f docker-compose.prod.yml logs -f db
```
```

Ersetzen durch:

````markdown
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

The `web` service has `access_log off;` set in `nginx.conf` because the reverse
proxy already logs every request with the real client IP. Only its error log is
shipped.
````

- [ ] **Step 2: Zeilenenden normalisieren**

```powershell
$f = "d:\Proggen\ChorManager\dist\README.md"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
```

- [ ] **Step 3: Commit**

```bash
git add dist/README.md
git commit -m "docs(ops): Log-Shipping und Label-Konvention dokumentieren"
```

---

### Task 4: Alloy-Konfiguration am Host

**Files:**
- Modify: `/data/compose/3/config.alloy` **am Host `gpit`, nicht im Repo**

**Interfaces:**
- Consumes: die Docker-Labels aus Task 1 (die zu diesem Zeitpunkt noch nicht deployed sein müssen)
- Produces: die Loki-Labels `job`, `stack`, `service`, `level`. Task 5 fragt genau diese ab.

Diese Datei liegt außerhalb des Repos. Der vollständige Zielzustand steht unten, damit die Änderung ohne Zugriff auf den Spec reproduzierbar ist. Geändert sind nur `discovery.relabel "getting_started"` und `loki.process "getting_started"`; alle übrigen Komponenten bleiben identisch.

- [ ] **Step 1: Bestehende Config sichern**

Am Host:

```bash
cp /data/compose/3/config.alloy /data/compose/3/config.alloy.bak
```

- [ ] **Step 2: Neue Config schreiben**

Vollständiger Inhalt von `/data/compose/3/config.alloy`:

```alloy
// This component is responsible for disovering new containers within the docker environment
discovery.docker "getting_started" {
        host = "unix:///var/run/docker.sock"
        refresh_interval = "5s"
}

// This component is responsible for relabeling the discovered containers
discovery.relabel "getting_started" {
        targets = []

        rule {
                source_labels = ["__meta_docker_container_name"]
                regex         = "/(.*)"
                target_label  = "container"
        }

        // Opt-in labels set by the container itself. Containers without them get
        // empty values, and Loki drops empty labels, so unlabelled stacks on this
        // host keep behaving exactly as before.
        rule {
                source_labels = ["__meta_docker_container_label_logs_job"]
                target_label  = "job"
        }
        rule {
                source_labels = ["__meta_docker_container_label_logs_stack"]
                target_label  = "stack"
        }
        rule {
                source_labels = ["__meta_docker_container_label_logs_service"]
                target_label  = "service"
        }
        rule {
                source_labels = ["__meta_docker_container_label_logs_format"]
                target_label  = "format"
        }
}

// This component is responsible for collecting logs from the discovered containers
loki.source.docker "getting_started" {
        host             = "unix:///var/run/docker.sock"
        targets          = discovery.docker.getting_started.targets
        forward_to       = [loki.process.getting_started.receiver]
        relabel_rules    = discovery.relabel.getting_started.rules
        refresh_interval = "5s"
}

local.file_match "letsencrypt_logs" {
        path_targets = [
                { "__path__" = "/var/log/letsencrypt/**/*.log", "service_name" = "letsencrypt" },
        ]
}

loki.source.file "letsencrypt" {
        targets = local.file_match.letsencrypt_logs.targets
        forward_to = [loki.process.getting_started.receiver]
}

// This component is responsible for processing the logs (In this case adding static labels)
loki.process "getting_started" {
    stage.static_labels {
        values = {
            env = "production",
        }
    }

    // Break up the Monolog JSON, but only for containers that declared that
    // format. Without the selector this stage would also run against nginx error
    // lines and MySQL messages and fail to parse on every single one.
    stage.match {
        selector = "{format=\"monolog\"}"

        stage.json {
            expressions = {
                level = "level_name",
                ts    = "datetime",
            }
        }

        stage.labels {
            values = { level = "" }
        }

        // Use the timestamp Monolog wrote, not the time Alloy happened to read
        // the line. Monolog emits microseconds with a UTC offset, which matches
        // RFC3339Nano.
        stage.timestamp {
            source = "ts"
            format = "RFC3339Nano"
        }
    }

    // Control label only, it has no business in Loki's index.
    stage.label_drop {
        values = ["format"]
    }

    forward_to = [loki.write.getting_started.receiver]
}

// This component is responsible for writing the logs to Loki
loki.write "getting_started" {
        endpoint {
                url  = "http://loki:3100/loki/api/v1/push"
        }
}

// Enables the ability to view logs in the Alloy UI in realtime
livedebugging {
  enabled = true
}
```

- [ ] **Step 3: Syntax prüfen, bevor etwas neu startet**

Containernamen ermitteln und formatieren lassen:

```bash
docker ps --filter "ancestor=grafana/alloy" --format "{{.Names}}"
docker exec <alloy-container> alloy fmt /etc/alloy/config.alloy
```

Erwartet: die formatierte Config auf stdout, Exit-Code 0, keine Fehlermeldung. Bei einem Syntaxfehler meldet `alloy fmt` Zeile und Spalte und schreibt nichts — dann korrigieren und wiederholen, **bevor** Step 4 läuft.

Findet der `--filter` nichts, weil das Image anders heißt: `docker ps --format "{{.Names}}\t{{.Image}}" | grep -i alloy`.

- [ ] **Step 4: Alloy neu laden**

```bash
docker kill -s HUP <alloy-container>
```

SIGHUP lädt die Config neu, ohne den Container zu starten — die Lesepositionen der Log-Quellen bleiben dabei erhalten.

- [ ] **Step 5: Komponenten prüfen**

Alloy-UI auf Port `12345` öffnen, Tab „Components".

Erwartet: alle Komponenten `Healthy`, insbesondere `loki.process.getting_started` und `discovery.relabel.getting_started`. Steht dort ein Fehler, mit Step 6 zurückrollen.

Gegenprobe, dass die bestehenden Quellen weiterlaufen — in Grafana:

```logql
{service_name="letsencrypt"}
```

Erwartet: die Dateiquelle liefert weiter Zeilen. Sie läuft durch dieselbe Process-Komponente; `stage.match` greift dort mangels `format`-Label nicht, und `stage.label_drop` auf ein nicht vorhandenes Label ist ein No-op.

- [ ] **Step 6 (nur im Fehlerfall): Rollback**

```bash
cp /data/compose/3/config.alloy.bak /data/compose/3/config.alloy
docker kill -s HUP <alloy-container>
```

Die Container-Labels aus Task 1 bleiben dann wirkungslos liegen, ein Redeploy des Stacks ist dafür nicht nötig.

---

### Task 5: Stack-Redeploy und Verifikation

**Files:**
- keine; Deployment über Portainer, Prüfung in Grafana

**Interfaces:**
- Consumes: die Labels aus Task 1 und die Alloy-Regeln aus Task 4
- Produces: den Nachweis, dass die Pipeline steht

- [ ] **Step 1: Stack neu deployen**

In Portainer den ChorManager-Stack mit der geänderten `docker-compose.prod.yml` neu deployen. Danach prüfen, dass die Labels wirklich am Container hängen:

```bash
docker inspect -f '{{ json .Config.Labels }}' <app-container> | grep logs
```

Erwartet: alle vier `logs.*`-Labels mit den Werten aus Task 1. Fehlen sie, hat Portainer die alte Compose-Version deployed.

Alloy übernimmt die neuen Targets nach spätestens 5 Sekunden (`refresh_interval`).

- [ ] **Step 2: Grundabfrage**

```logql
{job="chormanager"}
```

Erwartet: Zeilen von allen vier Services. Im Label-Browser müssen `service` mit vier Werten und `stack` mit `prod` erscheinen.

Kommt nichts: prüfen, ob der Logging-Driver noch `json-file` ist (`docker inspect -f '{{ .HostConfig.LogConfig.Type }}' <app-container>`).

- [ ] **Step 3: JSON-Parsing prüfen**

```logql
{job="chormanager", service="app", level="ERROR"}
```

Erwartet: nur Fehlerzeilen. Existiert das Label `level` gar nicht, hat `stage.match` nicht gegriffen — dann sitzt `logs.format` nicht oder nicht als `monolog` am App-Container.

- [ ] **Step 4: Kontext prüfen**

```logql
{job="chormanager", service="app"} | json | event="mail.queue.sent"
```

Erwartet: die Zeilen des Mail-Queue-Workers. Das belegt, dass die Log-Zeile vollständiges JSON geblieben ist und der `event`-Key aus `instructions/logging.md` zur Abfragezeit erreichbar bleibt, ohne dafür ein Label zu verbrauchen.

Ist gerade keine Mail gelaufen, taugt jeder andere Event genauso — `| json | event != ""` zeigt, welche es gibt.

- [ ] **Step 5: Aktiv gegen Fehler prüfen**

Diese drei Prüfungen laufen gegen einen Fehlschlag, nicht für den Erfolg. Alle drei müssen zutreffen:

1. **`format` darf im Grafana-Label-Browser nicht auftauchen.** Ist es da, hat `stage.label_drop` nicht gegriffen und das Steuerlabel liegt dauerhaft im Index.
2. **Die Zeitstempel der App-Zeilen müssen zur `datetime` im JSON passen**, nicht zur Empfangszeit. Dazu eine Zeile in Grafana aufklappen und den angezeigten Zeitstempel mit dem `datetime`-Feld der Zeile vergleichen. Bei falschem Format-String fällt Alloy still auf die Docker-Zeit zurück — sichtbar nur beim Hinsehen.
3. **Ein Container aus einem fremden Stack muss unverändert ankommen.** In Grafana `{container="<name-eines-fremden-containers>"}` abfragen. Das ist der Beleg, dass die Änderung an der gemeinsam genutzten Alloy-Config keine anderen Stacks beschädigt hat.

- [ ] **Step 6: Nginx-Access-Log verifizieren (erst nach dem nächsten Image-Build)**

`access_log off;` aus Task 2 steckt im `web`-Image und wird erst wirksam, nachdem der Release-Branch gebaut und das Image im Stack gezogen wurde. Bis dahin laufen die Access-Zeilen mit — unschön, aber folgenlos, und kein Grund, Step 1–5 aufzuhalten.

Nach dem Image-Update:

```logql
{job="chormanager", service="web"}
```

Erwartet: keine Access-Zeilen im `combined`-Format mehr, nur noch Error-Log-Einträge. Zum Gegentest eine 404 auf dem Stack auslösen — sie darf hier **nicht** erscheinen, wohl aber im Proxy-Log.

---

## Definition of Done

- [ ] Task 1–3 committet, drei Commits
- [ ] `docker compose config` löst auf und zeigt vier `labels:`-Blöcke
- [ ] `nginx -t` erfolgreich
- [ ] Alloy nach dem Reload `Healthy`, letsencrypt-Quelle liefert weiter
- [ ] `{job="chormanager"}` zeigt vier Services
- [ ] `level`-Label existiert bei `service="app"`, `format`-Label existiert nirgends
- [ ] Ein fremder Stack loggt unverändert weiter
- [ ] `dist/README.md` nennt Label-Schema und `json-file`-Zwang
