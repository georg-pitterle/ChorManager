# Log-Shipping nach Loki über bestehendes Grafana Alloy

Datum: 2026-07-31

## Ziel

Die Logs des ChorManager-Prod-Stacks laufen strukturiert und filterbar in Loki auf.
Filterbar heißt: nach Stack (prod/test), nach Service (app/web/db/snappymail) und
beim App-Container zusätzlich nach Log-Level, wobei der Monolog-Kontext zur
Abfragezeit erreichbar bleibt.

## Ausgangslage

- Der App-Container schreibt bereits strukturiertes JSON auf `php://stderr`
  (`src/Logging/AppLoggerFactory.php`, Monolog `JsonFormatter`). Der Stream ist über
  `APP_LOG_STREAM` konfigurierbar, Default `php://stderr`.
- Jede Zeile trägt `level_name`, `channel`, `datetime` sowie `extra.service` und
  `extra.env` (`src/Settings.php`, Abschnitt `logging`). Der Kontext enthält laut
  `instructions/logging.md` verpflichtend einen `event`-Key.
- Alle vier Prod-Services loggen auf stdout/stderr mit Docker-Driver `json-file`
  (`dist/docker-compose.prod.yml`).
- `nginx.conf` setzt kein `log_format` und kein `access_log`, es gilt also der
  Default `combined` auf stdout.
- Auf demselben Host läuft bereits ein Grafana Alloy in einem eigenen Stack
  (Config unter `/data/compose/3/config.alloy`), zusammen mit Loki. Beide hängen im
  `portainer_network`.
- Dieses Alloy macht `discovery.docker` über `/var/run/docker.sock` und sammelt damit
  **host-weit** alle Container, einschließlich derer des ChorManager-Stacks. Als
  einziges Label wird bisher `container` gesetzt, dazu statisch `env = "production"`.
- Die ChorManager-Logs erreichen Loki also schon heute, aber als unstrukturierter
  Text-Blob mit einem einzigen Label.

## Entscheidungen

### Kein eigenes Alloy im ChorManager-Stack

Ursprünglich angedacht, verworfen. Docker-Discovery ist host-weit, nicht stack-weit:
ein zweites Alloy sähe dieselben Container wie das bestehende und würde **jede
Log-Zeile doppelt** nach Loki schreiben. Dazu käme ein zweiter Mount von
`/var/run/docker.sock` — ein Container mit Docker-Socket ist faktisch root auf dem
Host und stünde quer zu den `no-new-privileges`-Härtungen des Stacks.

Stattdessen: das vorhandene Alloy bleibt die einzige Sammelstelle.

### Opt-in über Container-Labels statt zentraler Namensregeln

Die Alternative wäre gewesen, in Alloy auf die Compose-Automatiklabels
`com.docker.compose.project` und `com.docker.compose.service` zu relabeln — ganz
ohne Änderung am ChorManager-Stack.

Entschieden wurde dagegen: Die Information „dieser Container schreibt Monolog-JSON"
entsteht im ChorManager-Stack und gehört dorthin, nicht in eine zentrale Config, die
sonst bei jedem neuen Stack angefasst werden muss. Die Alloy-Regeln bleiben dadurch
generisch und einmalig.

### Nginx-Access-Logs werden nicht verschickt

Vor dem `web`-Container steht ein Proxy, der Requests bereits loggt. Der
Access-Log des `web`-Containers ist davon ein Duplikat und sogar ärmer: als
`$remote_addr` sieht er nur die Proxy-IP, nie den echten Client.

Der nginx-**Error**-Log dagegen ist nicht ersetzbar — dort steht die Ursache zu dem,
was der Proxy nur als Statuscode sieht: `upstream timed out` gegen die
`fastcgi_read_timeout` von 120s, `client intended to send too large body` bei 413
durch `client_max_body_size`, Connect-Fehler gegen den `chormanager-fpm`-Alias bei 502.

Deshalb: `access_log off;` in `nginx.conf`, der Error-Log bleibt. Damit fällt der
Access-Teil schon an der Quelle weg statt später per `stage.drop` in Alloy.

`db` und `snappymail` bleiben angebunden. MySQL loggt im Normalbetrieb fast nichts,
ist also billig und im Ernstfall wertvoll.

