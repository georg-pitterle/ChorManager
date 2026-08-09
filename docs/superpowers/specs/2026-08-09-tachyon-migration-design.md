# Webmail-Migration: SnappyMail → Tachyon

**Datum:** 2026-08-09
**Status:** Design freigegeben

## Ausgangslage

Das eingebettete Webmail unter `/webmail/` läuft auf SnappyMail (`djmaze/snappymail:v2.38.2`).
SnappyMail wird nicht mehr gepflegt. Nachfolger ist [Tachyon](https://github.com/kimusan/Tachyon),
ein Fork von SnappyMail (das seinerseits RainLoop Community forkte).

Aktueller Stand von Tachyon (verifiziert am 2026-08-09):

- Version **v3.2.2** (2026-07-11), erste Public-Release v3.0.1 (2026-06-30).
- Image **`ghcr.io/kimusan/tachyon:v3.2.2`**, `linux/amd64` + `linux/arm64`.
- Aufbau des Release-Images identisch zum djmaze-Image: Alpine + nginx + php-fpm,
  `EXPOSE 8888`/`9000`, `ENTRYPOINT []`, `CMD ["/entrypoint.sh"]`, `VOLUME /var/lib/tachyon`.
- Datenverzeichnis- und Config-Layout unverändert; laut Projekt-README können bestehende
  SnappyMail-Installationen direkt auf Tachyon aktualisiert werden.

### Verifizierte Kompatibilität (Quelle: Tachyon-Repository, Tag-Stand v3.2.2)

| Aspekt | Befund |
| --- | --- |
| Plugin-Auflösung | `Tachyon\Plugins\Manager::loadPluginByName()` bildet den Klassennamen weiterhin aus dem Ordnernamen (`chormanager-sso` → `ChormanagerSsoPlugin`) und prüft `class_exists()` unqualifiziert. Die bewusste Deklaration im globalen Namespace bleibt korrekt. |
| Basisklasse | Geprüft wird `is_subclass_of($cls, 'Tachyon\Plugins\AbstractPlugin')`. `RainLoop\Plugins\AbstractPlugin` existiert nur noch als 173-Byte-Alias-Shim. |
| `SensitiveString` | **Kein Shim vorhanden.** `SnappyMail\SensitiveString` gibt es nicht mehr; die Klasse heißt jetzt `Tachyon\Util\SensitiveString`. Ohne Umstellung bricht der SSO-Login mit Fatal Error. |
| Plugin-API | `addPartHook()`, `Manager()`, `Manager()->Actions()->LoginProcess()`, `Manager()->WriteLog()` unverändert vorhanden. |
| Konstanten | `APP_PRIVATE_DATA` und `APP_PLUGINS_PATH` unverändert definiert (`…/_data_/_default_/` bzw. `…/plugins/`). |
| Entrypoint | Gleiche Env-Vars (`UPLOAD_MAX_SIZE`, `MEMORY_LIMIT`, `SECURE_COOKIES`, `DEBUG`), gleiche `<UPLOAD_MAX_SIZE>`/`<MEMORY_LIMIT>`-Platzhalter in `/usr/local/etc/php-fpm.d/php-fpm.conf`, gleiches Admin-Passwort-File. Nur der Pfad wechselt auf `/var/lib/tachyon`. |
| nginx im Image | `root /tachyon;` — statische Assets liegen unter `/tachyon/v/<version>/…` statt `/snappymail/v/<version>/…`. |

Damit tragen alle bisherigen Kunstgriffe (Global-Namespace-Plugin, `enable-plugin.sh` als
Hintergrundprozess neben dem Original-Entrypoint, `env[]`-Passthrough in die php-fpm-Config)
unverändert weiter; angepasst werden müssen Pfade, der Asset-Prefix und zwei Klassennamen.

## Entscheidungen

1. **Umfang:** Dev (DDEV) und Prod (`dist/`, Deploy-Workflow) in einem Schritt.
2. **Benennung:** Eigene Bezeichner werden produktneutral (`WEBMAIL_*`), damit ein künftiger
   Fork-Wechsel keine erneute Umbenennung erzwingt.
3. **Prod-Volume:** Bestehendes Volume wird weiterverwendet (nur der Mountpfad wechselt),
   damit Admin-Passwort, `application.ini`, Domain-Configs und Benutzereinstellungen erhalten bleiben.
4. **Plugin-Namespace:** Direkt auf `Tachyon\…` umgestellt, nicht auf Compat-Shims gestützt —
   für `SensitiveString` existiert ohnehin kein Shim.
5. **Image-Bau:** Basis-Image tauschen (`FROM ghcr.io/kimusan/tachyon:v3.2.2`), Plugin weiterhin
   ins Image gebacken. Kein Build aus dem Tachyon-Source.

## Namensschema

| alt | neu |
| --- | --- |
| `SNAPPYMAIL_SSO_SECRET` | `WEBMAIL_SSO_SECRET` |
| `SNAPPYMAIL_UPLOAD_MAX_SIZE` | `WEBMAIL_UPLOAD_MAX_SIZE` |
| `SNAPPYMAIL_MEMORY_LIMIT` | `WEBMAIL_MEMORY_LIMIT` |
| `App\Services\SnappymailSsoTokenService` | `App\Services\WebmailSsoTokenService` |
| `tests/Unit/Services/SnappymailSsoTokenServiceTest.php` | `tests/Unit/Services/WebmailSsoTokenServiceTest.php` |
| `.ddev/docker-compose.snappymail.yaml` | `.ddev/docker-compose.webmail.yaml` |
| `.ddev/snappymail-plugins/` | `.ddev/webmail-plugins/` |
| `.ddev/.env.snappymail` (gitignored) | `.ddev/.env.webmail` |
| `dist/snappymail/` | `dist/webmail/` |
| Compose-Service `snappymail` | `webmail` |
| Netz-Alias `chormanager-snappymail-${STACK_ID}` | `chormanager-webmail-${STACK_ID}` |
| Image `ghcr.io/<owner>/chormanager-snappymail` | `ghcr.io/<owner>/chormanager-webmail` |
| Log-Label `logs.service: "snappymail"` | `logs.service: "webmail"` |
| Asset-Prefix `/snappymail/` | `/tachyon/` |
| Datenpfad `/var/lib/snappymail` | `/var/lib/tachyon` |

Unverändert bleiben: der Plugin-Ordnername `chormanager-sso` (der Klassenname leitet sich
daraus ab), die öffentliche Route `/webmail/`, das Feature-Flag `FEATURE_WEBMAIL` und
`MAIL_CREDENTIAL_KEY`.

### Bewusste Ausnahme: Volume-Key in Prod

In `dist/docker-compose.prod.yml` bleibt der Volume-Key **`snappymail_data`**. Compose leitet
den physischen Volume-Namen aus dem Key ab (`<stack>_snappymail_data`); eine Umbenennung würde
ein leeres Volume erzeugen und Admin-Passwort sowie alle Benutzereinstellungen verlieren.
Der Mountpfad wechselt auf `/var/lib/tachyon`; ein Kommentar im Compose-File erklärt den
Legacy-Namen ausdrücklich, damit er nicht später "aufgeräumt" wird.

Das DDEV-Volume wird dagegen neu vergeben (`name: ${DDEV_SITENAME}-webmail`) — lokaler
Webmail-State ist wegwerfbar.

## Architektur nach der Migration

### Container (Dev)

`.ddev/docker-compose.webmail.yaml`:

- Service `webmail`, Image `ghcr.io/kimusan/tachyon:v3.2.2`, kein Host-Port.
- Env: `UPLOAD_MAX_SIZE`, `MEMORY_LIMIT`, `SECURE_COOKIES=true`, `WEBMAIL_SSO_SECRET`.
- Volumes: `webmail_data:/var/lib/tachyon`,
  `./webmail-plugins/chormanager-sso:/var/lib/tachyon/_data_/_default_/plugins/chormanager-sso` (nicht `:ro`,
  weil der Entrypoint unbedingt `chown -R` darüber laufen lässt),
  `./webmail-plugins/enable-plugin.sh:/chormanager-enable-plugin.sh:ro`.
- `command: ["sh","-c","sh /chormanager-enable-plugin.sh & exec /entrypoint.sh"]`.

Die vorhandenen Erklärkommentare (DDEV-Interpolation liest nicht die Projekt-`.env`,
Pfadauflösung relativ zu `.ddev/`, kein `:ro` für das Plugin) bleiben inhaltlich erhalten und
werden nur auf die neuen Namen/Pfade gezogen.

### Container (Prod)

- `dist/webmail/Dockerfile`: `FROM ghcr.io/kimusan/tachyon:v3.2.2`, Plugin nach
  `/opt/chormanager-sso/chormanager-sso` kopiert (außerhalb des Volumes, damit ein Image-Update
  das Plugin auch bei bestehendem Volume aktualisiert), `enable-plugin.sh` nach
  `/chormanager-enable-plugin.sh`, gleiches `CMD`-Muster.
- `dist/docker-compose.prod.yml`: Service `webmail`, Image
  `ghcr.io/<owner>/chormanager-webmail:latest`, Env auf `WEBMAIL_*`, Mount
  `snappymail_data:/var/lib/tachyon`, Netz-Alias `chormanager-webmail-${STACK_ID:-prod}`,
  Log-Label `webmail`. Healthcheck unverändert (`http://127.0.0.1:8888/`).
- `.github/workflows/deploy.yml`: Build-Context `./dist/webmail`, Image-Name
  `chormanager-webmail`, Cache-Scope `webmail`.

### enable-plugin.sh

Inhaltlich unverändert, angepasst werden:

- `CONFIG_FILE="/var/lib/tachyon/_data_/_default_/configs/application.ini"`,
- `env[WEBMAIL_SSO_SECRET]`-Passthrough (weiterhin doppelt gequotet, wegen `=`/`+`/`/` in Base64),
- Kommentartexte auf Tachyon.

Die Warteschleife auf die ersetzten `<UPLOAD_MAX_SIZE>`/`<MEMORY_LIMIT>`-Platzhalter bleibt:
Tachyons Entrypoint schreibt dieselbe Datei mit `sed -i`, die Race-Condition besteht unverändert.

### SSO-Plugin

`chormanager-sso/index.php`:

- `class ChormanagerSsoPlugin extends \Tachyon\Plugins\AbstractPlugin` (global deklariert, wie bisher).
- `new \Tachyon\Util\SensitiveString($sPassword)` statt `\SnappyMail\SensitiveString`.
- `getenv('WEBMAIL_SSO_SECRET')`.
- `REQUIRED = '3.0.1'` (erste Tachyon-Release).
- Kommentarblöcke auf `Tachyon\Plugins\Manager` und die neuen Klassenpfade aktualisiert.

Fachlogik (Replay-Marker via `fopen(..,'x')`, Domain-JSON-Schreibung vor jedem Login,
fail-closed-Redirect auf `/webmail/`, `Referrer-Policy: no-referrer`, `LOG_WARNING`) bleibt
unangetastet. Die Sicherheitskommentare gelten unverändert weiter.

Die beiden Plugin-Kopien (`.ddev/webmail-plugins/`, `dist/webmail/chormanager-sso/`) bleiben
inhaltsgleich; die Duplikation ist bestehende Struktur und wird nicht angefasst.

### Routing

`.ddev/nginx_full/nginx-site.conf`:

- `/webmail/` → `proxy_pass http://webmail:8888/;` (Prefix-Strip wie bisher).
- `location /snappymail/` → `location /tachyon/` mit `proxy_pass http://webmail:8888/tachyon/;`
  (kein Strip — Tachyon serviert diese Assets selbst unter demselben Pfad).

Prod-Routing liegt beim Betreiber im SWAG-Reverse-Proxy und ist nur in `dist/README.md`
dokumentiert. Dort wird das Beispiel auf `/tachyon/` und den neuen Alias umgestellt.

### App-Code

`SnappymailSsoTokenService` → `WebmailSsoTokenService` (Datei, Klasse, `KEY_ENV`,
Exception-Texte, Doc-Block). `WebmailController` zieht den neuen Import/Typ nach.
Die Verdrahtung erfolgt über Konstruktor-Injection; der Klassenname wird überall dort
nachgezogen, wo er referenziert wird.

Verschlüsselung, Token-Format und TTL bleiben identisch — Prod-Neustart macht lediglich
in-flight-Tokens ungültig (45 s TTL), das ist folgenlos.

## Migrationsschritte für den Betreiber (Prod)

Diese Schritte sind **nicht** durch das Deployment abgedeckt und gehören prominent in
`dist/README.md`:

1. In Portainer `WEBMAIL_SSO_SECRET` mit dem bisherigen Wert von `SNAPPYMAIL_SSO_SECRET` setzen;
   `SNAPPYMAIL_*`-Variablen anschließend entfernen. Der Wert muss mit der `.env` der App
   übereinstimmen (`WEBMAIL_SSO_SECRET` dort ebenfalls umbenennen).
2. SWAG-Config: `location /snappymail/` → `location /tachyon/`, Upstream
   `chormanager-snappymail-prod` → `chormanager-webmail-prod` in beiden Location-Blöcken.
   Ohne diesen Schritt lädt das Webmail nach dem Deploy ohne CSS/JS.
3. Stack neu deployen. Das bestehende Volume wird unter `/var/lib/tachyon` weiterverwendet;
   es ist kein Datenexport nötig.

Reihenfolge ist bewusst: Env vor Deploy, SWAG vor oder direkt nach dem Deploy.

## Tests

TDD-Hebel sind die bestehenden Assertions gegen Compose- und README-Inhalte:

- `tests/Feature/StackResilienceFeatureTest.php` — Alias `chormanager-webmail-${STACK_ID:-prod}`.
- `tests/Feature/WebmailFeatureFlagTest.php` — SWAG-Beispiel prüft künftig `/tachyon/`-Location
  und den neuen Upstream; die vorhandene Negativ-Assertion gegen einen `proxy_pass` mit
  URI-Teil bleibt sinngemäß erhalten.
- `tests/Unit/Services/WebmailSsoTokenServiceTest.php` — Env-Key `WEBMAIL_SSO_SECRET`.
- `tests/Feature/WebmailControllerFeatureTest.php` — Env-Key und Service-Klasse.

Reihenfolge: Tests zuerst auf die neuen Werte umstellen (rot), dann Compose/README/Code
nachziehen (grün). Zusätzlich ein neuer Test, der `dist/webmail/Dockerfile` und
`.ddev/docker-compose.webmail.yaml` gegen das gepinnte Tachyon-Image prüft und sicherstellt,
dass kein `snappymail`-Rest in den Container-Definitionen verbleibt (verhindert eine halbe
Migration).

Seed-Daten sind nicht betroffen: die Migration führt keine neuen persistierten Entitäten ein.

## Verifikation vor Abschluss

1. `ddev restart` — Container startet, `docker logs` zeigt `[INFO] Tachyon version: …`.
2. Im Container prüfen: `application.ini` enthält `enable = On` und `chormanager-sso` in
   `enabled_list`; `php-fpm.conf` enthält `env[WEBMAIL_SSO_SECRET]`.
3. `/tachyon/v/<version>/static/…` liefert 200 über den DDEV-Proxy; `/webmail/` liefert die Shell.
4. SSO-Login aus dem Profil heraus führt ohne zweiten Login-Dialog in die Inbox.
5. `ddev composer phpcs`, `ddev composer twigcs`, vollständige PHPUnit-Suite.

Browser-gestützte Durchklick-Tests nur auf ausdrückliche Anforderung.

## Risiken

- **Tachyon ist jung** (erste Release 2026-06-30, ~55 Image-Downloads). Das Image ist deshalb
  auf `v3.2.2` gepinnt; ein Versionssprung erfolgt bewusst, nicht über einen Floating-Tag.
- **Compat-Shims sind unvollständig** (`SensitiveString` fehlt). Weitere Plugin-APIs wurden
  gegen den Source geprüft, aber ein Laufzeittest des SSO-Pfades ist verpflichtend.
- **Zwei manuelle Betreiber-Schritte** (Env, SWAG). Werden sie vergessen, ist das Webmail in
  Prod kaputt — Doku muss das unübersehbar machen.
