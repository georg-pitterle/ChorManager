# Audit-Logging und zur Laufzeit steuerbares Log-Level

Datum: 2026-08-01

## Ziel

1. Sicherheitsrelevante Vorgänge — Anmeldung, Rechteänderungen, Rollenzuweisungen —
   hinterlassen eine Logzeile, ohne dass dafür ein Level angehoben werden muss.
2. Das Log-Level ist zur Laufzeit über `/settings` umschaltbar, ohne Neustart und
   ohne Redeploy.
3. Schreibende SQL-Operationen sind separat zuschaltbar, damit ein angehobenes
   Level nicht automatisch in Query-Logs ertrinkt.
4. Alle Zeilen eines Requests lassen sich über eine gemeinsame `request_id`
   zusammenführen.

Anlass ist die erste Testphase: Fehler sind zu erwarten und müssen nachvollziehbar
sein, die Ressourcen für dauerhaft ausführliches Logging fehlen aber.

## Ausgangslage

- `AppLoggerFactory` baut einen Monolog-Logger mit `JsonFormatter` auf
  `php://stderr`. Das Level kommt aus `APP_LOG_LEVEL`, Vorgabe `INFO`
  (`src/Settings.php`, Abschnitt `logging`).
- Der Logger wird in `src/Dependencies.php` allein aus dem `settings`-Array
  gebaut. Er kennt die Datenbank nicht.
- Es existieren 62 Logger-Aufrufe in `src/`, alle an konkrete Fachaktionen
  gebunden (Budget, Backup, Notenarchiv, Mailversand). Es gibt kein
  Request-Logging und keine Authentifizierungs-Events.
- `AuthController` bekommt den Logger injiziert, nutzt ihn aber nur an einer
  Stelle für einen Fehler. Login, Logout und Fehlversuche laufen ungeloggt.
- Slims Error-Middleware erhält den Logger bereits (`src/Middleware.php:36`),
  unbehandelte Exceptions werden also protokolliert.
- Die Modelle erweitern `Illuminate\Database\Eloquent\Model` direkt; eine
  Basisklasse gibt es nicht. Die Capsule wird in `src/Dependencies.php:61`
  aufgebaut und global gesetzt.
- Rechte sind 19 `can_*`-Spalten auf `roles` (`src/Models/Role.php`).
- `app_settings` ist eine Key-Value-Tabelle mit `setting_key` als Primärschlüssel,
  gelesen und geschrieben über `AppSettingController`.
- Logs laufen bereits strukturiert nach Loki
  (siehe `2026-07-31-alloy-log-shipping-design.md`).

## Entscheidungen

### Kein Audit-Log in der Datenbank

Verworfen wurde eine eigene, nur anhängende Audit-Tabelle. Der Zweck ist
Nachvollziehbarkeit im Betrieb, nicht revisionssicherer Nachweis. Eine zweite
Schreibstrecke verdoppelt Migrationen, Testaufwand und Fehlerquellen, ohne dass
eine Anforderung sie verlangt. Aufbewahrung richtet sich nach Lokis Retention.

### Laufzeit-Schalter statt Umgebungsvariable

`APP_LOG_LEVEL` existiert, verlangt aber einen Redeploy und damit einen
Containerneustart. Bei einem sporadischen Fehler in der Testphase ist der Zustand,
der ihn ausgelöst hat, danach oft weg. Das Level liegt deshalb in `app_settings`
und wirkt sofort. Die Umgebungsvariable bleibt als Rückfallwert.

### Gate-Handler statt Neubau des Loggers

Der Logger wird fest auf `debug` gebaut. Davor sitzt ein eigener Handler, dessen
`isHandling()` bei jedem Record einen Resolver fragt, ob dieses Level gerade
durchgelassen wird.

Die beiden Alternativen scheitern an der Umgebung: Ein Neubau des Loggers pro
Request bricht mit dem DI-Singleton, weil jede Klasse, die den Logger im
Konstruktor erhalten hat, die alte Instanz weiterhält. Monologs `FilterHandler`
nimmt seine Levelmenge bei der Konstruktion entgegen und ist damit nicht dynamisch.

Entscheidend ist die Nebenwirkung: **Die Konstruktion des Loggers bleibt frei von
der Datenbank.** Er funktioniert auch dann, wenn die Datenbank nicht erreichbar
ist — dann greift stumm der Env-Wert. Genau in diesem Fall wird das Log am
dringendsten gebraucht.

### Schreib-SQL hat einen eigenen Schalter