### Nur `level` wird zusätzliches Label

`channel` ist konstant `chormanager`, `extra.service` ebenso, `extra.env` deckt sich
mit `stack` — alle drei wären Index-Ballast ohne Filterwert. `level` ist der eine
Filter, der ständig gebraucht wird, mit rund sechs Werten unkritisch für Lokis Index.

### Die Log-Zeile bleibt vollständiges JSON

Sie wird nicht durch das `message`-Feld ersetzt. Damit bleibt `context.event` zur
Abfragezeit über `| json | event="..."` erreichbar, ohne dafür Labels zu verbrennen.
Grafana rendert JSON-Zeilen von sich aus lesbar.

## Label-Schema

In `dist/docker-compose.prod.yml` erhält jeder Service einen `labels:`-Block:

| Service      | `logs.job`     | `logs.stack`         | `logs.service` | `logs.format` |
|--------------|----------------|----------------------|----------------|---------------|
| `app`        | `chormanager`  | `${STACK_ID:-prod}`  | `app`          | `monolog`     |
| `web`        | `chormanager`  | `${STACK_ID:-prod}`  | `web`          | `raw`         |
| `db`         | `chormanager`  | `${STACK_ID:-prod}`  | `db`           | `raw`         |
| `snappymail` | `chormanager`  | `${STACK_ID:-prod}`  | `snappymail`   | `raw`         |

Bedeutung der vier Labels:

- **`logs.job`** — der Opt-in-Schalter. Container ohne dieses Label durchlaufen die
  ChorManager-Regeln nicht.
- **`logs.stack`** — trennt prod von test. `STACK_ID` steuert im Stack ohnehin schon
  die Netzwerk-Aliase.
- **`logs.service`** — bewusst **nicht** der Container-Name. Der ist bei duplizierten
  Stacks eindeutig (`chormanager-prod-app-1`) und taugt damit nicht zum Vergleich
  zwischen prod und test. `service` ist über Stacks hinweg stabil.
- **`logs.format`** — steuert allein, welche Parsing-Stage greift. Reines
  Steuerlabel, wird vor dem Schreiben nach Loki wieder verworfen.

Docker-Labels mit Punkt erscheinen in Alloy als
`__meta_docker_container_label_logs_job`; Punkte werden zu Unterstrichen.

Kardinalität: `job` 1 Wert, `stack` 2, `service` 4, `level` ~6. Unkritisch.

## Alloy-Konfiguration

Zwei additive Eingriffe in `/data/compose/3/config.alloy`. Alle übrigen Komponenten
bleiben unverändert.

### a) `discovery.relabel "getting_started"` — vier Regeln ergänzen

```alloy
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
```

Container ohne diese Labels bekommen leere Werte, und leere Labels verwirft Loki.
Fremde Stacks auf dem Host laufen dadurch unverändert weiter.

### b) `loki.process "getting_started"` — zwei Stages ergänzen

Die bestehende `stage.static_labels` bleibt an erster Stelle stehen.

```alloy
    // Break up the Monolog JSON, but only for the app container
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

        stage.timestamp {
            source = "ts"
            format = "RFC3339Nano"
        }
    }

    // The control label must not end up in Loki's index
    stage.label_drop {
        values = ["format"]
    }
```

Begründungen:

- **`stage.match` ist der Schutzschild.** Ohne den Selector liefe die JSON-Stage auch
  gegen nginx-Error-Zeilen und MySQL-Meldungen und erzeugte pro Zeile einen
  Parse-Fehler.
- **`stage.timestamp`** setzt die Monolog-Zeit statt der Docker-Empfangszeit. Ohne das
  bekommt jede Zeile den Zeitpunkt, an dem Alloy sie gelesen hat, und bei Bursts
  verschiebt sich die Reihenfolge. Monolog liefert Mikrosekunden mit Offset, das passt
  auf `RFC3339Nano`.
- **`stage.label_drop`** entsorgt `format` vor dem Schreiben.

### Auswirkung auf die letsencrypt-Dateiquelle

`loki.source.file "letsencrypt"` reicht in dieselbe Process-Komponente. Das
`stage.match` greift dort nicht (kein `format`-Label), und `stage.label_drop` auf ein
nicht vorhandenes Label ist ein No-op. Die letsencrypt-Logs laufen unverändert durch.

