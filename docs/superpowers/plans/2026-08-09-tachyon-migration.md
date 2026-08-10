# Webmail-Migration SnappyMail → Tachyon — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Das eingebettete Webmail läuft statt auf dem eingestellten SnappyMail auf Tachyon (`ghcr.io/kimusan/tachyon:v3.2.2`), in Dev und Prod, mit produktneutralen `WEBMAIL_*`-Bezeichnern.

**Architecture:** Tachyon ist ein SnappyMail-Fork mit unverändertem Daten- und Config-Layout. Das bestehende Muster bleibt erhalten: eigenes Image auf Basis des offiziellen Tachyon-Images mit eingebackenem `chormanager-sso`-Plugin, das per Hintergrundskript neben dem Original-Entrypoint aktiviert wird. Geändert werden Pfade (`/var/lib/snappymail` → `/var/lib/tachyon`), der Asset-Prefix (`/snappymail/` → `/tachyon/`), zwei PHP-Klassennamen im Plugin und alle eigenen Bezeichner (`SNAPPYMAIL_*` → `WEBMAIL_*`).

**Tech Stack:** DDEV, Docker Compose, PHP 8.3 (Slim, PHP-DI Autowiring), PHPUnit, nginx, GitHub Actions, Tachyon v3.2.2.

**Spec:** `docs/superpowers/specs/2026-08-09-tachyon-migration-design.md`

## Global Constraints

- Tachyon-Image ist **exakt** auf `ghcr.io/kimusan/tachyon:v3.2.2` gepinnt — kein `latest`, kein Floating-Tag.
- Alle neu geschriebenen oder geänderten Textdateien haben **LF**-Zeilenenden (Ausnahme `.bat`/`.cmd`/`.ps1`). Der Pre-Commit-Hook prüft das; er braucht einen laufenden Web-Container (`ddev start`).
- PHP folgt PSR-12, 4 Leerzeichen, Zeilenlänge weich 120 / hart 130.
- Deutsche Texte verwenden echte Umlaute (ä/ö/ü/ß), keine Transliteration.
- Env-Variablen heißen `WEBMAIL_SSO_SECRET`, `WEBMAIL_UPLOAD_MAX_SIZE`, `WEBMAIL_MEMORY_LIMIT`. `MAIL_CREDENTIAL_KEY` und `FEATURE_WEBMAIL` bleiben unverändert.
- Der Plugin-Ordner heißt weiterhin `chormanager-sso`; der Klassenname `ChormanagerSsoPlugin` leitet sich daraus ab und darf nicht umbenannt werden.
- Der Volume-Key `snappymail_data` in `dist/docker-compose.prod.yml` bleibt bewusst stehen (physische Volume-Identität). Nur sein Mountpfad wechselt.
- Kein `git push` — der Plan endet nach lokalen Commits.
- Verifizierte Tachyon-Klassennamen: `\Tachyon\Plugins\AbstractPlugin`, `\Tachyon\Util\SensitiveString`. `\SnappyMail\SensitiveString` existiert in Tachyon **nicht** (kein Compat-Shim).

## File Structure

| Datei | Verantwortung | Aktion |
| --- | --- | --- |
| `src/Services/WebmailSsoTokenService.php` | Verschlüsselt kurzlebige SSO-Payloads | umbenannt aus `SnappymailSsoTokenService.php` |
| `src/Controllers/WebmailController.php` | Erzeugt Token, leitet auf `/webmail/?chormanager-sso&token=…` | Typ-/Import-Anpassung |
| `tests/Unit/Services/WebmailSsoTokenServiceTest.php` | Unit-Tests des Token-Services | umbenannt |
| `tests/Feature/WebmailControllerFeatureTest.php` | Feature-Tests des SSO-Starts | Env-Key + Klassenname |
| `tests/Feature/WebmailContainerDefinitionsTest.php` | **Neu** — Guard: Container-Definitionen sind vollständig auf Tachyon migriert | erstellt |
| `tests/Feature/StackResilienceFeatureTest.php` | Guard: Prod-Compose-Aliase | Alias-Assertion |
| `tests/Feature/WebmailFeatureFlagTest.php` | Guard: dokumentierte SWAG-Config | `/tachyon/`-Assertions |
| `.ddev/docker-compose.webmail.yaml` | Dev-Webmail-Service | umbenannt + Inhalt |
| `.ddev/webmail-plugins/chormanager-sso/index.php` | SSO-Plugin (Dev-Kopie) | umbenannter Pfad + Namespaces |
| `.ddev/webmail-plugins/enable-plugin.sh` | Plugin-Aktivierung im Dev-Container | umbenannter Pfad + Pfade/Env |
| `.ddev/nginx_full/nginx-site.conf` | Dev-Reverse-Proxy `/webmail/` + `/tachyon/` | Locations |
| `dist/webmail/Dockerfile` | Prod-Image auf Tachyon-Basis | umbenannter Pfad + Basis |
| `dist/webmail/chormanager-sso/index.php` | SSO-Plugin (Prod-Kopie, inhaltsgleich zur Dev-Kopie) | umbenannter Pfad + Namespaces |
| `dist/webmail/enable-plugin.sh` | Plugin-Sync + Aktivierung im Prod-Image | umbenannter Pfad + Pfade/Env |
| `dist/docker-compose.prod.yml` | Prod-Stack | Service/Image/Env/Mount/Alias/Label |
| `.github/workflows/deploy.yml` | Image-Build | Kontext/Name/Cache-Scope |
| `dist/grafana/chormanager-logs.json` | Log-Dashboard | Service-Filter |
| `.env.example`, `README.md`, `dist/README.md` | Doku inkl. Betreiber-Migration | Texte |

---

### Task 1: Token-Service umbenennen und Env-Key umstellen

**Files:**
- Rename: `src/Services/SnappymailSsoTokenService.php` → `src/Services/WebmailSsoTokenService.php`
- Rename: `tests/Unit/Services/SnappymailSsoTokenServiceTest.php` → `tests/Unit/Services/WebmailSsoTokenServiceTest.php`
- Test: `tests/Unit/Services/WebmailSsoTokenServiceTest.php`

**Interfaces:**
- Consumes: nichts.
- Produces: `App\Services\WebmailSsoTokenService` mit `__construct()` (wirft `RuntimeException`, wenn `WEBMAIL_SSO_SECRET` fehlt oder kein Base64 mit 32 Byte ist) und `createToken(array $payload): string` (Rückgabe `base64(nonce . ciphertext)`). Env-Konstante: `private const KEY_ENV = 'WEBMAIL_SSO_SECRET';`.

- [ ] **Step 1: Testdatei umbenennen und auf neuen Namen umstellen**

```bash
git mv tests/Unit/Services/SnappymailSsoTokenServiceTest.php tests/Unit/Services/WebmailSsoTokenServiceTest.php
```