Ein angehobenes Level allein schaltet keine Query-Logs frei. Erst Level `debug`
**und** der Schalter `log_db_writes` zusammen protokollieren Schreib-Statements.
Damit sind „ausführliches Anwendungs-Log ohne SQL" und „SQL zusätzlich" zwei
unabhängig erreichbare Zustände. Der umgekehrte Fall — SQL ohne Anwendungs-Debug —
ist bewusst nicht vorgesehen; er hätte keinen Anwendungsfall.

### Bindings werden nicht protokolliert

Die Parameter eines `INSERT` auf `users` oder auf die Mail-Zugangsdaten enthalten
Passwort-Hashes und verschlüsselte Geheimnisse. Protokolliert werden Statement,
Tabelle und Dauer. Passwörter, Tokens und Session-IDs erscheinen nirgends im Log,
auch nicht gekürzt (`instructions/security-baseline.md`).

### Keine Phinx-Migration

`app_settings` ist eine Key-Value-Tabelle. Die beiden Schalter sind zwei Zeilen,
kein Schemawechsel.

## Architektur

### Neue Bausteine

| Datei | Verantwortung |
|---|---|
| `src/Logging/LogLevelResolver.php` | liest `log_level` und `log_db_writes` aus `app_settings`, cached pro Request, fällt bei jedem Fehler auf `APP_LOG_LEVEL` zurück, wirft nie |
| `src/Logging/RuntimeLevelHandler.php` | umschließt den `StreamHandler`, entscheidet in `isHandling()` anhand des Resolvers |
| `src/Logging/RequestContext.php` | hält `request_id`, `user_id`, `ip`, `method`, `path` des laufenden Requests |
| `src/Logging/RequestContextProcessor.php` | hängt diesen Kontext an jeden Record |
| `src/Middleware/RequestContextMiddleware.php` | erzeugt die `request_id`, füllt den Kontext |
| `src/Logging/DatabaseWriteLogger.php` | registriert den Capsule-Listener, filtert auf schreibende Statements, Re-Entrancy-Guard, verwirft Bindings |

### Geänderte Bausteine

- `src/Logging/AppLoggerFactory.php` — baut den `StreamHandler` auf `debug` und
  hängt ihn in den `RuntimeLevelHandler`; registriert den
  `RequestContextProcessor`.
- `src/Dependencies.php` — verdrahtet Resolver, Kontext, Prozessor und Listener.
- `src/Middleware.php` — registriert die `RequestContextMiddleware`.
- `src/Controllers/AppSettingController.php` — nimmt die beiden neuen Schlüssel
  entgegen.
- `templates/settings/index.twig` — die beiden Bedienelemente.
- `src/Controllers/AuthController.php`, `UserController.php`, `RoleController.php`,
  `ProfileController.php`, `PasswordResetController.php` — die Aufrufstellen des
  Event-Katalogs.

### Zwei Fallstricke, die die Umsetzung lösen muss

**Rekursion.** Der Resolver fragt `app_settings` per SQL ab. Der DB-Listener
protokolliert Queries und holt sich dafür den Resolver, der wieder eine Query
absetzt. Der Listener braucht einen Re-Entrancy-Guard und muss die Abfrage auf
`app_settings` ausschließen.

**Reihenfolge beim Start.** Der Resolver darf die Capsule erst beim ersten
tatsächlichen Zugriff anfordern, nicht im Konstruktor. Sonst zieht der Logger die
Datenbank in seine eigene Konstruktion und die oben beschriebene Unabhängigkeit
ist wieder verloren.

## Event-Katalog

Namensschema wie bisher: `event` in Punktnotation, passend zu
`backup.create.completed` und `mail.queue.sent`.

### INFO — immer aktiv

| Bereich | Events |
|---|---|
| Anmeldung | `auth.login.succeeded`, `auth.login.failed` (mit Grund: `bad_credentials`, `unknown_user`, `inactive`), `auth.logout` |
| Sitzung | `auth.remember_me.used`, `auth.remember_me.rejected` |
| Passwort | `auth.password.changed`, `auth.password_reset.requested`, `auth.password_reset.completed` |
| Autorisierung | `authz.denied` mit Route und fehlendem Recht |
| Benutzer | `user.created`, `user.activated`, `user.deactivated`, `user.deleted`, `user.email.changed` |
| Rechte | `user.role.assigned`, `user.role.revoked`, `role.created`, `role.updated`, `role.deleted` |
| Daten | `export.generated`, `invitation.created`, `invitation.consumed`; `backup.*` besteht bereits |
| Konfiguration | `settings.updated`, `mail.credentials.changed` |

Vier dieser Events sind nicht offensichtlich und tragen den größten Teil des
Nutzens:

- **`authz.denied`** — jeder abgewiesene Rechtezugriff. In einer Testphase mit
  frisch konfigurierten Rollen ist das die häufigste Fehlerquelle, und ohne dieses
  Event ist „bei mir fehlt der Menüpunkt" nicht nachvollziehbar.
- **`role.updated` mit Diff der `can_*`-Flags** — nicht nur, dass eine Rolle
  geändert wurde, sondern welches der 19 Rechte von wem hinzugefügt oder entzogen
  wurde. Ohne den Diff beantwortet das Event die einzige Frage nicht, für die man
  es liest.
- **`user.email.changed`** — die klassische Übernahmeroute: Adresse ändern,
  Passwort zurücksetzen, Konto gehört einem.
- **`auth.remember_me.rejected`** — ein vorgelegtes, aber ungültiges Token. Harmlos
  bei abgelaufener Anmeldung, ein Angriffssignal bei Wiederholung.

### WARNING

Abgelehnter CSRF-Token, abgelehnter Datei-Upload, überschrittenes Rate-Limit
(`auth.login.rate_limited`).

### ERROR

Unbehandelte Exceptions. Läuft bereits über Slims Error-Middleware.

### DEBUG

Request-Ende mit Route, Statuscode und Dauer, dazu fachliche Zwischenschritte.

### DEBUG mit aktivem `log_db_writes`

Schreib-Statements mit Tabelle und Dauer, ohne Bindings.

## Request-Kontext

Jeder Record erhält automatisch `request_id`, `user_id`, `ip`, `method` und `path`.
Damit lässt sich ein Fehlerbericht aus der Testphase über die `request_id` zu allen
Zeilen desselben Requests auflösen — bei „bei mir ging etwas schief" mehr wert als
jedes einzelne Event.

IP-Adressen sind personenbezogen. Sie stehen im Request-Kontext und an
Sicherheitsevents, nicht als Dauerbeigabe an fachlichen Debug-Zeilen.

## Oberfläche

In `/settings`:

- Auswahlfeld **Log-Level** mit den acht PSR-3-Stufen, Vorgabe `INFO`.
- Schalter **SQL-Schreiboperationen protokollieren**, Vorgabe aus.

Der Schalter trägt in der Vorlage den sichtbaren Hinweis, dass er nur bei Level
`debug` wirkt — sonst schaltet ihn jemand ein und wundert sich. Beide Felder liegen
hinter dem Recht, das `/settings` ohnehin schützt.

## Tests

Zuerst geschrieben, nach `instructions/feature-tests.md`.

**Unit**

- Der Resolver liefert den Env-Wert, wenn die Datenbankabfrage wirft.
- Der Resolver cached und fragt innerhalb eines Requests nicht zweimal.
- Ein unsinniger Wert in `app_settings` führt nicht zum Absturz, sondern auf die
  Vorgabe.
- Der Gate-Handler lässt `error` durch, während das Level auf `info` steht, und
  verwirft `debug`.
- Der DB-Listener protokolliert `insert`, ignoriert `select`, gibt niemals Bindings
  aus und läuft bei einer Abfrage aus dem Resolver nicht in die Rekursion.

**Feature**

- Login mit richtigem Passwort erzeugt `auth.login.succeeded`.
- Login mit falschem Passwort erzeugt `auth.login.failed` mit Grund.
- Ein Zugriff ohne Recht erzeugt `authz.denied`.

Geprüft über Monologs `TestHandler`.

## Seed

`DevSeedService` legt `log_level` und `log_db_writes` mit ihren Vorgabewerten an,
damit die Einstellungsseite in Dev nicht leer ist
(`instructions/seed.md`).

## Reihenfolge der Umsetzung

Erst die Infrastruktur — Resolver, Gate-Handler, Request-Kontext, Schalter in
`/settings`, jeweils mit Tests. Danach der Event-Katalog, der sechs Controller
berührt. Sonst hängt der erste lauffähige Stand von zwei Dutzend Aufrufstellen ab.

Der DB-Listener kommt als dritter Schritt, weil er den Resolver bereits voraussetzt.

## Nicht Teil dieses Specs

- **Audit-Tabelle in der Datenbank.** Siehe Entscheidungen.
- **Alarmierung.** Keine Grafana-Alerts auf `auth.login.failed` oder ähnliches.
- **Aufbewahrungsfristen und Löschkonzept** für Logs in Loki.
- **Nachrüsten der bestehenden 62 Logger-Aufrufe** auf das neue Namensschema. Sie
  tragen bereits einen `event`-Key und bleiben unangetastet.
- **Anonymisierung von IP-Adressen.**
