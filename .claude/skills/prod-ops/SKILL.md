---
name: prod-ops
description: >
  Betrieb der ausgerollten ChorManager-Instanzen auf dem Docker-Host (Live-Instanz und
  Demo-Stack hinter Portainer/SWAG). Use this skill whenever the user pastes output from the
  server (`root@gpit`, `docker exec`, `docker volume`, `mysqldump`, `rclone`, cron, Portainer),
  asks how to do something on production or the demo stack, or the task touches the live
  database, backups on the host, offsite copies, container logs, OIDC/Nextcloud login, mail
  settings in `.env`, fixing wrong data on the live instance, or an SSH tunnel/HeidiSQL into
  the container — e.g. "auf prod", "live-instanz", "demo-umgebung", "am server", "im
  container", "backup wiederherstellen", "rclone", "falsche buchung auf live", "warum feuert
  der alert".
  Nicht für die lokale ddev-Umgebung (dort gilt ddev-workflow) und nicht für Echtdaten, die
  in ddev eingespielt werden sollen.
---

# Prod-Betrieb

Claude hat **keinen Zugang** zum Server. Georg führt jeden Befehl selbst aus und kopiert die
Ausgabe zurück. Die Arbeit hier besteht also aus Befehlen, die beim ersten Einfügen
funktionieren, und aus dem Lesen der Ausgaben, die zurückkommen.

## Regeln

1. **Befehle zum Einfügen, nie selbst ausführen.** Kein `ssh`, kein `curl` gegen die
   Instanz und kein `gh`-Workflow, der deployt, außer Georg verlangt es ausdrücklich.
2. **Erst den App-Weg prüfen.** Bevor SQL gegen Live geschrieben wird, im Repo nachsehen
   (`src/Services`, `src/Routes.php`), ob die App die Korrektur selbst kann. Kann sie es,
   ist der App-Weg die Antwort, der DB-Weg höchstens die Ausweichlösung. Im Kassabuch
   (`finances`) gilt das immer: Buchungen werden storniert (`FinanceBookingService::reverse()`,
   „Stornieren“ im Kassabuch), nie gelöscht. Das Journal `finance_revisions` muss lückenlos
   bleiben.
3. **Erst lesen, dann schreiben.** Jede ändernde Aktion auf Prod (UPDATE/DELETE, Restore,
   `rm`, Volume-Operationen, Neustart) kommt erst, wenn ein lesender Befehl den Ist-Zustand
   gezeigt hat. Davor immer eine Sicherung: `bin/create_backup.php --type=manual` oder
   `mysqldump` in eine Datei.
4. **SQL-Änderungen in zwei Durchläufen.** Ein `docker exec`-Heredoc ist eine eigene
   Sitzung, ein `COMMIT` im nächsten Befehl käme zu spät. Deshalb zweimal derselbe Block:
   zuerst mit `ROLLBACK;` am Ende als Probelauf, der das Ergebnis per `SELECT` zeigt. Erst
   wenn Georg das gesehen hat, folgt derselbe Block mit `COMMIT;`. Vorher Fremdschlüssel und
   abhängige Tabellen prüfen (`SHOW CREATE TABLE …`).
5. **Echtdaten bleiben auf dem Server.** Keine Personendaten in Dateien im Repo, nicht in
   den Scratchpad und nicht in Commits. Abfragen so schreiben, dass sie nur zurückgeben, was
   für die Diagnose nötig ist (Zählungen, IDs, Beträge statt ganzer Mitgliederzeilen).
6. **Fakten unten sind Stand 2026-11.** Widerspricht eine Ausgabe dieser Seite, gilt die
   Ausgabe: weiterarbeiten und Georg anbieten, die Seite nachzuziehen.

## Topologie

| | Live | Demo |
|---|---|---|
| Portainer-Stack | `chorkuma-chormanager` | `chormanager` |
| Container | `chorkuma-chormanager-{app,db,web,webmail}-1` | `chormanager-{app,db,web,webmail}-1` |
| URL | `https://chorkuma.chomano.eu` | eigener Hostname, nur Seed-Daten |
| Daten | **echt** | Seed, darf jederzeit neu aufgesetzt werden |
| Loki-Label | `stack="chorkuma"` (Alert-Regeln hängen daran) | eigener `STACK_ID` |

- Host: `gpit` (Shell als `root`); `grafana.gpit.dedyn.io` für Logs und Alerts
  (`/log-analysis`).
- Compose-Datei und `.env` des Live-Stacks liegen unter Portainer:
  `/var/lib/docker/volumes/portainer_data/_data/compose/<id>/`. Die `<id>` liefert
  `docker inspect chorkuma-chormanager-app-1 --format '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}'`.
  Vorlage im Repo: `dist/docker-compose.prod.yml`, Doku: `dist/README.md`.
- Volumes (Präfix = Stack-Name): `_db_data`, `_backup_data`, `_file_data`,
  `_session_data`, `_maintenance_data`, `_snappymail_data` (Altname, enthält Tachyon).
  Auf dem Host unter `/var/lib/docker/volumes/<volume>/_data/`.
