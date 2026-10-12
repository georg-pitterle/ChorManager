---
name: dependency-update
description: >
  Aktualisiert alle Abhängigkeiten von ChorManager in einem Durchgang — Composer, npm,
  Docker-Basis-Images (Dockerfile, Dockerfile.web, dist/webmail), Compose-Images in .ddev/
  und dist/, ddev-Laufzeiten (PHP, MariaDB), GitHub Actions und Playwright-Browser — und
  prüft dabei auf Major-Versionen. Use this skill whenever the user asks to update, upgrade
  or bump dependencies, packages, libraries, Docker images, base images or runtimes, or to
  check what is outdated: "update alle dependencies", "Pakete aktualisieren", "composer
  update", "npm update", "gibt es neue Versionen", "prüf auf major", "Dockerfiles
  aktualisieren", "neue PHP-/Node-Version", "Dependabot-PRs nachziehen", "npm audit". Also
  use it when only one ecosystem is named — the other places are checked in the same run.
  Nicht für das Hinzufügen eines neuen Pakets im Rahmen eines Features.
---

# Abhängigkeiten aktualisieren

Versionen sind in diesem Projekt an vielen Stellen festgelegt, nicht nur in `composer.json`
und `package.json`. Ein Update, das nur die beiden Paketmanager anfasst, lässt Docker-Images,
ddev-Dienste und Actions stillschweigend veralten. Dieser Ablauf geht deshalb immer alle
Stellen durch und trennt dabei sauber zwischen dem, was gefahrlos direkt eingespielt wird,
und dem, was der Entwickler entscheidet.

## Die Regel in einem Satz

**Minor und Patch werden eingespielt, Majors und Laufzeiten werden aufgelistet und erst nach
einem Ja angehoben.** Majors brechen per Definition Schnittstellen, und Laufzeiten (PHP,
Node, Datenbank, nginx) hängen an mehreren Stellen gleichzeitig — beides braucht eine
bewusste Entscheidung, keinen Routine-Klick.

## 1. Bestandsaufnahme

`git status` muss sauber sein — sonst wandert fremde Arbeit in den Update-Commit. Dann auf
einem eigenen Branch arbeiten (`git switch -c claude/dependency-updates`) und alles in einem
Rutsch erheben:

```bash
ddev composer outdated --direct          # "!" = minor/patch, "~" = Major verfügbar
ddev composer audit
npm outdated                             # Exit-Code 1 heißt "es gibt Veraltetes", kein Fehler
npm audit
node .claude/skills/dependency-update/scripts/check-pins.mjs
```

npm läuft auf dem **Host**, nicht in ddev: Dort ist das neuere npm, und `node_modules` liegt
ohnehin im Projektordner. Composer läuft in ddev.

`check-pins.mjs` findet über `git ls-files` jedes `FROM`, `COPY --from=`, Compose-`image:`,
jede Action-`uses:` sowie `php_version` und die Datenbank in `.ddev/config.yaml`. Es gibt
pro Fundstelle zwei Werte aus:

- **gleicher Major** — neueste Version im aktuellen Major. Das ist der direkt einspielbare
  Schritt, auch wenn es daneben einen Major gibt (Tachyon `v3.2.2 → v3.2.8` neben `v4`).
- **Ziel** — die empfohlene neueste Version. Für node, nginx, mysql und mariadb ist das der
  Tag hinter dem Kanal-Alias (`lts-alpine`, `stable-alpine`, `lts`), ermittelt über den
  Digest — nicht die höchste Nummer, denn die wäre bei nginx Mainline, bei mysql ein
  Innovation-Release und bei node eine noch nicht zur LTS beförderte Version. Für die
  ddev-Datenbank zusätzlich gedeckelt auf das, was ddev als `ddev-dbserver-*`-Image anbietet.

Eine Stelle, die das Skript nicht findet, ist ein Fehler im Skript, kein Grund, sie zu
übergehen — dann das Skript ergänzen.

## 2. Einspielen, was gefahrlos ist

**Reihenfolge: erst npm, dann Composer.** `composer update` ruft im `post-update-cmd`
`bin/copy-assets.php` auf, das die Frontend-Pakete aus `node_modules` nach `public/vendor/`
kopiert. Läuft npm danach, liegen dort alte Dateien — dann `ddev composer copy-assets` von
Hand nachholen.

npm:
- Exakt gepinnte Pakete bleiben exakt: `npm install <paket>@<version> --save-exact`.
- Danach `npm update` — das hebt die `^`-Pakete (sortablejs, tom-select, @playwright/test)
  und die indirekten Pakete im Lockfile an, was einzelne `npm install` nicht tun.
  `package.json`-Bereiche von `^`-Paketen auf die neue Mindestversion nachziehen
  (`npm install -D @playwright/test@^1.64.0`).