### Bekannte Unsauberkeit: statisches `env`

`stage.static_labels` setzt `env = "production"` für alles auf dem Host, auch für einen
Test-Stack mit `STACK_ID=test`. Weil Process-Stages nach dem Relabeling laufen, gewinnt
der statische Wert gegen jeden aus Container-Labels abgeleiteten.

Deshalb ist **`stack` der verlässliche Diskriminator** zwischen prod und test, nicht
`env`. Eine Korrektur würde die statische Stage entfernen und damit die
letsencrypt-Quelle mitbetreffen; bewusst außerhalb dieses Specs.

## Rollout

Alloy zuerst, Stack danach. Die neuen Relabel-Regeln sind ohne die Container-Labels
ein No-op, die Alloy-Änderung kann also gefahrlos vorlaufen und bleibt bei einem
Fehler isoliert sichtbar.

1. `config.alloy` auf `/data/compose/3/` ersetzen
2. Syntax prüfen, bevor etwas neu startet:
   `docker exec <alloy-container> alloy fmt /etc/alloy/config.alloy`
3. Alloy neu laden: `docker kill -s HUP <alloy-container>` — Reload ohne Neustart,
   die Lesepositionen bleiben erhalten
4. In der Alloy-UI auf Port `12345` prüfen, dass alle Komponenten `Healthy` sind
5. `labels:`-Blöcke in `dist/docker-compose.prod.yml` ergänzen, Stack in Portainer neu
   deployen
6. Discovery greift nach ≤5s (`refresh_interval`)

Die `nginx.conf`-Änderung fährt separat: `access_log off;` steckt im `web`-Image,
braucht also einen Build über den Release-Branch und danach einen Image-Pull im Stack.
Bis dahin laufen die Access-Logs mit — unschön, aber folgenlos.

## Verifikation

```logql
{job="chormanager"}                                  → vier services sichtbar
{job="chormanager", service="app", level="ERROR"}    → JSON-Parsing greift
{job="chormanager", service="app"} | json | event=`mail.queue.sent`
                                                     → context bleibt abfragbar
{service_name="letsencrypt"}                         → Dateiquelle unbeschädigt
```

Drei Prüfungen, die aktiv gegen einen Fehler laufen statt nur für den Erfolg:

- Im Grafana-Label-Browser darf **`format` nicht auftauchen**. Tut es das doch, hat
  `stage.label_drop` nicht gegriffen und das Steuerlabel liegt dauerhaft im Index.
- Die Zeitstempel der App-Zeilen müssen zur `datetime` **im JSON** passen, nicht zur
  Empfangszeit. Bei falschem Format-String fällt Alloy still auf die Docker-Zeit
  zurück — das sieht man nur, wenn man hinschaut.
- Ein Container aus einem **fremden Stack** muss unverändert ankommen. Das ist der
  Beleg, dass die Änderung andere Stacks nicht beschädigt hat.

## Rollback

Alte `config.alloy` zurückspielen, SIGHUP. Die Container-Labels bleiben dann
wirkungslos liegen, ein zweiter Redeploy ist nicht nötig.

## Dokumentation

`dist/README.md` beschreibt das Prod-Deployment und nimmt auf:

- die Label-Konvention samt Bedeutung der vier Labels
- den Hinweis, dass der Logging-Driver `json-file` bleiben muss.
  `loki.source.docker` liest über die Docker-API; ein anderer Driver macht die
  Container für Alloy unsichtbar.

## Nicht Teil dieses Specs

- **Feature-Tests** nach `instructions/feature-tests.md`. Die Änderung besteht aus
  Container-Labels, einer nginx-Direktive und einer Config-Datei außerhalb des Repos.
  Kein PHP-Code, nichts, was PHPUnit erreichen könnte. Ein Test, der YAML-Labels gegen
  sich selbst prüft, wäre Zeremonie ohne Aussage. Die Verifikation sind die
  LogQL-Abfragen oben plus `alloy fmt`.
- **Seed-Daten** nach `instructions/seed.md`. Es wird nichts persistiert.
- **Dev-Umgebung.** DDEV bleibt unangetastet, Logs dort weiter über `ddev logs`.
- **Metriken und Traces.** Nur Logs.
- **Alerting-Regeln** in Grafana.