- App ↔ DB über den Unix-Socket (`db_socket`-Volume, `DB_SOCKET`). Ohne ihn scheitert das
  In-App-Backup mit Fehler 2026, siehe `dist/README.md` → Backup.
- Nextcloud-Login (OIDC): Die App ist der Identity-Provider, Nextcloud unter
  `cloud.chorkuma.at` ist der Client. Verwaltung: `php bin/oidc_admin.php` im App-Container.

## Backups

- Täglich 03:00 über `/etc/cron.d/chorkuma-chormanager`:
  `docker exec chorkuma-chormanager-app-1 php /var/www/html/bin/create_backup.php --type=auto`,
  Log in `/var/log/chormanager-backup.log`. Das Skript wechselt selbst auf `www-data`.
- Ablage: Volume `chorkuma-chormanager_backup_data` = `BACKUP_DIR`
  (`/var/backups/chormanager`) im App-Container. Ein Dump entsteht als `*.sql.gz.partial`
  und wird erst nach Abschluss umbenannt. Zu jedem Dump gehört eine `.json`-Beschreibung mit
  `sha256`.
- Kopien außer Haus über rclone auf dem Host:
  - `crypt:` – verschlüsselte Sicherung der Docker-Volumes (`/backup/volumes/`, rclone sync).
  - `crypt-nc:` – verschlüsselt auf Nextcloud (`nextcloud:Backups/ChorManager`); dort liegen
    die App-Backups und unter `crypt-nc:config/` die `.env`. Immer mit
    `--exclude "*.partial"` kopieren.
- Restore eines heruntergeladenen Backups: `dist/README.md` → "Restoring a downloaded
  backup". Ohne passende `.json` (sha256) bricht der Restore ab. IMAP-Passwörter lassen sich
  nur entschlüsseln, wenn `MAIL_CREDENTIAL_KEY` zur `mail_key_id` passt.

## Befehlsgerüste

Variablen einmal setzen, dann bleiben die Befehle kurz:

```bash
APP=chorkuma-chormanager-app-1
DB=chorkuma-chormanager-db-1
```

Lesende Abfrage (die Zugangsdaten kommen aus der Umgebung des Containers, nie im Klartext):

```bash
docker exec -i "$DB" sh -c 'mysql -t -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 "$MYSQL_DATABASE"' <<'SQL'
SELECT id, running_number, amount FROM finances WHERE running_number = 42;
SQL
```

Sicherung vor einer Änderung:

```bash
docker exec "$DB" sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --set-gtid-purged=OFF "$MYSQL_DATABASE"' | gzip > ~/pre-fix-$(date +%Y%m%dT%H%M).sql.gz
```

`--set-gtid-purged=OFF` ist bei MySQL 9.7 Pflicht, sonst lässt sich der Dump nicht wieder
einspielen.

Änderung, erster Durchlauf (Probelauf, ändert nichts):

```bash
docker exec -i "$DB" sh -c 'mysql -t -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
START TRANSACTION;
UPDATE users SET is_active = 0 WHERE id = 123;
SELECT id, is_active FROM users WHERE id = 123;
ROLLBACK;
SQL
```

Zweiter Durchlauf: derselbe Block, letzte Zeile `COMMIT;` statt `ROLLBACK;`.

App-Konsole: `docker exec "$APP" php bin/<skript>.php …` (Arbeitsverzeichnis
`/var/www/html`). Logs: `docker logs --since 1h "$APP"`; strukturiert in Grafana/Loki.

## Fallstricke

- **Shell des Eingabeorts.** Auf dem Host läuft bash. Lokal läuft PowerShell, dort gibt es
  kein `<` (`ddev mysql < datei.sql` → ParserError). Stattdessen
  `Get-Content datei.sql -Raw | ddev mysql` oder `cmd /c "ddev mysql < datei.sql"`. Bei jedem
  Befehl dazuschreiben, wo er läuft: **Host**, **lokal (PowerShell)** oder **im Container**.
- **Platzhalter vermeiden.** Was sich ermitteln lässt (Pfad, Container), erst mit einem
  Befehl ermitteln lassen, statt `<pfad-zum-stack>` auszugeben.
- **Copy-&-Paste-Unfälle.** Ein Ordner namens `-p` entsteht, wenn ein umbrochenes
  `-p"$PW"` einzeln ausgeführt wird. Mehrzeilige Befehle nur als Heredoc oder mit `\` am
  Zeilenende ausgeben, in einem Block.
- **MySQL-Benutzer** werden nur beim allerersten Start mit leerem `db_data` angelegt. Wird
  danach `.env` geändert, folgt `Access denied`. Siehe `dist/README.md` → "Troubleshooting a
  duplicated stack".
- **Demo neu aufsetzen** ist erlaubt (nur Seed-Daten). Live **nie**: Dort gibt es nur
  gezielte, gesicherte Korrekturen.