- `allowScripts` in `package.json` nennt Pakete **mit Version** (`core-js@3.50.0`). Ändert
  sich eine davon im Lockfile (`npm ls core-js protobufjs`), mit
  `npm approve-scripts <paket>` neu freigeben, sonst laufen deren Install-Skripte nicht.
- `overrides` erzwingen Versionen für Sicherheitslücken in Unterpaketen. Nicht entfernen,
  ohne mit `npm ls <paket>` belegt zu haben, dass die Lücke anders geschlossen ist.
- `npm audit fix --force` nie ungeprüft: Es stuft gern Pakete zurück (z. B. html-midi-player
  1.6 → 1.1.1). Ist ein Befund nicht behebbar, kommt er mit Pfad und Begründung in den
  Bericht.

Composer:
- `ddev composer update`. Die Ausgabe nach transitiven Major-Sprüngen absuchen (`0.19 → 1.0`
  zählt auch). Für jeden: `ddev composer why <paket>` und `git grep -n "<Namespace>\\\\"` in
  `src/`, `tests/`, `bin/`. Nutzt nur ein Fremdpaket es, das den Bereich ausdrücklich
  erlaubt, bleibt es drin und wird im Bericht genannt. Nutzt der eigene Code es direkt, ist
  es ein Major wie jeder andere — bis zur Entscheidung zurücksetzen mit
  `ddev composer update <paket> --with <paket>:<alter Bereich>`.
- Hat `patches/patches.json` Einträge, prüfen, ob sie noch anwenden (die Ausgabe von
  `composer update` meldet es).

Images und Actions mit Wert in **gleicher Major** direkt anheben.

**Nach jedem Anheben** einer Image- oder Laufzeit-Version: `git grep -n "<alte Version>"`.
Versionen stehen auch in Tests, Kommentaren und Doku (Beispiele in Abschnitt 4). Jeden
Treffer nachziehen oder bewusst stehen lassen — ein Test mit der alten Version wird sonst
rot, ein Kommentar lügt still.

## 3. Majors und Laufzeiten vorlegen

Alles mit `MAJOR`, jede Laufzeit-Änderung und jeder Fall aus Abschnitt 4, der eine
Entscheidung braucht, wird **vor** dem Einspielen vorgelegt — als nummerierte Liste in der
Antwort, nicht als Auswahl-Dialog (der fasst nur vier Optionen). Pro Kandidat:

- aktuell → Ziel, und an welchen Stellen die Version steht (alle, nicht nur eine)
- die Breaking Changes aus Changelog/Release-Notes, die dieses Projekt tatsächlich treffen
  (nachlesen, nicht raten — "keine relevanten Änderungen" nur, wenn es gelesen wurde)
- Empfehlung: jetzt, später oder gar nicht, mit Grund

Der Entwickler antwortet mit den Nummern, die angehoben werden. Was abgelehnt wird, bleibt
unverändert und steht im Bericht als bewusst ausgelassen.

Einspielen eines bestätigten Majors: Composer mit `ddev composer require <paket>:^<neu>`
(dev-Pakete mit `--dev`) — `update` bleibt innerhalb der Bereiche aus `composer.json`. npm
mit `npm install <paket>@<neu>` (exakt gepinnte mit `--save-exact`).

## 4. Stellen mit Eigenheiten