In der umbenannten Datei ersetzen (alle Vorkommen):
- `use App\Services\SnappymailSsoTokenService;` → `use App\Services\WebmailSsoTokenService;`
- `class SnappymailSsoTokenServiceTest` → `class WebmailSsoTokenServiceTest`
- `new SnappymailSsoTokenService()` → `new WebmailSsoTokenService()` (6 Vorkommen)
- `private const ENV_KEY = 'SNAPPYMAIL_SSO_SECRET';` → `private const ENV_KEY = 'WEBMAIL_SSO_SECRET';`

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Services/WebmailSsoTokenServiceTest.php`
Expected: FAIL — `Error: Class "App\Services\WebmailSsoTokenService" not found`

- [ ] **Step 3: Service umbenennen**

```bash
git mv src/Services/SnappymailSsoTokenService.php src/Services/WebmailSsoTokenService.php
```

Datei-Inhalt anpassen — Doc-Block und Bezeichner:

```php
/**
 * One-directional encryption of short-lived webmail SSO payloads.
 *
 * Uses libsodium's secretbox (XSalsa20-Poly1305) with a key read from the
 * WEBMAIL_SSO_SECRET environment variable. This key protects the trust
 * boundary between ChorManager and the Tachyon plugin; it is distinct
 * from MAIL_CREDENTIAL_KEY (which protects stored IMAP credentials at
 * rest). Fails closed: if the key is missing or malformed, the constructor
 * throws rather than allowing a token to be created with a default key.
 *
 * ChorManager only ever encrypts. Decryption happens inside the Tachyon
 * plugin (a different runtime), so no decode method is provided here.
 */
final class WebmailSsoTokenService
{
    private const KEY_ENV = 'WEBMAIL_SSO_SECRET';
```

Und beide Exception-Meldungen in `loadKey()`:

```php
            throw new RuntimeException('WEBMAIL_SSO_SECRET is not configured correctly');
```

Der Rest der Datei (Namespace `App\Services`, `createToken()`, `loadKey()`-Logik) bleibt unverändert.

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Services/WebmailSsoTokenServiceTest.php`
Expected: PASS (alle Tests grün)

- [ ] **Step 5: Commit**

```bash
git add src/Services/WebmailSsoTokenService.php tests/Unit/Services/WebmailSsoTokenServiceTest.php
git commit -m "refactor(webmail): SsoTokenService produktneutral benennen"
```

---

### Task 2: Controller auf den neuen Service ziehen

**Files:**
- Modify: `src/Controllers/WebmailController.php:9,21,26`
- Test: `tests/Feature/WebmailControllerFeatureTest.php`

**Interfaces:**
- Consumes: `App\Services\WebmailSsoTokenService` aus Task 1 (`createToken(array): string`).
- Produces: `WebmailController::start()` unverändert in Signatur und Route (`POST /profile/webmail/start`), nur der injizierte Typ ändert sich. Die Verdrahtung läuft über PHP-DI-Autowiring; es gibt keine explizite Container-Definition, die angepasst werden müsste.

- [ ] **Step 1: Feature-Test auf neuen Namen und Env-Key umstellen**

In `tests/Feature/WebmailControllerFeatureTest.php` ersetzen:
- `use App\Services\SnappymailSsoTokenService;` → `use App\Services\WebmailSsoTokenService;`
- `private const SSO_ENV_KEY = 'SNAPPYMAIL_SSO_SECRET';` → `private const SSO_ENV_KEY = 'WEBMAIL_SSO_SECRET';`
- `private SnappymailSsoTokenService $ssoTokenService;` → `private WebmailSsoTokenService $ssoTokenService;`
- `new SnappymailSsoTokenService()` → `new WebmailSsoTokenService()`

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailControllerFeatureTest.php`
Expected: FAIL — der Controller injiziert noch `SnappymailSsoTokenService`, dessen Konstruktor `SNAPPYMAIL_SSO_SECRET` verlangt (`RuntimeException: SNAPPYMAIL_SSO_SECRET is not configured correctly`)

- [ ] **Step 3: Controller anpassen**

In `src/Controllers/WebmailController.php`:

```php
use App\Services\WebmailSsoTokenService;
```

```php
    private WebmailSsoTokenService $ssoTokenService;
```

```php
        WebmailSsoTokenService $ssoTokenService
```

Property-Zuweisung (`$this->ssoTokenService = $ssoTokenService;`) und Aufruf (`$this->ssoTokenService->createToken($payload)`) bleiben unverändert.

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailControllerFeatureTest.php`
Expected: PASS

- [ ] **Step 5: Sicherstellen, dass kein alter Klassenname mehr referenziert wird**

Run: `ddev exec grep -rn "SnappymailSsoTokenService" src tests`
Expected: keine Ausgabe (Exit-Code 1)

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/WebmailController.php tests/Feature/WebmailControllerFeatureTest.php
git commit -m "refactor(webmail): Controller nutzt WebmailSsoTokenService"
```

---

### Task 3: SSO-Plugin auf Tachyon-Namespaces umstellen

**Files:**
- Modify: `.ddev/snappymail-plugins/chormanager-sso/index.php`
- Modify: `dist/snappymail/chormanager-sso/index.php`
- Create: `tests/Feature/WebmailContainerDefinitionsTest.php`

Die Verzeichnisse werden erst in Task 4 (Dev) bzw. Task 5 (Prod) umbenannt. Dieser Task ändert nur Dateiinhalte, damit der Namespace-Wechsel isoliert reviewbar bleibt.

**Interfaces:**
- Consumes: Env-Variable `WEBMAIL_SSO_SECRET` (wird in Task 4/5 in den Container gereicht).
- Produces: Globale Klasse `ChormanagerSsoPlugin extends \Tachyon\Plugins\AbstractPlugin` mit Part-Hook `chormanager-sso`. Beide Plugin-Kopien sind byte-identisch.

- [ ] **Step 1: Guard-Test schreiben**

Neue Datei `tests/Feature/WebmailContainerDefinitionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Guards gegen eine halbe Migration: Die Webmail-Container-Definitionen und das
 * SSO-Plugin müssen vollständig auf Tachyon zeigen. Bleiben SnappyMail-Pfade
 * oder -Klassennamen stehen, startet der Container zwar, aber der SSO-Login
 * bricht zur Laufzeit ab (z. B. weil \SnappyMail\SensitiveString in Tachyon
 * nicht mehr existiert - dafür gibt es keinen Compat-Shim).
 */
class WebmailContainerDefinitionsTest extends TestCase
{
    private const TACHYON_IMAGE = 'ghcr.io/kimusan/tachyon:v3.2.2';

    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents($this->repoRoot() . '/' . $relativePath);
        $this->assertIsString($content, $relativePath . ' ist nicht lesbar.');

        return $content;
    }

    /**
     * @return array<int, string>
     */
    public static function pluginCopies(): array
    {
        // Pfade wandern in Task 4 (Dev) bzw. Task 5 (Prod) auf webmail-*.
        return [
            ['.ddev/snappymail-plugins/chormanager-sso/index.php'],
            ['dist/snappymail/chormanager-sso/index.php'],
        ];
    }

    /**
     * @dataProvider pluginCopies
     */
    public function testPluginExtendsTheTachyonBaseClass(string $path): void
    {
        $plugin = $this->read($path);

        $this->assertStringContainsString('extends \\Tachyon\\Plugins\\AbstractPlugin', $plugin);
        $this->assertStringNotContainsString('RainLoop\\Plugins\\AbstractPlugin', $plugin);
    }

    /**
     * @dataProvider pluginCopies
     */
    public function testPluginUsesTheTachyonSensitiveString(string $path): void
    {
        $plugin = $this->read($path);

        $this->assertStringContainsString('\\Tachyon\\Util\\SensitiveString', $plugin);
        $this->assertStringNotContainsString('SnappyMail\\SensitiveString', $plugin);
    }