| Was | Wo | Worauf achten |
|---|---|---|
| PHP | `composer.json` (`require.php`), `Dockerfile` (`php:X-fpm-alpine`), `.ddev/config.yaml` (`php_version`), `README.md` | Laufzeit: alle Stellen gemeinsam, sonst testet ddev eine andere Version als Produktion läuft. Ob die installierte ddev-Version (`ddev version`) sie kann, steht in der ddev-Doku unter "PHP Versions". |
| Node | `Dockerfile` (Stufe `assets`), `README.md` | Ziel ist, was `node:lts-alpine` zeigt (das Skript ermittelt es). Die Stufe baut nur Assets; ddev bringt eigenes Node mit. |
| nginx | `Dockerfile.web`, Kommentar in `nginx.conf`, `tests/Feature/EsModuleMimeTypeFeatureTest.php` | Ziel ist die stable-Linie (`stable-alpine`), nicht die höhere Mainline. Der Test hält fest, welche MIME-Typen das Image kennt — beim Anheben prüfen, ob die Annahme noch stimmt. |
| MySQL (Produktion) | `dist/docker-compose.prod.yml`, `dist/README.md` | Ziel ist `mysql:lts`. **Bekannter Blocker für 9.x:** MySQL 9.0 hat `mysql_native_password` entfernt; die Produktion setzt `--mysql-native-password=ON`, weil der MariaDB-Client im Alpine-Image für Backups kein `caching_sha2_password` kann. Ein Datenbank-Major ist ein Upgrade der Produktionsdaten — immer vorlegen, mit Hinweis für den Betrieb (Backup vorher). |
| MariaDB (ddev) | `.ddev/config.yaml` | Ziel ist `mariadb:lts`, sofern ddev sie anbietet (Skript prüft beides). Wechsel mit `ddev utility migrate-database`, nicht durch bloßes Umstellen der Zahl. Weicht bewusst von MySQL in Produktion ab; das nicht im Update-Lauf "angleichen". |
| Tachyon (Webmail) | `dist/webmail/Dockerfile`, `.ddev/docker-compose.webmail.yaml`, `tests/Feature/WebmailContainerDefinitionsTest.php` (`TACHYON_IMAGE`), `README.md` | Alle Stellen gleich — der Test prüft genau das. Bei einem Major prüfen, ob das Plugin `chormanager-sso` (Plugin-API, Pfade unter `/var/lib/tachyon`) und `enable-plugin.sh` noch passen. |
| Collabora | `.ddev/docker-compose.collabora.yaml` | Fester Tag; das Datum im Kommentar ("Stand …") mit anheben. Nur dev, nur mit Profil `collabora`. |
| Frontend-Pakete | `bin/copy-assets.php` | Kopiert feste Pfade, teils versionsgebunden (`tinymce-i18n/langs8/`) und teils aus indirekten Paketen (`tone`, `@magenta/music` unter html-midi-player). Nach einem Major oder einem Sprung dieser Pakete muss `ddev composer copy-assets` fehlerfrei laufen. Kein PHP-Test prüft die Frontend-Pakete im Browser — das gehört so in den Bericht. |
| GitHub Actions | `.github/workflows/*.yml` | Auf Major gepinnt (`@v7`). Neuer Major = Release-Notes auf geänderte Eingaben prüfen; dabei auch, ob `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24` noch nötig ist. `runs-on: ubuntu-latest` ist gleitend und bleibt so. |
| Playwright | `package.json` (`@playwright/test`) | Jede Version erwartet eine eigene Browser-Revision. Nach dem Update `ddev exec npx playwright install chromium` (Zielpfad kommt aus `PLAYWRIGHT_BROWSERS_PATH`) — sonst scheitert der nächste E2E-Lauf. |
| bleibt gleitend | `composer:latest` (Dockerfile), `composer_version: "2"` (ddev), eigene `chormanager-*:latest`-Images, djlint | Absichtlich nicht gepinnt bzw. vom Deploy gebaut. Nichts zu tun. |

## 5. Prüfen

| Geändert | Beleg |
|---|---|
| Composer oder npm | `ddev composer test:parallel`, `npm run test:js`, `ddev composer phpcs` |
| npm-Frontend-Pakete | `ddev composer copy-assets` läuft fehlerfrei (sonst fehlt eine Datei in `public/vendor/`) |
| `Dockerfile` / `Dockerfile.web` / `dist/webmail/Dockerfile` | das betroffene Image lokal bauen: `docker build -t chormanager-app:check .`, `docker build -f Dockerfile.web --build-arg APP_IMAGE=chormanager-app:check .`, `docker build dist/webmail`. Nur so ist belegt, dass das neue Basis-Image mit den `apk`-Paketen und Extensions noch baut. Der Deploy baut zusätzlich arm64 — bei neuem Basis-Tag mit `docker buildx imagetools inspect <image>` prüfen, dass es `linux/arm64` gibt. |
| `.ddev/config.yaml` oder `.ddev/docker-compose.*.yaml` | `ddev restart` und danach die Suite erneut |
| Playwright | Browser nachinstallieren (siehe oben) |

E2E-Läufe nur, wenn der Entwickler darum bittet — sie dauern lange. Im Bericht vermerken,
dass sie nicht liefen.

## 6. Bericht und Commit

Dem Entwickler eine Übersicht geben, gegliedert nach Bereich:

- **eingespielt**: Paket/Image, alt → neu (bei Composer die direkten Pakete einzeln, die
  transitiven zusammengefasst mit Anzahl; transitive Majors einzeln mit Begründung)
- **Majors**: welche vorgelegt wurden, welche angehoben, welche bewusst nicht und warum
- **offen**: Audit-Befunde ohne Fix, mit Pfad und Einschätzung
- **Prüfläufe** mit Zahlen, und was nicht lief
- **Hinweis für den Betrieb**, falls nötig (Datenbank-Upgrade, Browser nachinstallieren)

Alles landet in **einem** Commit `build(deps): …` über den Skill `git-commit` — dort stehen
Nachrichtenaufbau, Squash, Fast-Forward und die Push-Rückfrage. Majors bekommen im
Commit-Körper je einen eigenen Absatz mit dem Grund, weil sie das Einzige sind, was man
später gezielt suchen wird.