    /**
     * @dataProvider pluginCopies
     */
    public function testPluginReadsTheNeutralSsoSecret(string $path): void
    {
        $plugin = $this->read($path);

        $this->assertStringContainsString("getenv('WEBMAIL_SSO_SECRET')", $plugin);
        $this->assertStringNotContainsString('SNAPPYMAIL_SSO_SECRET', $plugin);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailContainerDefinitionsTest.php`
Expected: FAIL — beide Plugin-Kopien erweitern noch `\RainLoop\Plugins\AbstractPlugin`, nutzen `\SnappyMail\SensitiveString` und lesen `SNAPPYMAIL_SSO_SECRET`

- [ ] **Step 3: Beide Plugin-Kopien anpassen**

In **beiden** Dateien (`.ddev/snappymail-plugins/chormanager-sso/index.php` und `dist/snappymail/chormanager-sso/index.php`) identisch ändern.

Kopfkommentar (ersetzt den bisherigen Block):

```php
<?php

/**
 * ChorManager Single-Sign-On plugin for Tachyon (SnappyMail successor).
 *
 * Consumes a short-lived, libsodium-encrypted token issued by ChorManager
 * (App\Services\WebmailSsoTokenService) at
 * GET /webmail/?chormanager-sso&token=... and logs the user straight into
 * their IMAP mailbox via Actions::LoginProcess(), without a second login
 * prompt.
 *
 * Trust boundary: the token is encrypted with WEBMAIL_SSO_SECRET, a
 * secret shared only between ChorManager and this plugin - it is NOT the
 * same key ChorManager uses to encrypt stored IMAP credentials at rest
 * (MAIL_CREDENTIAL_KEY). Never reuse that key here.
 *
 * Every failure path is fail-closed: log a safe (no secret/password)
 * message and redirect to the plain /webmail/ login screen. Nothing is
 * ever echoed to the response on error.
 *
 * Deliberately declared in the GLOBAL namespace (no "namespace" statement):
 * Tachyon\Plugins\Manager::loadPluginByName() resolves the plugin class via
 * a bare, unqualified class_exists($sClassName)/is_subclass_of($sClassName,
 * 'Tachyon\Plugins\AbstractPlugin') check where $sClassName is the
 * unqualified "ChormanagerSsoPlugin" computed from the folder name -
 * declaring this class inside a namespace makes it fully qualified, which
 * class_exists('ChormanagerSsoPlugin') does not find, producing
 * "Invalid plugin class ChormanagerSsoPlugin" in the Tachyon log and the
 * plugin silently not loading.
 *
 * Tachyon renamed the RainLoop/SnappyMail namespaces to Tachyon\. Compat
 * shims exist for RainLoop\Plugins\*, but NOT for SnappyMail\SensitiveString
 * (now Tachyon\Util\SensitiveString) - so this plugin targets the Tachyon
 * names directly instead of relying on shims.
 */
class ChormanagerSsoPlugin extends \Tachyon\Plugins\AbstractPlugin
{
```

Konstanten-Block: `REQUIRED` auf die erste Tachyon-Release heben:

```php
		REQUIRED    = '3.0.1',
```

In `handleSso()` den Env-Namen ändern:

```php
		$sSecretB64 = (string) \getenv('WEBMAIL_SSO_SECRET');
```

Im Doc-Block von `ensureDomainConfig()` die Produktnennung angleichen:

```php
	/**
	 * Write (or overwrite) the Tachyon domain JSON for the email's domain so
	 * that LoginProcess() connects to the correct IMAP server instead of the
	 * default localhost:143 fallback.
	 *
	 * Tachyon resolves the IMAP server by loading
	 * APP_PRIVATE_DATA/domains/{domain}.json at login time. Without this file
	 * the default.json (localhost:143) is used, causing ConnectionError.
```

Im Login-Aufruf die Klasse tauschen:

```php
			$this->Manager()->Actions()->LoginProcess(
				$sEmail,
				new \Tachyon\Util\SensitiveString($sPassword),
				true
			);
```

Alles andere (Replay-Marker-Logik, `sweepOldMarkers()`, `decryptPayload()`, `safeWriteLog()`, die Sicherheits- und Multi-Tenant-Kommentare, `APP_PRIVATE_DATA`-Nutzung) bleibt unverändert — diese APIs wurden gegen den Tachyon-Source geprüft und existieren unverändert.

- [ ] **Step 4: Beide Kopien auf Identität prüfen**

Run: `ddev exec diff .ddev/snappymail-plugins/chormanager-sso/index.php dist/snappymail/chormanager-sso/index.php`
Expected: keine Ausgabe (Dateien identisch)

- [ ] **Step 5: Test laufen lassen, Erfolg bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailContainerDefinitionsTest.php`
Expected: PASS (6 Tests: 3 Assertions × 2 Plugin-Kopien)

- [ ] **Step 6: Commit**

```bash
git add .ddev/snappymail-plugins/chormanager-sso/index.php dist/snappymail/chormanager-sso/index.php tests/Feature/WebmailContainerDefinitionsTest.php
git commit -m "refactor(webmail): SSO-Plugin auf Tachyon-Namespaces umstellen"
```

---

### Task 4: DDEV-Stack auf Tachyon umstellen

**Files:**
- Rename: `.ddev/docker-compose.snappymail.yaml` → `.ddev/docker-compose.webmail.yaml`
- Rename: `.ddev/snappymail-plugins/` → `.ddev/webmail-plugins/`
- Modify: `.ddev/webmail-plugins/enable-plugin.sh`
- Modify: `.ddev/nginx_full/nginx-site.conf:3-5,26-63`
- Modify: `tests/Feature/WebmailContainerDefinitionsTest.php` (neue Assertions)
- Lokal (gitignored, nicht committen): `.ddev/.env.snappymail` → `.ddev/.env.webmail`, `.env`

**Interfaces:**
- Consumes: Plugin aus Task 3.
- Produces: Compose-Service **`webmail`** (DNS-Name im DDEV-Netz), erreichbar auf Port 8888; Datenpfad `/var/lib/tachyon`; Plugin-Mount unter `/var/lib/tachyon/_data_/_default_/plugins/chormanager-sso`; Env-Variable `WEBMAIL_SSO_SECRET` im Container.

- [ ] **Step 1: Guard-Assertions für den Dev-Stack ergänzen**

In `tests/Feature/WebmailContainerDefinitionsTest.php` im Provider `pluginCopies()` den Dev-Pfad auf den neuen Ort umstellen:

```php
            ['.ddev/webmail-plugins/chormanager-sso/index.php'],
```

Und innerhalb der Klasse ergänzen:

```php
    public function testDevComposeUsesThePinnedTachyonImage(): void
    {
        $compose = $this->read('.ddev/docker-compose.webmail.yaml');

        $this->assertStringContainsString('image: ' . self::TACHYON_IMAGE, $compose);
        $this->assertStringNotContainsString('djmaze/snappymail', $compose);
    }

    public function testDevComposeUsesTheTachyonDataPaths(): void
    {
        $compose = $this->read('.ddev/docker-compose.webmail.yaml');

        $this->assertStringContainsString(':/var/lib/tachyon', $compose);
        $this->assertStringContainsString(
            '/var/lib/tachyon/_data_/_default_/plugins/chormanager-sso',
            $compose
        );
        $this->assertStringNotContainsString('/var/lib/snappymail', $compose);
    }

    public function testDevEnablePluginScriptTargetsTachyon(): void
    {
        $script = $this->read('.ddev/webmail-plugins/enable-plugin.sh');

        $this->assertStringContainsString(
            'CONFIG_FILE="/var/lib/tachyon/_data_/_default_/configs/application.ini"',
            $script
        );
        $this->assertStringContainsString('env[WEBMAIL_SSO_SECRET]', $script);
        $this->assertStringNotContainsString('SNAPPYMAIL_SSO_SECRET', $script);
    }

    public function testDevNginxProxiesTheTachyonAssetPrefix(): void
    {
        $nginx = $this->read('.ddev/nginx_full/nginx-site.conf');

        $this->assertStringContainsString('location /tachyon/ {', $nginx);
        $this->assertStringContainsString('proxy_pass http://webmail:8888/tachyon/;', $nginx);
        $this->assertStringContainsString('proxy_pass http://webmail:8888/;', $nginx);
        $this->assertStringNotContainsString('/snappymail/', $nginx);
    }
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailContainerDefinitionsTest.php`
Expected: FAIL — `.ddev/docker-compose.webmail.yaml` und `.ddev/webmail-plugins/enable-plugin.sh` sind nicht lesbar

- [ ] **Step 3: Verzeichnisse und Compose-Datei umbenennen**

```bash
git mv .ddev/snappymail-plugins .ddev/webmail-plugins
git mv .ddev/docker-compose.snappymail.yaml .ddev/docker-compose.webmail.yaml
```

- [ ] **Step 4: Compose-Datei neu schreiben**

Vollständiger neuer Inhalt von `.ddev/docker-compose.webmail.yaml`:

```yaml
# Tachyon webmail service for ChorManager's local DDEV environment.
#
# Image: ghcr.io/kimusan/tachyon (https://github.com/kimusan/Tachyon), the
# maintained fork of the discontinued SnappyMail. Verified via the project's
# .docker/release/Dockerfile and entrypoint.sh:
#   - EXPOSE 8888 (nginx, the port to reach for HTTP) and EXPOSE 9000 (internal php-fpm,
#     not for external use).
#   - VOLUME /var/lib/tachyon holds all persistent config and per-account state.
#   - entrypoint.sh supports UPLOAD_MAX_SIZE, MEMORY_LIMIT, SECURE_COOKIES, DEBUG env vars.
#     There is no admin-password env var; the image auto-generates one on first boot and
#     writes it to /var/lib/tachyon/_data_/_default_/admin_password.txt inside the
#     volume (retrievable via `ddev exec -s webmail cat ...` if the admin UI is needed).
#
# No host port is published: only the `web` container needs to reach this service, and it
# does so by service name (`webmail`) over the DDEV project's default network, which
# every service defined here joins automatically (per DDEV's documented behavior for
# .ddev/docker-compose.*.yaml add-on files).
#
# SSO auto-login wiring:
#   - The `chormanager-sso` plugin source is bind-mounted from this repo into Tachyon's
#     plugins directory (APP_PLUGINS_PATH resolves to
#     /var/lib/tachyon/_data_/_default_/plugins/ at runtime). NOT mounted `:ro`: the
#     image's entrypoint.sh unconditionally runs `chown -R www-data:www-data
#     /var/lib/tachyon/` on every boot, which would fail with "Read-only file system"
#     under a `:ro` bind mount and (because entrypoint.sh uses `set -eu`) abort the whole
#     container before nginx/php-fpm ever start. The plugin source itself is still only
#     ever read by the application, never written to.
#   - `enable-plugin.sh` is bind-mounted read-only and run in the background by the
#     `command:` override below via `sh /chormanager-enable-plugin.sh` (not executed
#     directly - Windows-host bind mounts do not preserve the executable bit, so a direct
#     `/chormanager-enable-plugin.sh &` invocation fails with "Permission denied"). It
#     idempotently flips `[plugins] enable = On` and appends `chormanager-sso` to
#     `enabled_list` in application.ini once the image's entrypoint has generated that file
#     on first boot, then gets out of the way.
#   - The image declares `ENTRYPOINT []` and `CMD ["/entrypoint.sh"]`, so overriding
#     `command:` here fully and safely replaces startup - `exec /entrypoint.sh` at the end
#     preserves the image's normal startup behavior unchanged.
#   - WEBMAIL_SSO_SECRET is the shared secret between ChorManager
#     (App\Services\WebmailSsoTokenService) and the plugin; it is distinct from
#     MAIL_CREDENTIAL_KEY (which protects stored IMAP credentials at rest).
#   - IMPORTANT (also applies to WEBMAIL_UPLOAD_MAX_SIZE/WEBMAIL_MEMORY_LIMIT below):
#     "${VAR}" interpolation in .ddev/docker-compose.*.yaml is resolved from DDEV's own
#     process environment, NOT from this project's root .env file (that file is loaded by
#     the PHP app at runtime via vlucas/phpdotenv - a completely separate mechanism).
#     Setting WEBMAIL_SSO_SECRET only in the project root .env produces
#     `level=warning msg="The \"WEBMAIL_SSO_SECRET\" variable is not set."` on `ddev
#     restart`, and the plugin then sees an empty secret. Fix: also write it to
#     .ddev/.env.webmail (gitignored, like .env), e.g.:
#       ddev dotenv set .ddev/.env.webmail --webmail-sso-secret="$(php -r 'echo base64_encode(random_bytes(32));')"
#     DDEV auto-loads any .ddev/.env* file for compose variable substitution.
services:
  webmail:
    image: ghcr.io/kimusan/tachyon:v3.2.2
    container_name: ddev-${DDEV_SITENAME}-webmail
    labels:
      com.ddev.site-name: ${DDEV_SITENAME}
      com.ddev.approot: ${DDEV_APPROOT}
    restart: "no"
    environment:
      - UPLOAD_MAX_SIZE=${WEBMAIL_UPLOAD_MAX_SIZE:-25M}
      - MEMORY_LIMIT=${WEBMAIL_MEMORY_LIMIT:-128M}
      - SECURE_COOKIES=true
      - WEBMAIL_SSO_SECRET=${WEBMAIL_SSO_SECRET}
    volumes:
      - webmail_data:/var/lib/tachyon
      # Paths in .ddev/docker-compose.*.yaml are resolved relative to the .ddev/ directory
      # itself (a "./.ddev/webmail-plugins/..." path would resolve to the doubled
      # ".ddev/.ddev/webmail-plugins/..." and silently bind-mount an empty directory
      # instead) - so these are "./webmail-plugins/...", not "./.ddev/...".
      - ./webmail-plugins/chormanager-sso:/var/lib/tachyon/_data_/_default_/plugins/chormanager-sso
      - ./webmail-plugins/enable-plugin.sh:/chormanager-enable-plugin.sh:ro
    command: ["sh", "-c", "sh /chormanager-enable-plugin.sh & exec /entrypoint.sh"]

volumes:
  webmail_data:
    name: ${DDEV_SITENAME}-webmail
```

- [ ] **Step 5: `enable-plugin.sh` (Dev) anpassen**

In `.ddev/webmail-plugins/enable-plugin.sh` ändern:

```sh
# Idempotently enables the chormanager-sso plugin in Tachyon's
# application.ini once the image's entrypoint has generated it on first
# boot. Runs in the background alongside the image's real entrypoint (see
# .ddev/docker-compose.webmail.yaml command: override) - it must not
# block or replace it.

CONFIG_FILE="/var/lib/tachyon/_data_/_default_/configs/application.ini"
```

Im php-fpm-Abschnitt beide Vorkommen des Env-Namens:

```sh
if [ -f "${FPM_POOL_CONFIG}" ] && ! grep -q '^env\[WEBMAIL_SSO_SECRET\]' "${FPM_POOL_CONFIG}"; then
    echo "env[WEBMAIL_SSO_SECRET] = \"${WEBMAIL_SSO_SECRET:-}\"" >> "${FPM_POOL_CONFIG}"
    echo "[chormanager-enable-plugin] Added WEBMAIL_SSO_SECRET passthrough to ${FPM_POOL_CONFIG}."
fi
```

Und im Kommentar darüber `getenv('SNAPPYMAIL_SSO_SECRET')` → `getenv('WEBMAIL_SSO_SECRET')`. Die Warteschleife auf die `<UPLOAD_MAX_SIZE>`/`<MEMORY_LIMIT>`-Platzhalter, die awk-Logik und alle übrigen Kommentare bleiben unverändert — Tachyons Entrypoint verhält sich hier identisch.

- [ ] **Step 6: nginx-Konfiguration anpassen**

In `.ddev/nginx_full/nginx-site.conf` den Kopfkommentar:

```
# Customized: this file has been taken over to add the /webmail reverse-proxy
# location for the Tachyon container defined in
# .ddev/docker-compose.webmail.yaml. DDEV will not overwrite this file.
```

Den `/webmail/`-Block samt Kommentar:

```
    # Reverse-proxy to the Tachyon webmail container (.ddev/docker-compose.webmail.yaml).
    # Trailing slash on both the location and proxy_pass strips the /webmail prefix, so
    # Tachyon (which serves from its own root, see its bundled nginx.conf: "root
    # /tachyon; try_files $uri $uri/ index.php;") receives plain root-relative requests
    # for the HTML shell. Tachyon has no baseuri/APPLICATION_DIR env var, and it emits
    # its own static assets with an absolute, version-pinned path
    # (/tachyon/v/<version>/static/..., /tachyon/v/<version>/themes/...) rather than
    # paths relative to /webmail/. The second location below proxies that absolute
    # prefix straight through (no stripping) so those requests reach Tachyon instead of
    # falling through to the ChorManager app's own router.
    location /webmail/ {
        client_max_body_size 25m;
        proxy_pass http://webmail:8888/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
```

Den Redirect-Kommentar (`Redirect the bare /webmail ... inside SnappyMail`) auf „inside Tachyon" ändern; der `location = /webmail`-Block selbst bleibt unverändert.

Den Asset-Block ersetzen:

```
    # Tachyon's own static assets (CSS/JS/images/fonts), requested by the browser at
    # this absolute path regardless of the /webmail/ subpath the HTML shell was served
    # from. No prefix stripping here: Tachyon's container serves these at the exact
    # same /tachyon/... path itself (its nginx root is /tachyon and the release tree
    # lives at /tachyon/tachyon/v/<version>/).
    location /tachyon/ {
        client_max_body_size 25m;
        proxy_pass http://webmail:8888/tachyon/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
```

- [ ] **Step 7: Lokale Env-Dateien umstellen (nicht committen — gitignored)**

Den bestehenden Wert auslesen (die Datei enthält eine Zeile `SNAPPYMAIL_SSO_SECRET=<base64>`):

```bash
cat .ddev/.env.snappymail
```

Diesen Wert unverändert in die neue Datei schreiben — der Schlüssel muss identisch bleiben, sonst schlagen alle SSO-Token fail-closed fehl:

```bash
ddev dotenv set .ddev/.env.webmail --webmail-sso-secret="<Wert aus der Ausgabe oben>"
```

Danach in der Projekt-`.env` die Zeile `SNAPPYMAIL_SSO_SECRET=<wert>` in `WEBMAIL_SSO_SECRET=<wert>` umbenennen (identischer Wert!) und `SNAPPYMAIL_UPLOAD_MAX_SIZE`/`SNAPPYMAIL_MEMORY_LIMIT` auf `WEBMAIL_*` umbenennen. Anschließend die alte Datei entfernen:

```bash
rm .ddev/.env.snappymail
```

- [ ] **Step 8: Container neu starten und Laufzeit prüfen**

```bash
ddev restart
ddev exec -s webmail grep -A2 '^\[plugins\]' /var/lib/tachyon/_data_/_default_/configs/application.ini
ddev exec -s webmail grep '^env\[WEBMAIL_SSO_SECRET\]' /usr/local/etc/php-fpm.d/php-fpm.conf
```

Expected: `enable = On` und `enabled_list = "chormanager-sso"` in der application.ini; die `env[WEBMAIL_SSO_SECRET] = "…"`-Zeile ist vorhanden und nicht leer.

- [ ] **Step 9: Guard-Tests laufen lassen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailContainerDefinitionsTest.php --filter Dev`
Expected: PASS (die vier Dev-Tests aus Step 1)

- [ ] **Step 10: Commit**

```bash
git add .ddev tests/Feature/WebmailContainerDefinitionsTest.php
git commit -m "feat(webmail): DDEV-Stack auf Tachyon umstellen"
```

---

### Task 5: Prod-Stack, Image-Build und Log-Dashboard umstellen

**Files:**
- Rename: `dist/snappymail/` → `dist/webmail/`
- Modify: `dist/webmail/Dockerfile`
- Modify: `dist/webmail/enable-plugin.sh`
- Modify: `dist/docker-compose.prod.yml:174-192,207-211`
- Modify: `.github/workflows/deploy.yml:37,63-72`
- Modify: `dist/grafana/chormanager-logs.json:711,736`
- Modify: `tests/Feature/StackResilienceFeatureTest.php:57`
- Modify: `tests/Feature/WebmailContainerDefinitionsTest.php` (Prod-Assertions)

**Interfaces:**
- Consumes: Plugin aus Task 3.
- Produces: Prod-Service **`webmail`**, Image `ghcr.io/<owner>/chormanager-webmail:latest`, Netz-Alias `chormanager-webmail-${STACK_ID:-prod}`, Log-Label `logs.service: "webmail"`, Volume-Key `snappymail_data` gemountet auf `/var/lib/tachyon`.

- [ ] **Step 1: Guard-Assertions für Prod ergänzen**

In `tests/Feature/WebmailContainerDefinitionsTest.php` im Provider `pluginCopies()` den Prod-Pfad auf den neuen Ort umstellen:

```php
            ['dist/webmail/chormanager-sso/index.php'],
```

Und ergänzen:

```php
    public function testProdImageBuildsOnThePinnedTachyonImage(): void
    {
        $dockerfile = $this->read('dist/webmail/Dockerfile');

        $this->assertStringContainsString('FROM ' . self::TACHYON_IMAGE, $dockerfile);
        $this->assertStringNotContainsString('djmaze/snappymail', $dockerfile);
    }

    public function testProdEnablePluginScriptTargetsTachyon(): void
    {
        $script = $this->read('dist/webmail/enable-plugin.sh');

        $this->assertStringContainsString(
            'CONFIG_FILE="/var/lib/tachyon/_data_/_default_/configs/application.ini"',
            $script
        );
        $this->assertStringContainsString(
            'PLUGINS_DIR="/var/lib/tachyon/_data_/_default_/plugins"',
            $script
        );
        $this->assertStringContainsString('env[WEBMAIL_SSO_SECRET]', $script);
    }

    /**
     * Der Volume-Key heißt weiterhin snappymail_data: Compose leitet den
     * physischen Volume-Namen daraus ab, eine Umbenennung würde ein leeres
     * Volume anlegen und Admin-Passwort samt Benutzereinstellungen verlieren.
     * Nur der Mountpfad wandert auf /var/lib/tachyon.
     */
    public function testProdComposeKeepsTheLegacyVolumeKeyButMountsTachyonPath(): void
    {
        $compose = $this->read('dist/docker-compose.prod.yml');

        $this->assertStringContainsString('- snappymail_data:/var/lib/tachyon', $compose);
        $this->assertStringNotContainsString(':/var/lib/snappymail', $compose);
    }

    public function testProdComposeUsesTheNeutralWebmailNames(): void
    {
        $compose = $this->read('dist/docker-compose.prod.yml');

        $this->assertStringContainsString('chormanager-webmail:latest', $compose);
        $this->assertStringContainsString('logs.service: "webmail"', $compose);
        $this->assertStringContainsString('WEBMAIL_SSO_SECRET:', $compose);
        $this->assertStringNotContainsString('SNAPPYMAIL_SSO_SECRET', $compose);
    }
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailContainerDefinitionsTest.php --filter Prod`
Expected: FAIL — `dist/webmail/Dockerfile` ist nicht lesbar

- [ ] **Step 3: Verzeichnis umbenennen**

```bash
git mv dist/snappymail dist/webmail
```

- [ ] **Step 4: Dockerfile neu schreiben**

Vollständiger neuer Inhalt von `dist/webmail/Dockerfile`:

```dockerfile
# Custom Tachyon image for ChorManager.
#
# Tachyon (https://github.com/kimusan/Tachyon) is the maintained fork of the
# discontinued SnappyMail; data directory and config layout are unchanged.
#
# Bakes the chormanager-sso SSO plugin into the image so that NO host-side
# bind-mounts are required - this is what makes the stack usable from the
# Portainer web editor (where you cannot place files next to the compose
# file).
#
# Pin the base tag deliberately; bump it consciously instead of tracking a
# floating tag.
FROM ghcr.io/kimusan/tachyon:v3.2.2

# The plugin source is baked OUTSIDE the /var/lib/tachyon volume. The
# startup script copies it into the volume on every boot, so pulling a new
# image actually updates the plugin even when the named volume already exists.
COPY chormanager-sso /opt/chormanager-sso/chormanager-sso
COPY enable-plugin.sh /chormanager-enable-plugin.sh

# The base image declares `ENTRYPOINT []` and `CMD ["/entrypoint.sh"]`, so
# overriding CMD fully replaces startup. We launch our idempotent enabler in
# the background and then exec the image's real entrypoint unchanged.
CMD ["sh", "-c", "sh /chormanager-enable-plugin.sh & exec /entrypoint.sh"]
```

- [ ] **Step 5: `enable-plugin.sh` (Prod) anpassen**

In `dist/webmail/enable-plugin.sh` ändern:

```sh
# Startup helper for the custom ChorManager Tachyon image. Runs in the
# background next to the image's real entrypoint (see the Dockerfile CMD) - it
# must not block or replace it.
#
# Responsibilities, in order:
#   1. Pass WEBMAIL_SSO_SECRET through to php-fpm workers.
#   2. Sync the baked plugin into the data volume (so image updates apply even
#      when the named volume already exists).
#   3. Enable the plugin in application.ini.

SRC_PLUGIN="/opt/chormanager-sso/chormanager-sso"
PLUGINS_DIR="/var/lib/tachyon/_data_/_default_/plugins"
DEST_PLUGIN="${PLUGINS_DIR}/chormanager-sso"
CONFIG_FILE="/var/lib/tachyon/_data_/_default_/configs/application.ini"
```

Und der php-fpm-Abschnitt:

```sh
if [ -f "${FPM_POOL_CONFIG}" ] && ! grep -q '^env\[WEBMAIL_SSO_SECRET\]' "${FPM_POOL_CONFIG}"; then
    echo "env[WEBMAIL_SSO_SECRET] = \"${WEBMAIL_SSO_SECRET:-}\"" >> "${FPM_POOL_CONFIG}"
    echo "[chormanager-enable-plugin] Added WEBMAIL_SSO_SECRET passthrough to ${FPM_POOL_CONFIG}."
fi
```

Im Kommentar darüber ebenfalls `getenv('SNAPPYMAIL_SSO_SECRET')` → `getenv('WEBMAIL_SSO_SECRET')`. Die awk-Logik und die Sync-Schritte bleiben unverändert.

- [ ] **Step 6: Prod-Compose anpassen**

In `dist/docker-compose.prod.yml` den Service-Block ersetzen (Service-Key `snappymail` → `webmail`):

```yaml
  webmail:
    image: ghcr.io/georg-pitterle/chormanager-webmail:latest
    restart: unless-stopped
    labels:
      logs.job: "chormanager"
      logs.stack: "${STACK_ID:-prod}"
      logs.service: "webmail"
      logs.format: "raw"
    environment:
      UPLOAD_MAX_SIZE: ${WEBMAIL_UPLOAD_MAX_SIZE:-25M}
      MEMORY_LIMIT: ${WEBMAIL_MEMORY_LIMIT:-128M}
      SECURE_COOKIES: "true"
      WEBMAIL_SSO_SECRET: ${WEBMAIL_SSO_SECRET:?set in Portainer}
    volumes:
      # Legacy-Key mit Absicht: Compose leitet den physischen Volume-Namen aus
      # dem Key ab. Ein Rename würde ein leeres Volume anlegen und das
      # Admin-Passwort samt aller Benutzereinstellungen verlieren. Nur der
      # Mountpfad wandert auf Tachyons /var/lib/tachyon.
      - snappymail_data:/var/lib/tachyon
    networks:
      proxy:
        aliases:
          - chormanager-webmail-${STACK_ID:-prod}
```

Healthcheck, `security_opt` und `logging` des Blocks bleiben unverändert. Im `environment:`-Block ganz oben in der Datei (Zeile 29, YAML-Anker für den App-Service) ebenfalls:

```yaml
  WEBMAIL_SSO_SECRET: ${WEBMAIL_SSO_SECRET:?set in Portainer}
```

Der `volumes:`-Abschnitt am Dateiende bleibt unverändert (`snappymail_data:` bleibt stehen).

- [ ] **Step 7: Deploy-Workflow anpassen**

In `.github/workflows/deploy.yml`:

```yaml
        echo "webmail=ghcr.io/$OWNER/chormanager-webmail:latest" >> "$GITHUB_OUTPUT"
```

```yaml
    - name: Build and push webmail image
      uses: docker/build-push-action@v7
      with:
        context: ./dist/webmail
        file: ./dist/webmail/Dockerfile
        push: true
        tags: ${{ steps.set-images.outputs.webmail }}
        platforms: linux/amd64,linux/arm64
        cache-from: type=gha,scope=webmail
        cache-to: type=gha,mode=max,scope=webmail
```

- [ ] **Step 8: Grafana-Dashboard anpassen**

In `dist/grafana/chormanager-logs.json`:
- Zeile 711: `"description": "Logzeilen von Nginx, MySQL und Webmail, neueste zuerst.",`
- Zeile 736: `"expr": "{job=\"chormanager\", stack=~\"$stack\", service=~\"web|db|webmail\"}",`

- [ ] **Step 9: Alias-Assertion im Resilienz-Test nachziehen**

In `tests/Feature/StackResilienceFeatureTest.php:57`:

```php
        $this->assertStringContainsString('chormanager-webmail-${STACK_ID:-prod}', $compose);
```

- [ ] **Step 10: Tests laufen lassen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailContainerDefinitionsTest.php tests/Feature/StackResilienceFeatureTest.php`
Expected: PASS (alle Tests, inklusive der Plugin-Assertions aus Task 3, die jetzt die neuen Pfade finden)

- [ ] **Step 11: Commit**

```bash
git add dist .github/workflows/deploy.yml tests/Feature/WebmailContainerDefinitionsTest.php tests/Feature/StackResilienceFeatureTest.php
git commit -m "feat(webmail): Prod-Stack und Image-Build auf Tachyon umstellen"
```

---

### Task 6: Dokumentation und Betreiber-Migration

**Files:**
- Modify: `.env.example:134-183`
- Modify: `README.md:113-136,169`
- Modify: `dist/README.md:56-60,105-113,144-203`
- Modify: `tests/Feature/WebmailFeatureFlagTest.php:209-224`

**Interfaces:**
- Consumes: die in Task 5 festgelegten Prod-Namen (`chormanager-webmail-prod`, `/tachyon/`).
- Produces: keine Code-Schnittstelle; die dokumentierte SWAG-Config ist die Vorlage, gegen die `WebmailFeatureFlagTest` prüft.

- [ ] **Step 1: Guard-Test auf die neue SWAG-Config umstellen**

In `tests/Feature/WebmailFeatureFlagTest.php` die Methode `testProdReadmeWebmailProxyStripsPrefixViaRewrite()` ersetzen:

```php
    public function testProdReadmeWebmailProxyStripsPrefixViaRewrite(): void
    {
        // SWAG proxies to a variable upstream (needed for its resolver). With a
        // variable upstream, proxy_pass does NOT strip the /webmail/ prefix via a
        // trailing slash the way a literal upstream does — it forwards the request
        // unchanged or drops the query, so Tachyons /webmail/?/AppData/... AJAX
        // calls get its HTML shell back ("Invalid Content-Type text/html"). The
        // documented config must therefore strip the prefix with an explicit
        // rewrite and must not rely on the trailing-slash form.
        $readme = file_get_contents(dirname(__DIR__, 2) . '/dist/README.md');
        $this->assertIsString($readme);

        $this->assertStringContainsString('rewrite ^/webmail/(.*) /$1 break;', $readme);
        $this->assertStringNotContainsString('proxy_pass http://$upstream_sm:8888/;', $readme);
        $this->assertStringNotContainsString('proxy_pass http://$upstream_sm:8888/tachyon/;', $readme);
    }

    /**
     * Tachyon liefert seine statischen Assets unter dem absoluten Prefix
     * /tachyon/ aus (nginx-root im Image ist /tachyon). Die dokumentierte
     * SWAG-Config muss genau diesen Pfad durchreichen, sonst lädt das Webmail
     * in Prod ohne CSS/JS.
     */
    public function testProdReadmeDocumentsTheTachyonAssetLocation(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/dist/README.md');
        $this->assertIsString($readme);

        $this->assertStringContainsString('location /tachyon/ {', $readme);
        $this->assertStringContainsString('set $upstream_sm chormanager-webmail-prod;', $readme);
        $this->assertStringNotContainsString('location /snappymail/ {', $readme);
    }
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php --filter Readme`
Expected: FAIL — `dist/README.md` dokumentiert noch `location /snappymail/` und `chormanager-snappymail-prod`

- [ ] **Step 3: `.env.example` anpassen**

Den Webmail-Abschnitt ersetzen (ab „Webmail-Modul" bis Dateiende):

```
# =========================================
# Webmail-Modul (Tachyon)
# =========================================
# true  = eingebettetes Tachyon-Webmail aktiv (Container + WEBMAIL_SSO_SECRET nötig)
# false = kein Webmail-Container nötig; optional kann jeder Benutzer im Profil die URL
#         eines externen Webmail-Clients hinterlegen (Mail-Badge verlinkt dorthin)
FEATURE_WEBMAIL=false

# =========================================
# Mail-Account-Verschlüsselung
# =========================================
# Symmetrischer Schlüssel (base64, 32 Bytes) zur Verschlüsselung der IMAP-Passwörter.
# Erzeugen mit: php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
MAIL_CREDENTIAL_KEY=

# Optionaler Übergangs-Schlüssel für eine Key-Rotation (gleiches Format).
# Nur setzen, solange noch Datensätze mit dem alten Schlüssel existieren;
# nach erfolgreichem "php bin/rotate_mail_key.php" wieder entfernen.
MAIL_CREDENTIAL_KEY_PREVIOUS=

# =========================================
# Webmail-Container (DDEV-Add-on, .ddev/docker-compose.webmail.yaml)
# =========================================
# Maximale Upload-Größe innerhalb des Webmail-Containers (PHP + nginx dort).
# Optional, Default im Compose-File: 25M.
WEBMAIL_UPLOAD_MAX_SIZE=25M

# PHP memory_limit innerhalb des Webmail-Containers.
# Optional, Default im Compose-File: 128M.
WEBMAIL_MEMORY_LIMIT=128M

# =========================================
# Webmail Single-Sign-On (Auto-Login)
# =========================================
# Nur relevant bei FEATURE_WEBMAIL=true.
# Symmetrischer Schlüssel (base64, 32 Bytes) zur Verschlüsselung des kurzlebigen
# SSO-Tokens zwischen ChorManager und dem Tachyon-Plugin (chormanager-sso).
# Dies ist ein eigenständiges Credential, NICHT identisch mit MAIL_CREDENTIAL_KEY.
# Erzeugen mit: php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
# Wichtig: Dieser Wert muss exakt mit der WEBMAIL_SSO_SECRET-Umgebungsvariable
# übereinstimmen, die der Webmail-Container erhält (siehe environment:-Block in
# .ddev/docker-compose.webmail.yaml, welcher denselben Host-Env-Wert liest).
# ACHTUNG (DDEV-Eigenheit): Diese .env-Datei wird nur von der PHP-Anwendung zur Laufzeit
# gelesen (vlucas/phpdotenv) - NICHT von DDEV selbst für die "${VAR}"-Ersetzung in
# .ddev/docker-compose.*.yaml. Damit der Webmail-Container den Wert tatsächlich erhält,
# zusätzlich setzen mit:
#   ddev dotenv set .ddev/.env.webmail --webmail-sso-secret="<gleicher Wert wie oben>"
# (.ddev/.env.webmail ist wie .env per .gitignore ausgeschlossen.)
WEBMAIL_SSO_SECRET=
```

- [ ] **Step 4: `README.md` anpassen**

Überschrift und Abschnitt (ab Zeile 113):

```markdown
## Webmail-Integration (Tachyon)

Pro Benutzer konfigurierbarer IMAP-Webmail-Zugang via [Tachyon](https://github.com/kimusan/Tachyon), eingebettet unter `/webmail`. Nach Konfiguration im Benutzerprofil (`/profile`) öffnet ein Klick die Inbox ohne zweiten Login-Dialog — ChorManager stellt ein kurzlebiges, signiertes Token aus, das der Webmail-Container automatisch konsumiert. Ein Ungelesen-Badge in der Navigation zeigt die Anzahl ungelesener Nachrichten. Nachrichteninhalte werden niemals in der ChorManager-Datenbank gespeichert.

Tachyon ist der gepflegte Fork des eingestellten SnappyMail; Migrationsentscheidungen stehen in `docs/superpowers/specs/2026-08-09-tachyon-migration-design.md`.
```

Der Verweis auf `docs/superpowers/specs/2026-05-12-snappymail-integration-plan.md` bleibt als historische Spezifikation erhalten, wird aber als „ursprüngliche Spezifikation (SnappyMail)" gekennzeichnet.

Env-Beschreibungen:

```markdown
- `WEBMAIL_SSO_SECRET` — Separater Base64-kodierter 32-Byte-Schlüssel; verschlüsselt den kurzlebigen Auto-Login-Token für das Tachyon-Plugin. **Muss identisch** in ChorManagers `.env` und im Webmail-Container gesetzt sein (siehe `.ddev/.env.webmail` für das lokale Dev-Wiring). Gleiche Generierung wie `MAIL_CREDENTIAL_KEY`. Darf nie gleich `MAIL_CREDENTIAL_KEY` sein.
- `WEBMAIL_UPLOAD_MAX_SIZE` (Dev-Standard: `25M`) — PHP `upload_max_filesize` im Webmail-Container
- `WEBMAIL_MEMORY_LIMIT` (Dev-Standard: `128M`) — PHP `memory_limit` im Webmail-Container
```

Dev-Setup-Absatz:

```markdown
Der Webmail-Container läuft als DDEV-Add-on-Service (`.ddev/docker-compose.webmail.yaml`, Image `ghcr.io/kimusan/tachyon:v3.2.2`). DDEV routet `/webmail/` via `.ddev/nginx_full/nginx-site.conf` per Reverse-Proxy an den Container, `/tachyon/` liefert dessen statische Assets. Das Auto-Login-Plugin liegt in `.ddev/webmail-plugins/chormanager-sso/` und wird beim Container-Start automatisch aktiviert. Details stehen direkt in diesen Dateien.
```

Die restlichen Nennungen von „SnappyMail" in `README.md` (DDEV-Interpolationshinweis, Produktions-Hinweis, Key-Rotations-Abschnitt in Zeile 169) analog auf „Webmail"/„Tachyon" und `.ddev/.env.webmail` umstellen.

- [ ] **Step 5: `dist/README.md` anpassen**

Env-Tabelle:

```markdown
| `WEBMAIL_SSO_SECRET`      | Shared secret app ⇄ Tachyon plugin (`openssl rand -base64 32`)       | -               | **Yes**  |
```

sowie `WEBMAIL_UPLOAD_MAX_SIZE` / `WEBMAIL_MEMORY_LIMIT` in denselben Zeilen. Der Abschnitt zu Volumes/Aliassen (Zeilen 105–113) nennt künftig `webmail` statt `snappymail` als Alias, behält aber `snappymail_data` als Volume-Namen mit dem Zusatz „(Legacy-Name, absichtlich beibehalten)".

Der Webmail-Abschnitt wird ersetzt durch:

```markdown
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
Umstieg einmalig von Hand erledigt werden:

1. **Portainer-Env:** `WEBMAIL_SSO_SECRET` mit dem bisherigen Wert von
   `SNAPPYMAIL_SSO_SECRET` anlegen, danach `SNAPPYMAIL_SSO_SECRET`,
   `SNAPPYMAIL_UPLOAD_MAX_SIZE` und `SNAPPYMAIL_MEMORY_LIMIT` entfernen. Der
   Wert muss auf App- und Webmail-Seite identisch bleiben.
2. **SWAG-Config:** `location /snappymail/` in `location /tachyon/` umbenennen
   und in **beiden** Location-Blöcken den Upstream von
   `chormanager-snappymail-prod` auf `chormanager-webmail-prod` umstellen.
   Ohne diesen Schritt lädt das Webmail nach dem Deploy ohne CSS/JS.
3. **Stack neu deployen.** Das bestehende Volume wird unverändert
   weiterverwendet, nur unter dem neuen Mountpfad `/var/lib/tachyon` — es ist
   kein Datenexport und keine Volume-Migration nötig. Admin-Passwort,
   Domain-Konfigurationen und Benutzereinstellungen bleiben erhalten.
```

- [ ] **Step 6: Tests laufen lassen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add .env.example README.md dist/README.md tests/Feature/WebmailFeatureFlagTest.php
git commit -m "docs(webmail): Doku auf Tachyon umstellen inkl. Betreiber-Migration"
```

---

### Task 7: Gesamtverifikation

**Files:** keine Änderung außer eventuell nötigen Formatierungsfixes.

**Interfaces:**
- Consumes: alle vorherigen Tasks.
- Produces: nachgewiesen grüner Stand.

- [ ] **Step 1: Restbestände suchen**

Run: `ddev exec grep -rni "snappymail" --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git --exclude-dir=docs .`
Expected: Treffer ausschließlich in
- `dist/docker-compose.prod.yml` (Volume-Key `snappymail_data` + erklärender Kommentar),
- `dist/README.md` (Legacy-Volume-Hinweis, Migrationsabschnitt, „fork of the discontinued SnappyMail"),
- `README.md` (historischer Spec-Verweis),
- `.ddev/webmail-plugins/…` und `dist/webmail/…` nur dort, wo Tachyons Herkunft erklärt wird.

Jeder andere Treffer ist ein Migrationsrest und muss behoben werden.

- [ ] **Step 2: Linter laufen lassen**

Run: `ddev composer phpcs`
Expected: keine Fehler. Bei Verstößen `ddev composer phpcbf` ausführen und erneut prüfen.

Run: `ddev composer twigcs`
Expected: keine Fehler (an Templates wurde nichts geändert; der Lauf dient als Absicherung).

- [ ] **Step 3: Vollständige Testsuite**

Run: `ddev exec ./vendor/bin/phpunit`
Expected: PASS, keine Fehler und keine Risky-Tests durch die Migration.

- [ ] **Step 4: Laufzeitprüfung im Container**

```bash
ddev restart
ddev logs -s webmail | tail -20
```

Expected: `[INFO] Tachyon version: …` und `[chormanager-enable-plugin] Plugin 'chormanager-sso' enabled …` in der Ausgabe.

```bash
ddev exec curl -s -o /dev/null -w '%{http_code}\n' http://webmail:8888/
ddev exec curl -s -o /dev/null -w '%{http_code}\n' https://chormanager.ddev.site/webmail/
```

Expected: jeweils `200`.

Asset-Pfad prüfen (Version aus dem Container ermitteln und eine Datei abrufen):

```bash
ddev exec -s webmail ls /tachyon/tachyon/v
ddev exec curl -s -o /dev/null -w '%{http_code}\n' "https://chormanager.ddev.site/tachyon/v/<version>/static/css/app.css"
```

Expected: `200` — belegt, dass die neue `/tachyon/`-Location greift.

- [ ] **Step 5: SSO-Login prüfen**

Im Browser am lokalen ChorManager anmelden, im Profil einen Mailbox-Zugang hinterlegen und das Mail-Badge anklicken. Erwartet: Inbox ohne zweiten Login-Dialog. Bei Fehlschlag `ddev logs -s webmail` prüfen — das Plugin loggt `chormanager_sso.*`-Events mit `LOG_WARNING`.

Dieser Schritt ist manuell; automatisierte Browser-Durchläufe nur auf ausdrückliche Anforderung.

- [ ] **Step 6: Abschluss-Commit (nur falls Schritt 2 Formatierungsfixes erzeugt hat)**

```bash
git add -A
git commit -m "style(webmail): Formatierung nach Tachyon-Migration"
```

- [ ] **Step 7: Ergebnis berichten**

Berichten: geänderte Dateien, ausgeführte Kommandos, Testergebnisse, und ausdrücklich die zwei manuellen Betreiber-Schritte für Prod (Portainer-Env, SWAG-Config). Kein `git push`.
