# Office-Dokumente im Browser bearbeiten (Collabora) – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Office-Dateien der Dateiablage lassen sich über einen angebundenen Collabora-Online-Server im Browser ansehen und bearbeiten; jede Bearbeitungssitzung ergibt genau eine neue Version.

**Architecture:** ChorManager wird WOPI-Host: drei öffentliche Endpunkte (`CheckFileInfo`, `GetFile`, `PutFile`) unter `/wopi/files/{id}`, abgesichert über kurzlebige, nur als Hash gespeicherte Zugangstokens, die bei jedem Aufruf die aktuellen Rechte prüfen. Die Editor-Seite `/files/{id}/edit` stellt das Token aus und schickt es per Formular-POST in ein iframe zu Collabora. Gespeichert wird über `FileService`, damit Kontingent, Typprüfung und Versionierung an einer Stelle bleiben.

**Tech Stack:** PHP 8.5, Slim 4, PHP-DI, Eloquent (illuminate/database), Phinx, Twig, PHPUnit, `node --test` für reines JS, Collabora Online (`collabora/code`) im optionalen DDEV-Container.

**Spec:** `docs/superpowers/specs/2026-10-04-collabora-office-editing-design.md`

## Global Constraints

- Bezeichner englisch, Inhalte (UI-Texte, Kommentare, Testbeschreibungen, Commit-Nachrichten) deutsch mit echten Umlauten (`instructions/naming.md`).
- PSR-12, Zeilenlänge höchstens 130 (`phpcs.xml`); Twig: doppelte Anführungszeichen, kein Inline-JS, kein Inline-CSS.
- Dateien mit LF-Zeilenenden; nach jedem Schreiben auf Windows normalisieren (`instructions/line-endings.md`).
- Logs: `LoggerInterface`, stabiler `event`-Schlüssel, Kennungen statt Dateinamen; das Zugangstoken erscheint **nie** in einem Log.
- Schemaänderungen nur per Phinx-Migration; vor Task 2 den Skill `/phinx-migration` laden.
- Token: 32 Zufallsbytes, URL-sicher kodiert, nur SHA-256 gespeichert, TTL 10 Stunden (`36000` s).
- Sitzungsregel: aktuelle Version mit `office_session_open = true` und `office_saved_at` jünger als **60 Minuten** wird ersetzt, sonst entsteht eine neue Version.
- Konflikt: abweichender `X-COOL-WOPI-Timestamp` → `409` mit `{"COOLStatusCode":1010}`.
- CSP-Lockerung nur auf `#^/files/\d+/edit$#` und nur für den Ursprung von `OFFICE_SERVER_URL`.
- Kein `git push`. Kein Hilfethema.

### Befehle im Worktree

`ddev` lässt sich im Worktree nicht aufrufen (es sähe ein zweites Projekt gleichen Namens). Alle PHP-, Composer- und Node-Befehle laufen deshalb direkt im Web-Container. Im Plan steht dafür `WT`:

```bash
# WT <befehl>  steht für:
MSYS_NO_PATHCONV=1 docker exec -e TEST_TOKEN=9 \
  -w /var/www/html/.claude/worktrees/collabora-office-editing ddev-ChorManager-web <befehl>
```

`TEST_TOKEN=9` gibt gefilterten Läufen die eigene Datenbank `db_test_9`, damit sie einer parallel arbeitenden Sitzung im Hauptverzeichnis nicht in die Quere kommen. Gefilterte Läufe kürzen ihre Ausgabe mit `| tail -8`.

## Review Focus

1. **Office-Server nicht erreichbar** – Ordner- und Detailseiten rendern trotzdem, nur ohne Bearbeiten-Knöpfe; ein Fehlschlag wird eine Minute lang zwischengespeichert, damit nicht jede Seite drei Sekunden auf den Timeout wartet. Test: Task 5 `testPagesRenderWithoutButtonsWhenOfficeServerIsDown`, Task 1 `testFailureIsRetriedAfterOneMinute`.
2. **Leerer PutFile-Rumpf** – ein Speichern mit 0 Bytes würde das Dokument leeren; es wird mit 400 abgewiesen, ohne Version. Test: Task 3 `testEmptyContentIsRejected`, Task 4 `testEmptyBodyIsRejected`.
3. **Umbenennen während einer Sitzung** – Speichern geht unter dem neuen Namen weiter. Test: Task 4 `testRenameDuringSessionKeepsSaving`.
4. **Rechte mitten in der Sitzung entzogen** – der nächste Aufruf liefert 404, nichts wird gespeichert. Test: Task 4 `testRevokedShareAndTrashedFileEndAccess` (PutFile-Teil).
5. **Dateiendungen in Großbuchstaben** (`PROTOKOLL.ODT`) – werden wie kleingeschriebene erkannt. Test: Task 1 `testParsesEditAndViewActionsAndRebasesOntoServerUrl`.

---

### Task 1: Konfiguration und Discovery

**Files:**
- Modify: `src/Settings.php` (Abschnitt `modules` und neuer Abschnitt `office`)
- Create: `src/Services/Office/OfficeSettings.php`
- Create: `src/Services/Office/OfficeAction.php`
- Create: `src/Services/Office/OfficeDiscovery.php`
- Modify: `src/Dependencies.php` (Einträge für `OfficeSettings`, `OfficeDiscovery`)
- Create: `tests/Fixtures/office/discovery.xml`
- Create: `tests/Feature/OfficeFixtures.php`
- Test: `tests/Feature/OfficeDiscoveryFeatureTest.php`

**Interfaces:**
- Produces:
  - `OfficeSettings::__construct(string $serverUrl, string $internalUrl, string $wopiBaseUrl, string $appUrl)`, öffentliche readonly-Eigenschaften gleichen Namens
  - `OfficeSettings::fromArray(array $office, string $appUrl): self`
  - `OfficeSettings::serverOrigin(): string`, `OfficeSettings::appOrigin(): string`, `OfficeSettings::originOf(string $url): string`
  - `OfficeAction::__construct(string $urlSrc, bool $canEdit)`; `OfficeAction::modeFor(int $level): ?string` (`'edit'|'view'|null`)
  - `OfficeDiscovery::__construct(OfficeSettings $settings, \Closure $fetch, string $cacheFile, LoggerInterface $logger)`
  - `OfficeDiscovery::actionFor(string $fileName): ?OfficeAction`, `OfficeDiscovery::isAvailable(): bool`, `OfficeDiscovery::httpFetcher(): \Closure`
  - Konstanten `OfficeDiscovery::CACHE_SECONDS = 3600`, `OfficeDiscovery::FAILURE_CACHE_SECONDS = 60`
  - Settings: `settings['modules']['office']` (bool), `settings['office']` mit `server_url`, `internal_url`, `wopi_base_url`, `discovery_cache`
  - Test-Trait `Tests\Feature\OfficeFixtures` mit `officeSettings()`, `discoveryXml()`, `discovery(?\Closure $fetch = null, ?LoggerInterface $logger = null, bool $separateCache = false)`, `tearDownOfficeFixtures()`

- [ ] **Step 1: Discovery-Fixture anlegen**

`tests/Fixtures/office/discovery.xml`:

```xml
<?xml version="1.0" encoding="utf-8"?>
<wopi-discovery>
  <net-zone name="external-http">
    <app name="writer">
      <action default="true" ext="odt" name="edit" urlsrc="http://collabora:9980/browser/abc123/cool.html?"/>
      <action default="true" ext="docx" name="edit" urlsrc="http://collabora:9980/browser/abc123/cool.html?"/>
    </app>
    <app name="calc">
      <action default="true" ext="xlsx" name="view" urlsrc="http://collabora:9980/browser/abc123/cool.html?"/>
      <action default="true" ext="xlsx" name="edit" urlsrc="http://collabora:9980/browser/abc123/cool.html?"/>
      <action default="true" ext="ods" name="edit" urlsrc="http://collabora:9980/browser/abc123/cool.html?&lt;ui=UI_LLCC&amp;&gt;"/>
    </app>
    <app name="draw">
      <action default="true" ext="pdf" name="view" urlsrc="http://collabora:9980/browser/abc123/cool.html?"/>
    </app>
    <app name="application/vnd.oasis.opendocument.text">
      <action default="true" ext="" name="edit" urlsrc="http://collabora:9980/browser/abc123/cool.html?"/>
    </app>
  </net-zone>
</wopi-discovery>
```

- [ ] **Step 2: Test-Trait `OfficeFixtures` anlegen**

`tests/Feature/OfficeFixtures.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeSettings;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Gemeinsame Bausteine der Office-Tests: feste Adressen, eine Discovery aus der
 * Fixture statt aus einem echten Collabora und eine eigene Cache-Datei je Test.
 */
trait OfficeFixtures
{
    protected string $discoveryCacheFile = '';

    /** @var list<string> */
    protected array $extraDiscoveryCacheFiles = [];

    protected function officeSettings(): OfficeSettings
    {
        return new OfficeSettings(
            'https://office.example.test',
            'http://collabora:9980',
            'http://web',
            'https://chor.example.test'
        );
    }

    protected function discoveryXml(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/Fixtures/office/discovery.xml');
    }

    /**
     * Alle Instanzen eines Tests teilen sich eine Cache-Datei - so wie im Betrieb.
     * `$separateCache` gibt einer Instanz eine eigene, etwa für einen ausgefallenen
     * Server neben einem erreichbaren.
     *
     * @param (\Closure(string): ?string)|null $fetch
     */
    protected function discovery(
        ?\Closure $fetch = null,
        ?LoggerInterface $logger = null,
        bool $separateCache = false
    ): OfficeDiscovery {
        if ($this->discoveryCacheFile === '') {
            $this->discoveryCacheFile = self::newDiscoveryCachePath();
        }
        $cacheFile = $this->discoveryCacheFile;
        if ($separateCache) {
            $cacheFile = self::newDiscoveryCachePath();
            $this->extraDiscoveryCacheFiles[] = $cacheFile;
        }

        return new OfficeDiscovery(
            $this->officeSettings(),
            $fetch ?? fn (string $url): ?string => $this->discoveryXml(),
            $cacheFile,
            $logger ?? new NullLogger()
        );
    }

    protected function tearDownOfficeFixtures(): void
    {
        foreach ([$this->discoveryCacheFile, ...$this->extraDiscoveryCacheFiles] as $file) {
            if ($file !== '' && is_file($file)) {
                unlink($file);
            }
        }
        $this->discoveryCacheFile = '';
        $this->extraDiscoveryCacheFiles = [];
        Carbon::setTestNow();
    }

    private static function newDiscoveryCachePath(): string
    {
        return sys_get_temp_dir() . '/office-discovery-' . bin2hex(random_bytes(6)) . '.json';
    }
}
```

- [ ] **Step 3: Failing Tests schreiben**

`tests/Feature/OfficeDiscoveryFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolderShare as Share;
use App\Services\Office\OfficeAction;
use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeSettings;
use Carbon\Carbon;
use DI\ContainerBuilder;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Welche Dateien Collabora öffnen kann, steht in seiner Discovery. Getestet wird
 * gegen eine feste Fixture; ein echter Server ist nicht nötig.
 */
class OfficeDiscoveryFeatureTest extends TestCase
{
    use OfficeFixtures;

    /** @var array<string, string|false> */
    private array $previousEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->previousEnv = [];
        $this->tearDownOfficeFixtures();
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->previousEnv)) {
            $this->previousEnv[$key] = getenv($key);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);

        return $builder->build()->get('settings');
    }

    public function testParsesEditAndViewActionsAndRebasesOntoServerUrl(): void
    {
        $discovery = $this->discovery();

        $odt = $discovery->actionFor('PROTOKOLL.ODT');
        $this->assertNotNull($odt, 'Endungen in Großbuchstaben zählen wie kleine.');
        $this->assertTrue($odt->canEdit);
        $this->assertSame('https://office.example.test/browser/abc123/cool.html?', $odt->urlSrc);

        $pdf = $discovery->actionFor('Partitur.pdf');
        $this->assertNotNull($pdf);
        $this->assertFalse($pdf->canEdit);

        $this->assertNull($discovery->actionFor('Einsingen.mp3'));
        $this->assertNull($discovery->actionFor('ohne-endung'));
        $this->assertTrue($discovery->isAvailable());
    }

    public function testEditWinsOverViewForTheSameExtension(): void
    {
        $this->assertTrue($this->discovery()->actionFor('Kassa.xlsx')?->canEdit);
    }

    public function testPlaceholdersInUrlSrcAreRemoved(): void
    {
        $this->assertSame(
            'https://office.example.test/browser/abc123/cool.html?',
            $this->discovery()->actionFor('Liste.ods')?->urlSrc
        );
    }

    public function testFetchesFromInternalUrl(): void
    {
        $requested = [];
        $this->discovery(function (string $url) use (&$requested): ?string {
            $requested[] = $url;

            return $this->discoveryXml();
        })->isAvailable();

        $this->assertSame(['http://collabora:9980/hosting/discovery'], $requested);
    }

    public function testUnreachableServerMeansNothingIsAvailableAndIsLogged(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $discovery = $this->discovery(static fn (string $url): ?string => null, $logger);

        $this->assertFalse($discovery->isAvailable());
        $this->assertNull($discovery->actionFor('Protokoll.odt'));
        $this->assertTrue($handler->hasWarningThatPasses(
            static fn ($record): bool => ($record->context['event'] ?? '') === 'office.discovery_failed'
        ));
    }

    public function testBrokenXmlCountsAsFailure(): void
    {
        $this->assertFalse($this->discovery(static fn (string $url): ?string => '<wopi-discovery><net-zone')->isAvailable());
    }

    public function testSuccessfulResultIsCachedAcrossInstances(): void
    {
        $calls = 0;
        $fetch = function (string $url) use (&$calls): ?string {
            $calls++;

            return $this->discoveryXml();
        };

        $this->discovery($fetch)->isAvailable();
        $this->discovery($fetch)->isAvailable();

        $this->assertSame(1, $calls);
    }

    public function testFailureIsRetriedAfterOneMinute(): void
    {
        Carbon::setTestNow('2026-10-04 10:00:00');
        $calls = 0;
        $fetch = function (string $url) use (&$calls): ?string {
            $calls++;

            return null;
        };

        $this->discovery($fetch)->isAvailable();
        $this->discovery($fetch)->isAvailable();
        $this->assertSame(1, $calls, 'Innerhalb einer Minute kein neuer Versuch.');

        Carbon::setTestNow(Carbon::now()->addSeconds(OfficeDiscovery::FAILURE_CACHE_SECONDS + 1));
        $this->discovery($fetch)->isAvailable();
        $this->assertSame(2, $calls);
    }

    public function testModeDependsOnLevelAndAction(): void
    {
        $edit = new OfficeAction('https://office.example.test/x?', true);
        $view = new OfficeAction('https://office.example.test/x?', false);

        $this->assertSame('edit', $edit->modeFor(Share::LEVEL_EDIT));
        $this->assertSame('edit', $edit->modeFor(Share::LEVEL_MANAGE));
        $this->assertSame('view', $edit->modeFor(Share::LEVEL_UPLOAD));
        $this->assertSame('view', $edit->modeFor(Share::LEVEL_READ));
        $this->assertSame('view', $view->modeFor(Share::LEVEL_MANAGE));
        $this->assertNull($edit->modeFor(Share::LEVEL_NONE));
    }

    public function testSettingsFallBackToServerAndAppUrl(): void
    {
        $settings = OfficeSettings::fromArray(
            ['server_url' => 'https://office.example.test:9980/', 'internal_url' => '', 'wopi_base_url' => ''],
            'https://chor.example.test/'
        );

        $this->assertSame('https://office.example.test:9980', $settings->serverUrl);
        $this->assertSame('https://office.example.test:9980', $settings->internalUrl);
        $this->assertSame('https://chor.example.test', $settings->wopiBaseUrl);
        $this->assertSame('https://office.example.test:9980', $settings->serverOrigin());
        $this->assertSame('https://chor.example.test', $settings->appOrigin());
    }

    public function testModuleNeedsFilesAndServerUrl(): void
    {
        $this->setEnv('FEATURE_OFFICE', 'true');
        $this->setEnv('FEATURE_FILES', 'true');
        $this->setEnv('OFFICE_SERVER_URL', 'https://office.example.test');
        $this->assertTrue($this->settings()['modules']['office']);

        $this->setEnv('FEATURE_FILES', 'false');
        $this->assertFalse($this->settings()['modules']['office'], 'Ohne Dateiablage nichts zu bearbeiten.');

        $this->setEnv('FEATURE_FILES', 'true');
        $this->setEnv('OFFICE_SERVER_URL', '');
        $this->assertFalse($this->settings()['modules']['office'], 'Ohne Server-Adresse kein Editor.');
    }
}
```

- [ ] **Step 4: Tests laufen lassen, Fehlschlag prüfen**

Run: `WT php vendor/bin/phpunit --filter OfficeDiscoveryFeatureTest | tail -8`
Expected: FAIL, `Class "App\Services\Office\OfficeSettings" not found` (bzw. `OfficeAction`).

- [ ] **Step 5: `OfficeSettings` und `OfficeAction` schreiben**

`src/Services/Office/OfficeSettings.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Office;

/**
 * Die drei Adressen der Office-Anbindung. Browser, ChorManager und Collabora
 * erreichen einander je nach Betrieb unter verschiedenen Namen - in DDEV etwa
 * spricht Collabora ChorManager als `http://web` an, der Browser Collabora aber
 * über den Router.
 */
final class OfficeSettings
{
    public function __construct(
        public readonly string $serverUrl,
        public readonly string $internalUrl,
        public readonly string $wopiBaseUrl,
        public readonly string $appUrl
    ) {
    }

    /**
     * @param array<string, mixed> $office settings['office']
     */
    public static function fromArray(array $office, string $appUrl): self
    {
        $server = rtrim(trim((string) ($office['server_url'] ?? '')), '/');
        $internal = rtrim(trim((string) ($office['internal_url'] ?? '')), '/');
        $wopi = rtrim(trim((string) ($office['wopi_base_url'] ?? '')), '/');
        $app = rtrim(trim($appUrl), '/');

        return new self($server, $internal !== '' ? $internal : $server, $wopi !== '' ? $wopi : $app, $app);
    }

    /** Ursprung des Office-Servers - für CSP und die Prüfung von PostMessages. */
    public function serverOrigin(): string
    {
        return self::originOf($this->serverUrl);
    }

    /** Ursprung von ChorManager im Browser - Collabora schickt PostMessages nur dorthin. */
    public function appOrigin(): string
    {
        return self::originOf($this->appUrl);
    }

    public static function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
```

`src/Services/Office/OfficeAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Models\FileFolderShare;

/**
 * Was Collabora mit einer Dateiendung anfangen kann: die Editor-Adresse und ob
 * sich die Endung bearbeiten oder nur ansehen lässt.
 */
final class OfficeAction
{
    public function __construct(
        public readonly string $urlSrc,
        public readonly bool $canEdit
    ) {
    }

    /**
     * Bearbeiten nur mit Stufe "Bearbeiten" und einer bearbeitbaren Endung,
     * Ansehen ab Stufe "Lesen", sonst nichts.
     */
    public function modeFor(int $level): ?string
    {
        if ($level < FileFolderShare::LEVEL_READ) {
            return null;
        }

        return $this->canEdit && $level >= FileFolderShare::LEVEL_EDIT ? 'edit' : 'view';
    }
}
```

- [ ] **Step 6: `OfficeDiscovery` schreiben**

`src/Services/Office/OfficeDiscovery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Office;

use Carbon\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Liest die WOPI-Discovery von Collabora: welche Endung sich mit welcher
 * Editor-Adresse bearbeiten oder ansehen lässt.
 *
 * Das Ergebnis liegt in einer Cache-Datei - eine Stunde, ein Fehlschlag nur eine
 * Minute. Sonst wartete bei ausgefallenem Server jede Ordnerseite auf den Timeout.
 * Die Editor-Adresse bekommt den Ursprung aus OFFICE_SERVER_URL: Collabora kennt
 * sich selbst oft nur unter seinem internen Namen.
 */
final class OfficeDiscovery
{
    public const CACHE_SECONDS = 3600;
    public const FAILURE_CACHE_SECONDS = 60;

    /** @var array<string, array{url: string, edit: bool}>|null */
    private ?array $actions = null;

    /**
     * @param \Closure(string): ?string $fetch liefert den Rumpf oder null bei Fehlern
     */
    public function __construct(
        private readonly OfficeSettings $settings,
        private readonly \Closure $fetch,
        private readonly string $cacheFile,
        private readonly LoggerInterface $logger
    ) {
    }

    /** HTTP-Abruf mit kurzem Timeout - im Betrieb der Standard. */
    public static function httpFetcher(): \Closure
    {
        return static function (string $url): ?string {
            $context = stream_context_create(['http' => ['timeout' => 3]]);
            $body = @file_get_contents($url, false, $context);

            return is_string($body) && $body !== '' ? $body : null;
        };
    }

    public function actionFor(string $fileName): ?OfficeAction
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $entry = $extension === '' ? null : ($this->actions()[$extension] ?? null);

        return $entry === null ? null : new OfficeAction($entry['url'], $entry['edit']);
    }

    public function isAvailable(): bool
    {
        return $this->actions() !== [];
    }

    /**
     * @return array<string, array{url: string, edit: bool}>|null null bei unlesbarem XML
     */
    public static function parse(string $xml, string $serverUrl): ?array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($document === false) {
            return null;
        }

        $actions = [];
        foreach ($document->xpath('//action') ?: [] as $action) {
            $extension = strtolower((string) $action['ext']);
            $name = (string) $action['name'];
            $urlSrc = (string) $action['urlsrc'];
            if ($extension === '' || $urlSrc === '' || !in_array($name, ['edit', 'view'], true)) {
                continue;
            }
            $isEdit = $name === 'edit';
            if (!isset($actions[$extension]) || ($isEdit && !$actions[$extension]['edit'])) {
                $actions[$extension] = ['url' => self::rebase($urlSrc, $serverUrl), 'edit' => $isEdit];
            }
        }

        return $actions;
    }

    /**
     * Pfad und Query aus der Discovery, Ursprung aus OFFICE_SERVER_URL. Platzhalter
     * wie `<ui=UI_LLCC&>` fallen weg; das Ergebnis endet auf `?` oder `&`, damit
     * `WOPISrc=...` direkt angehängt werden kann.
     */
    private static function rebase(string $urlSrc, string $serverUrl): string
    {
        $parts = parse_url($urlSrc);
        $path = (string) ($parts['path'] ?? '/');
        $query = (string) preg_replace('/<[^>]*>/', '', (string) ($parts['query'] ?? ''));
        $suffix = $query === '' || str_ends_with($query, '&') ? $query : $query . '&';

        return $serverUrl . $path . '?' . $suffix;
    }

    /**
     * @return array<string, array{url: string, edit: bool}>
     */
    private function actions(): array
    {
        if ($this->actions !== null) {
            return $this->actions;
        }

        $cached = $this->readCache();
        if ($cached !== null) {
            return $this->actions = $cached;
        }

        $xml = ($this->fetch)($this->settings->internalUrl . '/hosting/discovery');
        $actions = $xml === null ? null : self::parse($xml, $this->settings->serverUrl);
        if ($actions === null || $actions === []) {
            $this->logger->warning('Office discovery failed.', [
                'event' => 'office.discovery_failed',
                'reason' => $xml === null ? 'unreachable' : 'unreadable',
            ]);
            $actions = [];
        }

        $this->writeCache($actions);

        return $this->actions = $actions;
    }

    /**
     * @return array<string, array{url: string, edit: bool}>|null
     */
    private function readCache(): ?array
    {
        $raw = is_file($this->cacheFile) ? @file_get_contents($this->cacheFile) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $usable = is_array($data) && is_array($data['actions'] ?? null)
            && ($data['server'] ?? '') === $this->settings->serverUrl;
        if (!$usable) {
            return null;
        }

        $ttl = $data['actions'] === [] ? self::FAILURE_CACHE_SECONDS : self::CACHE_SECONDS;

        return Carbon::now()->getTimestamp() - (int) ($data['fetched_at'] ?? 0) < $ttl ? $data['actions'] : null;
    }

    /**
     * @param array<string, array{url: string, edit: bool}> $actions
     */
    private function writeCache(array $actions): void
    {
        $directory = dirname($this->cacheFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        @file_put_contents($this->cacheFile, (string) json_encode([
            'server' => $this->settings->serverUrl,
            'fetched_at' => Carbon::now()->getTimestamp(),
            'actions' => $actions,
        ]), LOCK_EX);
    }
}
```

- [ ] **Step 7: Settings und Container**

In `src/Settings.php` im Array `modules` nach `'files' => ...` ergänzen:

```php
                // Office-Dokumente im Browser (Collabora, WOPI). Ohne Dateiablage
                // gibt es nichts zu bearbeiten, ohne Server-Adresse keinen Editor.
                'office'        => EnvHelper::read('FEATURE_OFFICE', 'false') === 'true'
                    && EnvHelper::read('FEATURE_FILES', 'false') === 'true'
                    && trim((string) EnvHelper::read('OFFICE_SERVER_URL', '')) !== '',
```

und nach dem Abschnitt `'files' => [...]` einen neuen Abschnitt:

```php
            // Office-Anbindung: drei Adressen, weil Browser, ChorManager und
            // Collabora einander je nach Betrieb unterschiedlich erreichen.
            'office' => [
                'server_url' => EnvHelper::read('OFFICE_SERVER_URL', ''),
                'internal_url' => EnvHelper::read('OFFICE_SERVER_INTERNAL_URL', ''),
                'wopi_base_url' => EnvHelper::read('OFFICE_WOPI_BASE_URL', ''),
                'discovery_cache' => __DIR__ . '/../var/cache/office-discovery.json',
            ],
```

Prüfen, dass `var/cache/` von `.gitignore` erfasst ist (`git check-ignore -v var/cache/office-discovery.json`); falls nicht, `var/cache/` in `.gitignore` ergänzen.

In `src/Dependencies.php` die `use`-Liste um `App\Services\Office\OfficeDiscovery` und `App\Services\Office\OfficeSettings` ergänzen (`App\Util\AppUrlResolver` ist dort bereits importiert – sonst ebenfalls) und nach dem Eintrag `PurgeFileTrashCommand::class` einfügen:

```php
        // Office-Anbindung. Die App-Adresse kommt aus APP_URL bzw. DDEV - nie aus
        // dem Host-Kopf einer Anfrage, die Collabora schickt.
        OfficeSettings::class => function (ContainerInterface $c): OfficeSettings {
            return OfficeSettings::fromArray(
                $c->get('settings')['office'],
                (string) (AppUrlResolver::configuredBaseUrl() ?? '')
            );
        },
        OfficeDiscovery::class => function (ContainerInterface $c): OfficeDiscovery {
            return new OfficeDiscovery(
                $c->get(OfficeSettings::class),
                OfficeDiscovery::httpFetcher(),
                $c->get('settings')['office']['discovery_cache'],
                $c->get(LoggerInterface::class)
            );
        },
```

- [ ] **Step 8: Tests laufen lassen**

Run: `WT php vendor/bin/phpunit --filter OfficeDiscoveryFeatureTest | tail -8`
Expected: PASS (11 Tests).

- [ ] **Step 9: LF normalisieren und committen**

```bash
git add src/Settings.php src/Dependencies.php src/Services/Office tests/Fixtures/office tests/Feature/OfficeFixtures.php tests/Feature/OfficeDiscoveryFeatureTest.php .gitignore
git commit -m "feat(office): Discovery von Collabora lesen und Office-Einstellungen

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Datenmodell, Zugangstokens, Nutzer ohne Sitzung

**Files:**
- Create: `db/migrations/20261004090000_create_office_editing_tables.php`
- Create: `src/Models/OfficeAccessToken.php`
- Modify: `src/Models/FileVersion.php` (fillable, casts)
- Create: `src/Services/Office/IssuedOfficeToken.php`
- Create: `src/Services/Office/OfficeTokenService.php`
- Modify: `src/Services/Files/FileActor.php` (neue Fabrik `forUser`)
- Modify: `src/Services/DevSeedService.php` (Tabelle in `resetSeedData`)
- Test: `tests/Feature/OfficeTokenServiceFeatureTest.php`

**Interfaces:**
- Consumes: nichts aus Task 1.
- Produces:
  - Tabelle `office_access_tokens` (`id`, `token_hash` char(64) unique, `file_id` unsigned FK, `user_id` FK, `expires_at`, `created_at`)
  - Spalten `file_versions.office_session_open` (bool, Standard false), `file_versions.office_saved_at` (datetime, null)
  - `OfficeAccessToken` (Eloquent, `$timestamps = false`)
  - `FileVersion`: `office_session_open` als `boolean`, `office_saved_at` als `datetime` gecastet und fillable
  - `IssuedOfficeToken::__construct(string $plain, CarbonImmutable $expiresAt)`; `IssuedOfficeToken::ttlMilliseconds(): int`
  - `OfficeTokenService::__construct(LoggerInterface $logger)`; `issue(int $userId, int $fileId): IssuedOfficeToken`; `resolve(string $plain, int $fileId): ?OfficeAccessToken`; Konstante `TTL_SECONDS = 36000`
  - `FileActor::forUser(User $user): ?FileActor` (null bei `is_active = 0`)

- [ ] **Step 1: Skill `/phinx-migration` laden** und seinen Ablauf für die folgende Migration einhalten.

- [ ] **Step 2: Failing Tests schreiben**

`tests/Feature/OfficeTokenServiceFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OfficeAccessToken;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileActor;
use App\Services\Office\OfficeTokenService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Zugangstokens für Collabora: nur als Hash gespeichert, an genau eine Datei
 * gebunden, zehn Stunden gültig.
 */
class OfficeTokenServiceFeatureTest extends TestCase
{
    use FileFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownFileFixtures();
    }

    private function tokens(): OfficeTokenService
    {
        return new OfficeTokenService(new NullLogger());
    }

    /** @return array{0: User, 1: StoredFile} */
    private function userAndFile(): array
    {
        $user = $this->createMember();
        $folder = $this->createFolder('Vorstand ' . bin2hex(random_bytes(3)));
        $file = StoredFile::create([
            'folder_id' => $folder->id,
            'name' => 'Protokoll ' . bin2hex(random_bytes(3)) . '.odt',
            'size' => 1,
            'mime_type' => 'application/vnd.oasis.opendocument.text',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $file];
    }

    public function testIssuedTokenIsStoredOnlyAsHash(): void
    {
        [$user, $file] = $this->userAndFile();

        $issued = $this->tokens()->issue((int) $user->id, (int) $file->id);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $issued->plain);
        $row = OfficeAccessToken::query()->where('file_id', $file->id)->sole();
        $this->assertSame(hash('sha256', $issued->plain), $row->token_hash);
        $this->assertSame((int) $user->id, $row->user_id);
    }

    public function testTokenLivesTenHoursAndTtlIsAbsoluteMilliseconds(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        [$user, $file] = $this->userAndFile();

        $issued = $this->tokens()->issue((int) $user->id, (int) $file->id);

        $this->assertSame(Carbon::parse('2026-10-04 22:00:00')->getTimestamp() * 1000, $issued->ttlMilliseconds());
    }

    public function testResolveChecksTokenFileAndExpiry(): void
    {
        [$user, $file] = $this->userAndFile();
        [, $other] = $this->userAndFile();
        $issued = $this->tokens()->issue((int) $user->id, (int) $file->id);

        $this->assertSame((int) $user->id, $this->tokens()->resolve($issued->plain, (int) $file->id)?->user_id);
        $this->assertNull($this->tokens()->resolve($issued->plain, (int) $other->id), 'Nur für die eigene Datei.');
        $this->assertNull($this->tokens()->resolve('falsch', (int) $file->id));
        $this->assertNull($this->tokens()->resolve('', (int) $file->id));
        $this->assertNull($this->tokens()->resolve(str_repeat('a', 500), (int) $file->id));

        Carbon::setTestNow(Carbon::now()->addSeconds(OfficeTokenService::TTL_SECONDS + 1));
        $this->assertNull($this->tokens()->resolve($issued->plain, (int) $file->id), 'Abgelaufen.');
    }

    public function testIssuingRemovesExpiredTokens(): void
    {
        [$user, $file] = $this->userAndFile();
        Carbon::setTestNow('2026-10-04 08:00:00');
        $this->tokens()->issue((int) $user->id, (int) $file->id);

        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->tokens()->issue((int) $user->id, (int) $file->id);

        $this->assertSame(1, OfficeAccessToken::query()->where('user_id', $user->id)->count());
    }

    public function testActorForUserReflectsActiveFlagAndFileAdminRole(): void
    {
        $member = $this->createMember();
        $this->assertFalse(FileActor::forUser($member)?->isFileAdmin);

        $role = $this->createRoleFor($member);
        $role->can_manage_files = 1;
        $role->save();
        $this->assertTrue(FileActor::forUser($member->fresh())?->isFileAdmin);

        $member->is_active = 0;
        $member->save();
        $this->assertNull(FileActor::forUser($member->fresh()), 'Deaktiviert heißt: kein Zugriff.');
    }
}
```

- [ ] **Step 3: Tests laufen lassen, Fehlschlag prüfen**

Run: `WT php vendor/bin/phpunit --filter OfficeTokenServiceFeatureTest | tail -8`
Expected: FAIL, `Class "App\Models\OfficeAccessToken" not found`.

- [ ] **Step 4: Migration schreiben**

`db/migrations/20261004090000_create_office_editing_tables.php`:

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Office-Bearbeitung über Collabora (WOPI).
 *
 * `office_access_tokens`: Zugangstokens, mit denen Collabora Dateien holt und
 * speichert. Gespeichert wird nur der SHA-256-Hash.
 *
 * `file_versions.office_session_open` / `office_saved_at`: Eine Bearbeitungssitzung
 * ergibt eine Version. Solange die Sitzung offen ist, ersetzt jedes Speichern den
 * Inhalt dieser Version (unter neuem Pfad).
 */
final class CreateOfficeEditingTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('office_access_tokens')
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('file_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uniq_office_access_tokens_hash'])
            ->addIndex(['expires_at'])
            ->addForeignKey('file_id', 'files', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_office_access_tokens_file',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_office_access_tokens_user',
            ])
            ->create();

        $this->table('file_versions')
            ->addColumn('office_session_open', 'boolean', ['null' => false, 'default' => false, 'after' => 'uploaded_by'])
            ->addColumn('office_saved_at', 'datetime', ['null' => true, 'after' => 'office_session_open'])
            ->update();
    }
}
```

Die Testdatenbank migriert der nächste Testlauf selbst (`bin/prepare_test_database.php`). Die Entwicklungsdatenbank wird **nicht** aus dem Worktree migriert, sondern erst nach dem Merge (Task 7).

- [ ] **Step 5: Model, Token-Service, FileActor**

`src/Models/OfficeAccessToken.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Zugangstoken, mit dem Collabora eine Datei für eine Person holt und speichert.
 * Der Klartext steht nur in der Editor-Seite, hier liegt sein SHA-256.
 */
class OfficeAccessToken extends Model
{
    protected $table = 'office_access_tokens';

    public $timestamps = false;

    protected $fillable = ['token_hash', 'file_id', 'user_id', 'expires_at', 'created_at'];

    protected $casts = [
        'file_id' => 'integer',
        'user_id' => 'integer',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
```

`src/Models/FileVersion.php`: `'office_session_open'` und `'office_saved_at'` an `$fillable` anhängen und in `$casts` ergänzen:

```php
        'office_session_open' => 'boolean',
        'office_saved_at' => 'datetime',
```

`src/Services/Office/IssuedOfficeToken.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Office;

use Carbon\CarbonImmutable;

/** Ein frisch ausgestelltes Token - der Klartext existiert nur in diesem Objekt. */
final class IssuedOfficeToken
{
    public function __construct(
        public readonly string $plain,
        public readonly CarbonImmutable $expiresAt
    ) {
    }

    /** `access_token_ttl` nach WOPI: Ablaufzeitpunkt in Millisekunden seit 1970. */
    public function ttlMilliseconds(): int
    {
        return $this->expiresAt->getTimestamp() * 1000;
    }
}
```

`src/Services/Office/OfficeTokenService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Models\OfficeAccessToken;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Stellt Zugangstokens für Collabora aus und löst sie wieder auf. Abgelaufene
 * Tokens räumt jedes neue Ausstellen ab - ein eigener Cron-Lauf lohnt dafür nicht.
 */
final class OfficeTokenService
{
    /** Zehn Stunden: ein langer Probenabend am Dokument, aber kein Dauerzugang. */
    public const TTL_SECONDS = 36000;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function issue(int $userId, int $fileId): IssuedOfficeToken
    {
        $now = Carbon::now();
        OfficeAccessToken::query()->where('expires_at', '<=', $now)->delete();

        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $now->copy()->addSeconds(self::TTL_SECONDS);
        OfficeAccessToken::create([
            'token_hash' => hash('sha256', $plain),
            'file_id' => $fileId,
            'user_id' => $userId,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        $this->logger->info('Office access token issued.', [
            'event' => 'office.token_issued',
            'file_id' => $fileId,
            'user_id' => $userId,
        ]);

        return new IssuedOfficeToken($plain, $expiresAt->toImmutable());
    }

    public function resolve(string $plain, int $fileId): ?OfficeAccessToken
    {
        if ($plain === '' || strlen($plain) > 128) {
            return null;
        }

        $token = OfficeAccessToken::query()->where('token_hash', hash('sha256', $plain))->first();
        if ($token === null || $token->file_id !== $fileId || $token->expires_at->lte(Carbon::now())) {
            return null;
        }

        return $token;
    }
}
```

`src/Services/Files/FileActor.php`: `use App\Models\Role;` und `use App\Models\User;` ergänzen, nach `fromSession` einfügen:

```php
    /**
     * Für Aufrufe ohne Sitzung (Collabora): Das Datei-Admin-Recht kommt aus den
     * aktuellen Rollen, nicht aus einem Stand vom Login. Wer deaktiviert ist,
     * bekommt keinen Actor.
     */
    public static function forUser(User $user): ?self
    {
        if (!(bool) $user->is_active) {
            return null;
        }

        $isFileAdmin = $user->roles->contains(
            static fn (Role $role): bool => (bool) ($role->can_manage_files ?? false)
        );

        return new self((int) $user->id, $isFileAdmin);
    }
```

`src/Services/DevSeedService.php`, Methode `resetSeedData()`: in `$tables` vor `'file_favorites'` die Zeile `'office_access_tokens',` einfügen.

- [ ] **Step 6: Tests laufen lassen**

Run: `WT php vendor/bin/phpunit --filter "OfficeTokenServiceFeatureTest|FileServiceFeatureTest" | tail -8`
Expected: PASS.

- [ ] **Step 7: LF normalisieren und committen**

```bash
git add db/migrations/20261004090000_create_office_editing_tables.php src/Models/OfficeAccessToken.php src/Models/FileVersion.php src/Services/Office src/Services/Files/FileActor.php src/Services/DevSeedService.php tests/Feature/OfficeTokenServiceFeatureTest.php
git commit -m "feat(office): Zugangstokens und Sitzungsspalten für Office-Versionen

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Speichern aus dem Editor (`FileService::saveFromOffice`)

**Files:**
- Modify: `src/Services/Files/FileService.php` (`store()` aufteilen, `saveFromOffice()`, `isOpenOfficeSession()`)
- Test: `tests/Feature/FileServiceOfficeSaveFeatureTest.php`

**Interfaces:**
- Consumes: `FileVersion::office_session_open` / `office_saved_at` (Task 2).
- Produces:
  - `FileService::saveFromOffice(FileActor $actor, int $fileId, UploadedFileInterface $upload, bool $endsSession): FileVersion` – wirft `FileManagementException` mit `status` 400 (leer), 403/404 (Rechte), 413 (Größe, Kontingent), 422 (Typ)
  - Konstante `FileService::OFFICE_SESSION_IDLE_MINUTES = 60`

- [ ] **Step 1: Failing Tests schreiben**

`tests/Feature/FileServiceOfficeSaveFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolderShare as Share;
use App\Models\FileVersion;
use App\Models\StoredFile;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileService;
use App\Services\Files\FileStorageRegistry;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * Eine Bearbeitungssitzung im Office-Editor ergibt genau eine Version: das erste
 * Speichern legt sie an, jedes weitere ersetzt ihren Inhalt unter neuem Pfad.
 */
class FileServiceOfficeSaveFeatureTest extends TestCase
{
    use FileFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownFileFixtures();
    }

    private function service(int $maxUpload = 1024 * 1024, int $maxVersions = 10): FileService
    {
        $access = new FileAccessService();
        $quota = new FileQuotaService($access, 0);

        return new FileService(
            $access,
            new FileFolderService($access, $quota, new NullLogger()),
            $quota,
            new FileStorageRegistry($this->storage()),
            new NullLogger(),
            $maxUpload,
            $maxVersions
        );
    }

    private function upload(string $content, string $name = 'Protokoll.odt'): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($content), $name, 'application/octet-stream', strlen($content));
    }

    /** @return array{0: FileActor, 1: StoredFile} */
    private function fileWithLevel(int $level = Share::LEVEL_EDIT, ?int $quota = null, string $content = 'Stand 1'): array
    {
        $member = $this->createMember();
        $root = $this->createFolder('Vorstand ' . bin2hex(random_bytes(3)), null, $quota);
        $this->share($root, 'user', (int) $member->id, $level);
        $file = $this->service()->upload(new FileActor((int) $member->id, true), $root, $this->upload($content))->file;

        return [$this->actor($member), $file];
    }

    private function versionCount(StoredFile $file): int
    {
        return FileVersion::query()->where('file_id', $file->id)->count();
    }

    public function testFirstSaveCreatesVersionAndFurtherSavesReplaceIt(): void
    {
        [$actor, $file] = $this->fileWithLevel();
        $service = $this->service();

        $first = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), false);
        $this->assertSame(2, $first->version_number);
        $this->assertTrue($first->office_session_open);
        $firstPath = (string) $first->storage_path;

        $second = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand BB'), false);

        $this->assertSame((int) $first->id, (int) $second->id, 'Dieselbe Version.');
        $this->assertSame(2, $this->versionCount($file));
        $this->assertSame('Stand BB', $this->storage()->read((string) $second->storage_path));
        $this->assertNotSame($firstPath, $second->storage_path, 'Neuer Pfad statt Überschreiben.');
        $this->assertFalse($this->storage()->exists($firstPath), 'Pfad ohne Version wird gelöscht.');
        $this->assertSame(8, StoredFile::findOrFail($file->id)->size);
        $this->assertSame((int) $second->id, StoredFile::findOrFail($file->id)->current_version_id);
    }

    public function testExitSaveClosesSessionSoNextSaveStartsNewVersion(): void
    {
        [$actor, $file] = $this->fileWithLevel();
        $service = $this->service();

        $closed = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), true);
        $this->assertFalse($closed->office_session_open);

        $next = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand B'), false);
        $this->assertSame(3, $next->version_number);
    }

    public function testSessionIdleForAnHourStartsNewVersion(): void
    {
        [$actor, $file] = $this->fileWithLevel();
        $service = $this->service();

        Carbon::setTestNow('2026-10-04 10:00:00');
        $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), false);
        Carbon::setTestNow('2026-10-04 10:59:00');
        $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand B'), false);
        $this->assertSame(2, $this->versionCount($file), 'Innerhalb einer Stunde dieselbe Version.');

        Carbon::setTestNow('2026-10-04 12:00:00');
        $late = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand C'), false);
        $this->assertSame(3, $late->version_number);
    }

    public function testUploadThroughFileManagerEndsTheOfficeSession(): void
    {
        [$actor, $file] = $this->fileWithLevel();
        $service = $this->service();

        $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), false);
        $service->replace($actor, (int) $file->id, $this->upload('Hochgeladen'));
        $after = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand B'), false);

        $this->assertSame(4, $after->version_number, 'Die hochgeladene Fassung bleibt erhalten.');
    }

    public function testQuotaCountsOnlyGrowthWhenReplacing(): void
    {
        [$actor, $file] = $this->fileWithLevel(Share::LEVEL_EDIT, 30, str_repeat('x', 10));
        $service = $this->service();

        $service->saveFromOffice($actor, (int) $file->id, $this->upload(str_repeat('y', 10)), false);
        // 10 + 15 = 25 passt; zählte die volle Größe, wären es 10 + 10 + 15 = 35.
        $service->saveFromOffice($actor, (int) $file->id, $this->upload(str_repeat('z', 15)), false);
        $this->assertSame(2, $this->versionCount($file));

        try {
            $service->saveFromOffice($actor, (int) $file->id, $this->upload(str_repeat('w', 25)), false);
            $this->fail('Kontingent überschritten muss abgewiesen werden.');
        } catch (FileManagementException $exception) {
            $this->assertSame(413, $exception->status);
        }
    }

    public function testReaderCannotSave(): void
    {
        [$actor, $file] = $this->fileWithLevel(Share::LEVEL_READ);

        try {
            $this->service()->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), false);
            $this->fail('Lesende dürfen nicht speichern.');
        } catch (FileManagementException $exception) {
            $this->assertSame(403, $exception->status);
        }
        $this->assertSame(1, $this->versionCount($file));
    }

    public function testOversizedContentIsRejected(): void
    {
        [$actor, $file] = $this->fileWithLevel();

        try {
            $this->service(maxUpload: 5)->saveFromOffice($actor, (int) $file->id, $this->upload('zu groß'), false);
            $this->fail('Zu große Inhalte müssen abgewiesen werden.');
        } catch (FileManagementException $exception) {
            $this->assertSame(413, $exception->status);
        }
        $this->assertSame(1, $this->versionCount($file));
    }

    public function testEmptyContentIsRejected(): void
    {
        [$actor, $file] = $this->fileWithLevel();

        try {
            $this->service()->saveFromOffice($actor, (int) $file->id, $this->upload(''), false);
            $this->fail('Ein leerer Inhalt würde das Dokument löschen.');
        } catch (FileManagementException $exception) {
            $this->assertSame(400, $exception->status);
        }
        $this->assertSame(1, $this->versionCount($file));
    }
}
```

- [ ] **Step 2: Tests laufen lassen, Fehlschlag prüfen**

Run: `WT php vendor/bin/phpunit --filter FileServiceOfficeSaveFeatureTest | tail -8`
Expected: FAIL, `Call to undefined method App\Services\Files\FileService::saveFromOffice()`.

- [ ] **Step 3: `store()` aufteilen**

In `src/Services/Files/FileService.php` `use Carbon\Carbon;` ergänzen. Den Anfang von `store()` – vom Aufruf `$this->localSource($upload)` bis einschließlich des `finally`-Blocks – in eine neue private Methode auslagern und `store()` so umbauen:

```php
    private function store(
        FileActor $actor,
        FileFolder $folder,
        UploadedFileInterface $upload,
        string $name,
        ?StoredFile $existing
    ): UploadResult {
        ['storage' => $storage, 'attributes' => $attributes] = $this->storeContent(
            $actor,
            $folder,
            $upload,
            static fn (int $size): int => $size
        );

        try {
            $file = DB::connection()->transaction(function () use (
                $actor,
                $folder,
                $existing,
                $name,
                $attributes
            ): StoredFile {
                $file = $existing ?? StoredFile::create([
                    'folder_id' => (int) $folder->id,
                    'name' => $name,
                    'size' => $attributes['size'],
                    'mime_type' => $attributes['mime_type'],
                    'created_by' => $actor->userId,
                    'updated_by' => $actor->userId,
                ]);

                $this->addVersion($file, $actor, $attributes);

                return $file;
            });
        } catch (\Throwable $exception) {
            // Ohne Datenbankzeile gehört die Datei niemandem - gleich wieder weg.
            $storage->delete($attributes['storage_path']);
            throw $exception;
        }

        $this->pruneVersions($file);

        $this->logger->info($existing !== null ? 'File version created.' : 'File uploaded.', [
            'event' => $existing !== null ? 'files.version_created' : 'files.uploaded',
            'file_id' => (int) $file->id,
            'folder_id' => (int) $folder->id,
            'size' => $attributes['size'],
            'user_id' => $actor->userId,
        ]);

        return new UploadResult($file->fresh(['currentVersion']) ?? $file, $existing !== null);
    }

    /**
     * Prüft Größe, Typ und Kontingent und legt den Inhalt unter einem neuen Pfad ab.
     *
     * @param \Closure(int): int $quotaBytes Zuwachs fürs Kontingent bei gegebener Größe
     * @return array{
     *     storage: FileStorage,
     *     attributes: array{storage_driver: string, storage_path: string, size: int, mime_type: string, sha256: string}
     * }
     */
    private function storeContent(
        FileActor $actor,
        FileFolder $folder,
        UploadedFileInterface $upload,
        \Closure $quotaBytes,
        bool $allowEmpty = true
    ): array {
        [$sourcePath, $isTemporary] = $this->localSource($upload);
        try {
            $size = (int) filesize($sourcePath);
            if ($size === 0 && !$allowEmpty) {
                throw new FileManagementException('Die Datei ist leer.', 400);
            }
            if ($size > $this->maxUploadBytes) {
                throw new FileManagementException(sprintf(
                    'Die Datei ist zu groß (höchstens %d MB).',
                    intdiv($this->maxUploadBytes, 1024 * 1024) ?: 1
                ), 413);
            }

            $mimeType = $this->detectMimeType($sourcePath, $upload);
            if (in_array($mimeType, self::BLOCKED_MIME_TYPES, true)) {
                $this->logger->warning('File upload rejected.', [
                    'event' => 'security.upload.rejected',
                    'reason' => 'blocked_mime_type',
                    'mime_type' => $mimeType,
                    'user_id' => $actor->userId,
                ]);
                throw new FileManagementException('Dieser Dateityp ist nicht erlaubt.', 422);
            }

            $this->assertQuota($folder, $quotaBytes($size));

            $sha256 = (string) hash_file('sha256', $sourcePath);
            $storage = $this->storages->default();
            $storagePath = $storage->put($sourcePath);
        } finally {
            if ($isTemporary) {
                @unlink($sourcePath);
            }
        }

        return ['storage' => $storage, 'attributes' => [
            'storage_driver' => $storage->name(),
            'storage_path' => $storagePath,
            'size' => $size,
            'mime_type' => $mimeType,
            'sha256' => $sha256,
        ]];
    }
```

Run: `WT php vendor/bin/phpunit --filter "FileServiceFeatureTest|FileControllerFeatureTest" | tail -8`
Expected: PASS – der Umbau ändert kein Verhalten.

- [ ] **Step 4: `saveFromOffice()` schreiben**

Nach `replace()` einfügen:

```php
    /** Ab so vielen Minuten ohne Speichern beginnt eine Office-Sitzung eine neue Version. */
    public const OFFICE_SESSION_IDLE_MINUTES = 60;

    /**
     * Speichern aus dem Office-Editor. Eine Bearbeitungssitzung ergibt eine Version:
     * Das erste Speichern legt sie an, jedes weitere ersetzt ihren Inhalt - unter
     * neuem Pfad, denn gespeicherte Inhalte sind unveränderlich. `$endsSession`
     * schließt die Sitzung (Collabora: X-COOL-WOPI-IsExitSave).
     *
     * Ob ersetzt wird, entscheidet sich vor der Sperre. Collabora speichert ein
     * Dokument ohnehin nacheinander, weil alle Beteiligten in einer Sitzung arbeiten.
     */
    public function saveFromOffice(
        FileActor $actor,
        int $fileId,
        UploadedFileInterface $upload,
        bool $endsSession
    ): FileVersion {
        $file = $this->findWithFileLevel($actor, $fileId, FileFolderShare::LEVEL_EDIT);
        $current = $file->currentVersion;
        $reuse = $current !== null && self::isOpenOfficeSession($current);
        $replacedBytes = $reuse ? (int) $current->size : 0;

        ['storage' => $storage, 'attributes' => $attributes] = $this->storeContent(
            $actor,
            $file->folder,
            $upload,
            static fn (int $size): int => max(0, $size - $replacedBytes),
            false
        );
        $officeState = ['office_session_open' => !$endsSession, 'office_saved_at' => Carbon::now()];

        try {
            [$version, $replaced] = DB::connection()->transaction(
                function () use ($actor, $file, $current, $reuse, $attributes, $officeState): array {
                    StoredFile::query()->whereKey($file->id)->lockForUpdate()->first();

                    if ($reuse && $current !== null) {
                        $replaced = [
                            'storage_driver' => (string) $current->storage_driver,
                            'storage_path' => (string) $current->storage_path,
                        ];
                        $current->fill($attributes + $officeState + ['uploaded_by' => $actor->userId])->save();
                        $file->size = $attributes['size'];
                        $file->mime_type = $attributes['mime_type'];
                        $file->updated_by = $actor->userId;
                        $file->save();

                        return [$current, $replaced];
                    }

                    $version = $this->addVersion($file, $actor, $attributes);
                    $version->fill($officeState)->save();

                    return [$version, null];
                }
            );
        } catch (\Throwable $exception) {
            $storage->delete($attributes['storage_path']);
            throw $exception;
        }

        if ($replaced !== null) {
            $this->deleteUnreferencedStorage([$replaced]);
        } else {
            $this->pruneVersions($file);
        }

        $this->logger->info('File saved from office editor.', [
            'event' => 'office.file_saved',
            'file_id' => (int) $file->id,
            'version_id' => (int) $version->id,
            'replaced' => $replaced !== null,
            'session_closed' => $endsSession,
            'user_id' => $actor->userId,
        ]);

        return $version->fresh() ?? $version;
    }

    private static function isOpenOfficeSession(FileVersion $version): bool
    {
        return (bool) $version->office_session_open
            && $version->office_saved_at !== null
            && $version->office_saved_at->gt(Carbon::now()->subMinutes(self::OFFICE_SESSION_IDLE_MINUTES));
    }
```

Die Konstante gehört nach PSR-12 zu den übrigen Konstanten oben in der Klasse – dorthin verschieben.

- [ ] **Step 5: Tests laufen lassen**

Run: `WT php vendor/bin/phpunit --filter "FileServiceOfficeSaveFeatureTest|FileServiceFeatureTest|FileControllerFeatureTest|FileBackupFeatureTest" | tail -8`
Expected: PASS.

- [ ] **Step 6: Schärfeprobe** (Memory „Schärfeprobe statt grüner Suite“)

Nacheinander je eine Sabotage einbauen, den Test laufen lassen, Rot bestätigen, Sabotage zurücknehmen:
1. In `isOpenOfficeSession` `subMinutes(...)` durch `subMinutes(100000)` ersetzen → `testSessionIdleForAnHourStartsNewVersion` muss rot werden.
2. `max(0, $size - $replacedBytes)` durch `$size` ersetzen → `testQuotaCountsOnlyGrowthWhenReplacing` rot.
3. `'office_session_open' => !$endsSession` durch `true` ersetzen → `testExitSaveClosesSessionSoNextSaveStartsNewVersion` rot.
4. `$this->deleteUnreferencedStorage([$replaced]);` auskommentieren → `testFirstSaveCreatesVersionAndFurtherSavesReplaceIt` rot.

Run je Sabotage: `WT php vendor/bin/phpunit --filter FileServiceOfficeSaveFeatureTest | tail -8`

- [ ] **Step 7: LF normalisieren und committen**

```bash
git add src/Services/Files/FileService.php tests/Feature/FileServiceOfficeSaveFeatureTest.php
git commit -m "feat(office): Speichern aus dem Editor ergibt eine Version pro Sitzung

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: WOPI-Endpunkte

**Files:**
- Create: `src/Services/Office/WopiAccess.php`
- Create: `src/Services/Office/WopiTimestamp.php`
- Create: `src/Controllers/WopiController.php`
- Modify: `src/Routes.php` (öffentliche WOPI-Routen)
- Modify: `src/Middleware/CsrfMiddleware.php` (`EXEMPT_PREFIXES` um `/wopi`)
- Modify: `src/Dependencies.php` (`OfficeTokenService`, `WopiController` autowire)
- Test: `tests/Feature/WopiControllerFeatureTest.php`
- Test: `tests/Feature/CsrfMiddlewareFeatureTest.php` (neuer Test)

**Interfaces:**
- Consumes: `OfficeTokenService::resolve()`, `OfficeTokenService::TTL_SECONDS`, `FileActor::forUser()` (Task 2); `FileService::saveFromOffice()` (Task 3); `OfficeDiscovery::actionFor()`, `OfficeSettings::appOrigin()` (Task 1); `OfficeFixtures` (Task 1).
- Produces:
  - Routen `GET /wopi/files/{id}`, `GET /wopi/files/{id}/contents`, `POST /wopi/files/{id}/contents` (nur bei `modules.office`)
  - `WopiController::checkFileInfo|getFile|putFile(Request, Response, array $args): Response`
  - `WopiTimestamp::of(?FileVersion $version): string` (ISO 8601 UTC mit Millisekunden, `''` ohne Version)

- [ ] **Step 1: Failing Tests schreiben**

`tests/Feature/WopiControllerFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\WopiController;
use App\Models\FileFolderShare as Share;
use App\Models\FileVersion;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileActor;
use App\Services\Files\FileService;
use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeSettings;
use App\Services\Office\OfficeTokenService;
use Carbon\Carbon;
use DI\Container;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Unit\Bootstrap;

/**
 * Die drei WOPI-Endpunkte gegen den echten Container: Collabora weist sich nur
 * mit dem Token aus, jede Anfrage prüft die Rechte neu.
 */
class WopiControllerFeatureTest extends TestCase
{
    use FileFixtures;
    use OfficeFixtures;
    use TestHttpHelpers;

    private Container $container;
    private TestHandler $logs;
    private ?string $previousStoragePath = null;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $previous = getenv('FILES_STORAGE_PATH');
        $this->previousStoragePath = $previous === false ? null : $previous;
        putenv('FILES_STORAGE_PATH=' . $this->storageDir);
        $_ENV['FILES_STORAGE_PATH'] = $this->storageDir;
        $_SERVER['FILES_STORAGE_PATH'] = $this->storageDir;

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($builder);
        $this->container = $builder->build();
        $this->container->set(Capsule::class, Bootstrap::getCapsule());

        $settings = $this->container->get('settings');
        $settings['modules']['files'] = true;
        $settings['modules']['office'] = true;
        $settings['files']['max_upload_bytes'] = 1024;
        $this->container->set('settings', $settings);

        $this->logs = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($this->logs);
        $this->container->set(LoggerInterface::class, $logger);
        $this->container->set(OfficeSettings::class, $this->officeSettings());
        $this->container->set(OfficeDiscovery::class, $this->discovery());
    }

    protected function tearDown(): void
    {
        if ($this->previousStoragePath === null) {
            putenv('FILES_STORAGE_PATH');
            unset($_ENV['FILES_STORAGE_PATH'], $_SERVER['FILES_STORAGE_PATH']);
        } else {
            putenv('FILES_STORAGE_PATH=' . $this->previousStoragePath);
            $_ENV['FILES_STORAGE_PATH'] = $this->previousStoragePath;
            $_SERVER['FILES_STORAGE_PATH'] = $this->previousStoragePath;
        }
        $this->tearDownOfficeFixtures();
        $this->tearDownFileFixtures();
    }

    private function upload(string $content, string $name): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($content), $name, 'application/octet-stream', strlen($content));
    }

    /** @return array{0: User, 1: StoredFile, 2: string} */
    private function sharedFile(int $level = Share::LEVEL_EDIT, string $name = 'Protokoll.odt'): array
    {
        $member = $this->createMember('Erika');
        $root = $this->createFolder('Vorstand ' . bin2hex(random_bytes(3)));
        $this->share($root, 'user', (int) $member->id, $level);
        $file = $this->container->get(FileService::class)
            ->upload(new FileActor((int) $member->id, true), $root, $this->upload('Stand 1', $name))->file;
        $token = $this->container->get(OfficeTokenService::class)->issue((int) $member->id, (int) $file->id)->plain;

        return [$member, $file, $token];
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(string $action, StoredFile $file, string $token, string $body = '', array $headers = []): ResponseInterface
    {
        $path = '/wopi/files/' . $file->id . ($action === 'checkFileInfo' ? '' : '/contents');
        $method = $action === 'putFile' ? 'POST' : 'GET';
        $request = $this->makeRequest($method, $path, [], ['access_token' => $token], $headers);
        if ($action === 'putFile') {
            $request = $request->withBody((new StreamFactory())->createStream($body));
        }

        return $this->container->get(WopiController::class)->{$action}($request, $this->makeResponse(), ['id' => (string) $file->id]);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function currentContent(StoredFile $file): string
    {
        $version = FileVersion::findOrFail(StoredFile::findOrFail($file->id)->current_version_id);

        return $this->storage()->read((string) $version->storage_path);
    }

    public function testRoutesExistOnlyWithOfficeModule(): void
    {
        foreach ([true, false] as $enabled) {
            $builder = new ContainerBuilder();
            (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
            $container = $builder->build();
            $settings = $container->get('settings');
            $settings['modules']['files'] = true;
            $settings['modules']['office'] = $enabled;
            $container->set('settings', $settings);

            AppFactory::setContainer($container);
            $app = AppFactory::create();
            (require dirname(__DIR__, 2) . '/src/Routes.php')($app);
            $patterns = array_map(
                static fn ($route): string => $route->getPattern(),
                $app->getRouteCollector()->getRoutes()
            );

            $this->assertSame($enabled, in_array('/wopi/files/{id:[0-9]+}', $patterns, true));
            $this->assertSame($enabled, in_array('/wopi/files/{id:[0-9]+}/contents', $patterns, true));
        }
    }

    public function testCheckFileInfoDescribesFileAndWriteRightForEditor(): void
    {
        [$member, $file, $token] = $this->sharedFile();

        $response = $this->call('checkFileInfo', $file, $token);

        $this->assertSame(200, $response->getStatusCode());
        $info = $this->json($response);
        $this->assertSame('Protokoll.odt', $info['BaseFileName']);
        $this->assertSame(7, $info['Size']);
        $this->assertSame((string) $file->current_version_id, $info['Version']);
        $this->assertSame((string) $member->id, $info['UserId']);
        $this->assertStringContainsString('Erika', $info['UserFriendlyName']);
        $this->assertTrue($info['UserCanWrite']);
        $this->assertTrue($info['UserCanNotWriteRelative']);
        $this->assertSame('https://chor.example.test', $info['PostMessageOrigin']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $info['LastModifiedTime']);
    }

    public function testReaderMayReadButNotWrite(): void
    {
        [, $file, $token] = $this->sharedFile(Share::LEVEL_READ);

        $this->assertFalse($this->json($this->call('checkFileInfo', $file, $token))['UserCanWrite']);
        $this->assertSame(403, $this->call('putFile', $file, $token, 'Stand 2')->getStatusCode());
        $this->assertSame('Stand 1', $this->currentContent($file));
    }

    public function testViewOnlyFormatIsNotWritableEvenForEditors(): void
    {
        [, $file, $token] = $this->sharedFile(Share::LEVEL_EDIT, 'Partitur.pdf');

        $this->assertFalse($this->json($this->call('checkFileInfo', $file, $token))['UserCanWrite']);
        $this->assertSame(403, $this->call('putFile', $file, $token, 'Stand 2')->getStatusCode());
    }

    public function testFileAdminWithoutShareMayWrite(): void
    {
        $admin = $this->createMember('Admin');
        $role = $this->createRoleFor($admin);
        $role->can_manage_files = 1;
        $role->save();
        $root = $this->createFolder('Archiv ' . bin2hex(random_bytes(3)));
        $file = $this->container->get(FileService::class)
            ->upload(new FileActor((int) $admin->id, true), $root, $this->upload('Stand 1', 'Liste.odt'))->file;
        $token = $this->container->get(OfficeTokenService::class)->issue((int) $admin->id, (int) $file->id)->plain;

        $this->assertTrue($this->json($this->call('checkFileInfo', $file, $token))['UserCanWrite']);
    }

    public function testInvalidTokensAreRejectedWith401(): void
    {
        [, $file, $token] = $this->sharedFile();
        [, $other] = $this->sharedFile();

        $this->assertSame(401, $this->call('checkFileInfo', $file, '')->getStatusCode());
        $this->assertSame(401, $this->call('checkFileInfo', $file, 'unbekannt')->getStatusCode());
        $this->assertSame(401, $this->call('checkFileInfo', $other, $token)->getStatusCode(), 'Token gilt nur für seine Datei.');

        Carbon::setTestNow(Carbon::now()->addSeconds(OfficeTokenService::TTL_SECONDS + 1));
        $this->assertSame(401, $this->call('checkFileInfo', $file, $token)->getStatusCode());
    }

    public function testDeactivatedUserLosesAccess(): void
    {
        [$member, $file, $token] = $this->sharedFile();
        $member->is_active = 0;
        $member->save();

        $this->assertSame(401, $this->call('checkFileInfo', $file, $token)->getStatusCode());
    }

    public function testRevokedShareAndTrashedFileEndAccess(): void
    {
        [, $file, $token] = $this->sharedFile();
        Share::query()->where('folder_id', $file->folder_id)->delete();

        $this->assertSame(404, $this->call('checkFileInfo', $file, $token)->getStatusCode());
        $this->assertSame(404, $this->call('putFile', $file, $token, 'Stand 2')->getStatusCode());
        $this->assertSame('Stand 1', $this->currentContent($file), 'Nichts gespeichert.');

        [, $trashed, $trashedToken] = $this->sharedFile();
        $trashed->delete();
        $this->assertSame(404, $this->call('getFile', $trashed, $trashedToken)->getStatusCode());
    }

    public function testGetFileStreamsCurrentVersion(): void
    {
        [, $file, $token] = $this->sharedFile();

        $response = $this->call('getFile', $file, $token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Stand 1', (string) $response->getBody());
    }

    public function testPutFileSavesAndAnswersWithNewTimestamp(): void
    {
        [, $file, $token] = $this->sharedFile();

        $response = $this->call('putFile', $file, $token, 'Stand 2', ['X-WOPI-Override' => 'PUT']);

        $this->assertSame(200, $response->getStatusCode());
        $saved = $this->json($response)['LastModifiedTime'];
        $this->assertSame('Stand 2', $this->currentContent($file));
        $this->assertSame($saved, $this->json($this->call('checkFileInfo', $file, $token))['LastModifiedTime']);
        $this->assertSame(2, FileVersion::query()->where('file_id', $file->id)->count());
    }

    public function testExitSaveHeaderClosesSession(): void
    {
        [, $file, $token] = $this->sharedFile();

        $this->call('putFile', $file, $token, 'Stand 2', ['X-COOL-WOPI-IsExitSave' => 'true']);

        $version = FileVersion::findOrFail(StoredFile::findOrFail($file->id)->current_version_id);
        $this->assertFalse($version->office_session_open);
    }

    public function testStaleTimestampIsAConflict(): void
    {
        [$member, $file, $token] = $this->sharedFile();
        $known = $this->json($this->call('checkFileInfo', $file, $token))['LastModifiedTime'];

        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->container->get(FileService::class)
            ->replace(new FileActor((int) $member->id, true), (int) $file->id, $this->upload('Fremd', 'Protokoll.odt'));

        $conflict = $this->call('putFile', $file, $token, 'Meins', ['X-COOL-WOPI-Timestamp' => $known]);
        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertSame(['COOLStatusCode' => 1010], $this->json($conflict));
        $this->assertSame('Fremd', $this->currentContent($file));
        $this->assertTrue($this->logs->hasNoticeThatPasses(
            static fn ($record): bool => ($record->context['event'] ?? '') === 'office.save_conflict'
        ));

        // "Überschreiben" in Collabora: dasselbe Speichern ohne Zeitstempel.
        $this->assertSame(200, $this->call('putFile', $file, $token, 'Meins')->getStatusCode());
        $this->assertSame('Meins', $this->currentContent($file));
    }

    public function testMatchingTimestampSaves(): void
    {
        [, $file, $token] = $this->sharedFile();
        $known = $this->json($this->call('checkFileInfo', $file, $token))['LastModifiedTime'];

        $response = $this->call('putFile', $file, $token, 'Stand 2', ['X-COOL-WOPI-Timestamp' => $known]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testOversizedBodyIs413(): void
    {
        [, $file, $token] = $this->sharedFile();

        $this->assertSame(413, $this->call('putFile', $file, $token, str_repeat('x', 2048))->getStatusCode());
        $this->assertTrue($this->logs->hasNoticeThatPasses(
            static fn ($record): bool => ($record->context['event'] ?? '') === 'office.save_rejected'
        ));
    }

    public function testEmptyBodyIsRejected(): void
    {
        [, $file, $token] = $this->sharedFile();

        $this->assertSame(400, $this->call('putFile', $file, $token, '')->getStatusCode());
        $this->assertSame('Stand 1', $this->currentContent($file));
    }

    public function testRenameDuringSessionKeepsSaving(): void
    {
        [$member, $file, $token] = $this->sharedFile();
        $this->container->get(FileService::class)
            ->renameFile(new FileActor((int) $member->id, true), StoredFile::findOrFail($file->id), 'Protokoll neu.odt');

        $this->assertSame('Protokoll neu.odt', $this->json($this->call('checkFileInfo', $file, $token))['BaseFileName']);
        $this->assertSame(200, $this->call('putFile', $file, $token, 'Stand 2')->getStatusCode());
        $this->assertSame('Stand 2', $this->currentContent($file));
    }

    public function testTokenNeverAppearsInLogs(): void
    {
        [, $file, $token] = $this->sharedFile();
        [, $other] = $this->sharedFile();

        $this->call('checkFileInfo', $file, $token);
        $this->call('getFile', $file, $token);
        $this->call('putFile', $file, $token, 'Stand 2');
        $this->call('putFile', $file, $token, 'Stand 3', ['X-COOL-WOPI-Timestamp' => 'veraltet']);
        $this->call('putFile', $file, $token, str_repeat('x', 2048));
        $this->call('checkFileInfo', $other, $token);

        $this->assertNotEmpty($this->logs->getRecords());
        foreach ($this->logs->getRecords() as $record) {
            $this->assertStringNotContainsString($token, (string) json_encode($record->toArray()));
        }
    }
}
```

In `tests/Feature/CsrfMiddlewareFeatureTest.php` ergänzen:

```php
    public function testWopiEndpointsPassWithoutCsrfTokenButNeighboursDoNot(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        // Collabora speichert von Server zu Server: keine Sitzung, kein CSRF-Token.
        // Ausgewiesen wird sich mit dem Zugangstoken, das WopiController prüft.
        $passed = $middleware->process($this->makeRequest('POST', '/wopi/files/5/contents'), $handler);
        $this->assertSame(200, $passed->getStatusCode());

        foreach (['/wopifoo', '/files/5/edit', '/files/5/replace'] as $path) {
            $blocked = $middleware->process($this->makeRequest('POST', $path), $handler);
            $this->assertSame(403, $blocked->getStatusCode(), $path);
        }
    }
```

- [ ] **Step 2: Tests laufen lassen, Fehlschlag prüfen**

Run: `WT php vendor/bin/phpunit --filter "WopiControllerFeatureTest|CsrfMiddlewareFeatureTest" | tail -8`
Expected: FAIL, `WopiController` nicht gefunden bzw. 403 statt 200 für `/wopi/...`.

- [ ] **Step 3: Hilfsklassen schreiben**

`src/Services/Office/WopiAccess.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileActor;

/** Ergebnis der Zugangsprüfung eines WOPI-Aufrufs. */
final class WopiAccess
{
    public function __construct(
        public readonly User $user,
        public readonly FileActor $actor,
        public readonly StoredFile $file,
        public readonly bool $canWrite
    ) {
    }
}
```

`src/Services/Office/WopiTimestamp.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Models\FileVersion;

/**
 * `LastModifiedTime` einer Datei für WOPI. Collabora schickt den Wert beim
 * Speichern als `X-COOL-WOPI-Timestamp` unverändert zurück; verglichen wird
 * deshalb die Zeichenkette, und die muss immer gleich gebildet werden.
 */
final class WopiTimestamp
{
    public static function of(?FileVersion $version): string
    {
        $moment = $version?->office_saved_at ?? $version?->created_at;

        return $moment === null ? '' : $moment->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
```

- [ ] **Step 4: `WopiController` schreiben**

`src/Controllers/WopiController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\FileFolderShare;
use App\Models\User;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileService;
use App\Services\Files\FileStorageRegistry;
use App\Services\NameFormatterService;
use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeSettings;
use App\Services\Office\OfficeTokenService;
use App\Services\Office\WopiAccess;
use App\Services\Office\WopiTimestamp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Stream;
use Slim\Psr7\UploadedFile;

/**
 * WOPI-Host für Collabora Online: Dateiinfo, Inhalt lesen, Inhalt speichern.
 *
 * Collabora hat keine Sitzung bei uns. Ausgewiesen wird sich mit dem Token aus
 * der Editor-Seite (Query-Parameter `access_token`), und jeder Aufruf prüft die
 * aktuellen Rechte neu - ein entzogenes Recht wirkt beim nächsten Speichern.
 * Das Token steht in keiner Logzeile.
 */
final class WopiController
{
    /** Statuscodes aus FileManagementException, die Collabora unverändert bekommt. */
    private const PASSED_THROUGH = [400, 403, 404, 409, 413, 422];

    public function __construct(
        private readonly OfficeTokenService $tokens,
        private readonly FileService $files,
        private readonly FileAccessService $access,
        private readonly FileStorageRegistry $storages,
        private readonly OfficeDiscovery $discovery,
        private readonly OfficeSettings $settings,
        private readonly NameFormatterService $names,
        private readonly LoggerInterface $logger
    ) {
    }

    public function checkFileInfo(Request $request, Response $response, array $args): Response
    {
        return $this->guarded($request, $response, (int) $args['id'], FileFolderShare::LEVEL_READ, function (
            WopiAccess $access
        ) use ($response): Response {
            $file = $access->file;

            return $this->json($response, [
                'BaseFileName' => (string) $file->name,
                'Size' => (int) $file->size,
                'Version' => (string) $file->current_version_id,
                'LastModifiedTime' => WopiTimestamp::of($file->currentVersion),
                'OwnerId' => (string) ($file->created_by ?? ''),
                'UserId' => (string) $access->user->id,
                'UserFriendlyName' => $this->names->formatPerson($access->user),
                'UserCanWrite' => $access->canWrite,
                'UserCanNotWriteRelative' => true,
                'PostMessageOrigin' => $this->settings->appOrigin(),
            ]);
        });
    }

    public function getFile(Request $request, Response $response, array $args): Response
    {
        return $this->guarded($request, $response, (int) $args['id'], FileFolderShare::LEVEL_READ, function (
            WopiAccess $access
        ) use ($response): Response {
            $version = $access->file->currentVersion;
            if ($version === null) {
                return $response->withStatus(404);
            }
            $stream = $this->storages->for((string) $version->storage_driver)->readStream((string) $version->storage_path);

            return $response
                ->withBody(new Stream($stream))
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('X-WOPI-ItemVersion', (string) $version->id);
        });
    }

    public function putFile(Request $request, Response $response, array $args): Response
    {
        return $this->guarded($request, $response, (int) $args['id'], FileFolderShare::LEVEL_EDIT, function (
            WopiAccess $access
        ) use ($request, $response): Response {
            $file = $access->file;
            if (!$access->canWrite) {
                return $response->withStatus(403);
            }

            $known = $request->getHeaderLine('X-COOL-WOPI-Timestamp');
            if ($known !== '' && $known !== WopiTimestamp::of($file->currentVersion)) {
                $this->logger->notice('Office save conflict.', [
                    'event' => 'office.save_conflict',
                    'file_id' => (int) $file->id,
                    'user_id' => $access->actor->userId,
                ]);

                return $this->json($response->withStatus(409), ['COOLStatusCode' => 1010]);
            }

            $body = $request->getBody();
            $upload = new UploadedFile($body, (string) $file->name, 'application/octet-stream', $body->getSize());
            $endsSession = strtolower($request->getHeaderLine('X-COOL-WOPI-IsExitSave')) === 'true';

            try {
                $version = $this->files->saveFromOffice($access->actor, (int) $file->id, $upload, $endsSession);
            } catch (FileManagementException $exception) {
                $status = in_array($exception->status, self::PASSED_THROUGH, true) ? $exception->status : 500;
                $this->logger->notice('Office save rejected.', [
                    'event' => 'office.save_rejected',
                    'file_id' => (int) $file->id,
                    'user_id' => $access->actor->userId,
                    'status' => $status,
                ]);

                return $response->withStatus($status);
            }

            return $this->json($response, ['LastModifiedTime' => WopiTimestamp::of($version)]);
        });
    }

    /**
     * Token auflösen, Person und Rechte prüfen, dann die Aktion ausführen.
     * 401: Token unbrauchbar oder Person deaktiviert. 403/404: Rechte, wie in
     * der Dateiablage - wer die Datei gar nicht mehr sieht, bekommt 404.
     *
     * @param \Closure(WopiAccess): Response $action
     */
    private function guarded(Request $request, Response $response, int $fileId, int $requiredLevel, \Closure $action): Response
    {
        $plain = (string) ($request->getQueryParams()['access_token'] ?? '');
        $token = $this->tokens->resolve($plain, $fileId);
        $user = $token === null ? null : User::find($token->user_id);
        $actor = $user === null ? null : FileActor::forUser($user);
        if ($user === null || $actor === null) {
            return $response->withStatus(401);
        }

        try {
            $file = $this->files->findWithFileLevel($actor, $fileId, $requiredLevel);
        } catch (FileManagementException $exception) {
            return $response->withStatus($exception->status === 403 ? 403 : 404);
        }

        $level = $this->access->fileLevelFor($actor, $file);
        $canWrite = $level >= FileFolderShare::LEVEL_EDIT
            && ($this->discovery->actionFor((string) $file->name)?->canEdit ?? false);

        return $action(new WopiAccess($user, $actor, $file, $canWrite));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

Hinweis: `findWithFileLevel` prüft bei einem Lesenden mit `LEVEL_EDIT` und wirft `forbidden()` (403); `testReaderMayReadButNotWrite` erwartet genau das.

- [ ] **Step 5: Routen, CSRF-Ausnahme, Container**

`src/Routes.php`: `use App\Controllers\WopiController;` ergänzen und direkt nach dem Block der öffentlichen Datei-Links (`/s/...`) einfügen:

```php
    // WOPI-Endpunkte für Collabora: ohne Anmeldung, das Zugangstoken aus der
    // Editor-Seite ist die Berechtigung. WopiController prüft Token und Rechte.
    if ($settings['modules']['office'] ?? false) {
        $app->get('/wopi/files/{id:[0-9]+}', [WopiController::class, 'checkFileInfo']);
        $app->get('/wopi/files/{id:[0-9]+}/contents', [WopiController::class, 'getFile']);
        $app->post('/wopi/files/{id:[0-9]+}/contents', [WopiController::class, 'putFile']);
    }
```

`src/Middleware/CsrfMiddleware.php`: `EXEMPT_PREFIXES` erweitern und den Kommentar darüber um einen Absatz ergänzen:

```php
     * `/wopi`: Collabora speichert von Server zu Server und hat weder Sitzung
     * noch CSRF-Token. WopiController weist es über das Zugangstoken aus.
```

```php
    private const EXEMPT_PREFIXES = [
        '/webdav',
        '/wopi',
    ];
```

`src/Dependencies.php`: `use App\Controllers\WopiController;` und `use App\Services\Office\OfficeTokenService;` ergänzen, nach dem `OfficeDiscovery`-Eintrag:

```php
        OfficeTokenService::class => \DI\autowire(),
        WopiController::class => \DI\autowire(),
```

- [ ] **Step 6: Tests laufen lassen**

Run: `WT php vendor/bin/phpunit --filter "WopiControllerFeatureTest|CsrfMiddlewareFeatureTest|DependenciesContainerWiringTest" | tail -8`
Expected: PASS.

- [ ] **Step 7: Schärfeprobe**

Je Sabotage den Test laufen lassen, Rot bestätigen, zurücknehmen:
1. In `putFile` die Bedingung `$known !== ''` durch `false` ersetzen → `testStaleTimestampIsAConflict` rot.
2. In `guarded` `FileActor::forUser($user)` durch `new FileActor((int) $user->id, false)` ersetzen → `testDeactivatedUserLosesAccess` rot.
3. In `putFile` `if (!$access->canWrite)` entfernen → `testViewOnlyFormatIsNotWritableEvenForEditors` rot.

- [ ] **Step 8: LF normalisieren und committen**

```bash
git add src/Services/Office/WopiAccess.php src/Services/Office/WopiTimestamp.php src/Controllers/WopiController.php src/Routes.php src/Middleware/CsrfMiddleware.php src/Dependencies.php tests/Feature/WopiControllerFeatureTest.php tests/Feature/CsrfMiddlewareFeatureTest.php
git commit -m "feat(office): WOPI-Endpunkte für Collabora mit Token und Konfliktprüfung

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Editor-Seite, CSP, CSRF-Schutz, Knöpfe in der Ablage

**Files:**
- Create: `src/Controllers/OfficeEditorController.php`
- Create: `templates/files/office_editor.twig`
- Create: `public/js/office-editor-messages.js`
- Create: `public/js/office-editor.js`
- Modify: `public/css/style.css` (Klasse `.office-editor-frame`)
- Modify: `src/Middleware/SecurityHeadersMiddleware.php` (Konstruktor, CSP für die Editor-Seite)
- Modify: `src/Middleware/HtmlFormCsrfInjectorMiddleware.php` (kein Token in Formulare mit absoluter Adresse)
- Modify: `src/Dependencies.php` (`SecurityHeadersMiddleware`, `OfficeEditorController`, Twig-Funktion `office_mode`)
- Modify: `src/Routes.php` (`/files/{id}/edit`)
- Modify: `templates/files/file.twig`, `templates/files/folder.twig` (Knöpfe)
- Test: `tests/Feature/OfficeEditorControllerFeatureTest.php`
- Test: `tests/Feature/SecurityHeadersMiddlewareFeatureTest.php` (neuer Test)
- Test: `tests/Feature/HtmlFormCsrfInjectorMiddlewareFeatureTest.php` (neuer Test)
- Test: `tests/js/office-editor-messages.test.mjs`

**Interfaces:**
- Consumes: `OfficeDiscovery::actionFor|isAvailable`, `OfficeAction::modeFor`, `OfficeSettings` (Task 1); `OfficeTokenService::issue`, `IssuedOfficeToken::ttlMilliseconds` (Task 2); `OfficeFixtures` (Task 1).
- Produces:
  - Route `GET /files/{id}/edit` → `OfficeEditorController::edit`
  - Twig-Funktion `office_mode(string fileName, int level): ?string`
  - `SecurityHeadersMiddleware::__construct(string $officeOrigin = '')`
  - JS `window.OfficeEditorMessages.actionFor(origin, data, officeOrigin)` → `'ready'|'close'|null`, `hostMessage(messageId)` → JSON-String

- [ ] **Step 1: Failing Tests schreiben (PHP)**

`tests/Feature/OfficeEditorControllerFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FileBrowserController;
use App\Controllers\FileDetailController;
use App\Controllers\OfficeEditorController;
use App\Models\FileFolderShare as Share;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileActor;
use App\Services\Files\FileService;
use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeSettings;
use App\Services\Office\OfficeTokenService;
use DI\Container;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Unit\Bootstrap;

/**
 * Die Editor-Seite stellt das Token aus und schickt es per Formular in den
 * Collabora-Rahmen; Detail- und Ordnerseite bieten die Bearbeitung je nach Stufe an.
 */
class OfficeEditorControllerFeatureTest extends TestCase
{
    use FileFixtures;
    use OfficeFixtures;
    use TestHttpHelpers;

    private Container $container;
    private ?string $previousStoragePath = null;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $previous = getenv('FILES_STORAGE_PATH');
        $this->previousStoragePath = $previous === false ? null : $previous;
        putenv('FILES_STORAGE_PATH=' . $this->storageDir);
        $_ENV['FILES_STORAGE_PATH'] = $this->storageDir;
        $_SERVER['FILES_STORAGE_PATH'] = $this->storageDir;
        $this->container = $this->buildContainer(true);
    }

    protected function tearDown(): void
    {
        if ($this->previousStoragePath === null) {
            putenv('FILES_STORAGE_PATH');
            unset($_ENV['FILES_STORAGE_PATH'], $_SERVER['FILES_STORAGE_PATH']);
        } else {
            putenv('FILES_STORAGE_PATH=' . $this->previousStoragePath);
            $_ENV['FILES_STORAGE_PATH'] = $this->previousStoragePath;
            $_SERVER['FILES_STORAGE_PATH'] = $this->previousStoragePath;
        }
        $this->tearDownOfficeFixtures();
        $this->tearDownFileFixtures();
    }

    /**
     * @param (\Closure(string): ?string)|null $fetch
     */
    private function buildContainer(bool $officeEnabled, ?\Closure $fetch = null): Container
    {
        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($builder);
        $container = $builder->build();
        $container->set(Capsule::class, Bootstrap::getCapsule());
        $settings = $container->get('settings');
        $settings['modules']['files'] = true;
        $settings['modules']['office'] = $officeEnabled;
        $container->set('settings', $settings);
        $container->set(OfficeSettings::class, $this->officeSettings());
        $container->set(OfficeDiscovery::class, $this->discovery($fetch, null, $fetch !== null));

        return $container;
    }

    private function login(int $userId): void
    {
        $_SESSION = ['user_id' => $userId, 'can_manage_files' => false];
    }

    /** @return array{0: User, 1: StoredFile} */
    private function sharedFile(int $level, string $name = 'Protokoll.odt'): array
    {
        $member = $this->createMember('Erika');
        $root = $this->createFolder('Vorstand ' . bin2hex(random_bytes(3)));
        $this->share($root, 'user', (int) $member->id, $level);
        $upload = new UploadedFile((new StreamFactory())->createStream('Stand 1'), $name, 'application/octet-stream', 7);
        $file = $this->container->get(FileService::class)->upload(new FileActor((int) $member->id, true), $root, $upload)->file;

        return [$member, $file];
    }

    public function testEditorPagePostsTokenIntoCollaboraFrame(): void
    {
        [$member, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $member->id);

        $response = $controller->edit($this->makeRequest('GET', '/files/' . $file->id . '/edit'), $this->makeResponse(), [
            'id' => (string) $file->id,
        ]);
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'action="https://office.example.test/browser/abc123/cool.html?WOPISrc=http%3A%2F%2Fweb%2Fwopi%2Ffiles%2F' . $file->id,
            $html
        );
        $this->assertStringContainsString('target="officeEditorFrame"', $html);
        $this->assertStringContainsString('data-office-origin="https://office.example.test"', $html);
        $this->assertStringContainsString('Bearbeiten', $html);
        $this->assertMatchesRegularExpression('/name="access_token_ttl" value="\d{13}"/', $html);

        $this->assertSame(1, preg_match('/name="access_token" value="([A-Za-z0-9_-]{43})"/', $html, $match));
        $this->assertNotNull($this->container->get(OfficeTokenService::class)->resolve($match[1], (int) $file->id));
    }

    public function testReaderGetsViewOnlyPage(): void
    {
        [$member, $file] = $this->sharedFile(Share::LEVEL_READ);
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $member->id);

        $html = (string) $controller->edit($this->makeRequest('GET', '/files/' . $file->id . '/edit'), $this->makeResponse(), [
            'id' => (string) $file->id,
        ])->getBody();

        $this->assertStringContainsString('Nur ansehen', $html);
    }

    public function testStrangerIsSentBackToFiles(): void
    {
        [, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $stranger = $this->createMember('Fremd');
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $stranger->id);

        $response = $controller->edit($this->makeRequest('GET', '/files/' . $file->id . '/edit'), $this->makeResponse(), [
            'id' => (string) $file->id,
        ]);

        $this->assertRedirect($response, '/files');
    }

    public function testUnsupportedTypeAndUnreachableServerLeadBackToFile(): void
    {
        [$member, $audio] = $this->sharedFile(Share::LEVEL_EDIT, 'Einsingen.mp3');
        $controller = $this->container->get(OfficeEditorController::class);
        $this->login((int) $member->id);
        $response = $controller->edit($this->makeRequest('GET', '/files/' . $audio->id . '/edit'), $this->makeResponse(), [
            'id' => (string) $audio->id,
        ]);
        $this->assertRedirect($response, '/files/' . $audio->id);
        $this->assertStringContainsString('Dateityp', (string) ($_SESSION['error'] ?? ''));

        [$editor, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $down = $this->buildContainer(true, static fn (string $url): ?string => null);
        $downController = $down->get(OfficeEditorController::class);
        $this->login((int) $editor->id);
        $response = $downController->edit($this->makeRequest('GET', '/files/' . $file->id . '/edit'), $this->makeResponse(), [
            'id' => (string) $file->id,
        ]);
        $this->assertRedirect($response, '/files/' . $file->id);
        $this->assertStringContainsString('nicht erreichbar', (string) ($_SESSION['error'] ?? ''));
    }

    public function testFileAndFolderPagesOfferBrowserEditingByLevel(): void
    {
        [$editor, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        [$reader, $readable] = $this->sharedFile(Share::LEVEL_READ);
        [$audioOwner, $audio] = $this->sharedFile(Share::LEVEL_EDIT, 'Einsingen.mp3');
        $detail = $this->container->get(FileDetailController::class);
        $browser = $this->container->get(FileBrowserController::class);

        $this->login((int) $editor->id);
        $html = (string) $detail->show($this->makeRequest('GET', '/files/' . $file->id), $this->makeResponse(), [
            'id' => (string) $file->id,
        ])->getBody();
        $this->assertStringContainsString('href="/files/' . $file->id . '/edit"', $html);
        $this->assertStringContainsString('Im Browser bearbeiten', $html);

        $folderHtml = (string) $browser->folder(
            $this->makeRequest('GET', '/files/folders/' . $file->folder_id),
            $this->makeResponse(),
            ['id' => (string) $file->folder_id]
        )->getBody();
        $this->assertStringContainsString('href="/files/' . $file->id . '/edit"', $folderHtml);

        $this->login((int) $reader->id);
        $html = (string) $detail->show($this->makeRequest('GET', '/files/' . $readable->id), $this->makeResponse(), [
            'id' => (string) $readable->id,
        ])->getBody();
        $this->assertStringContainsString('Im Browser ansehen', $html);

        $this->login((int) $audioOwner->id);
        $html = (string) $detail->show($this->makeRequest('GET', '/files/' . $audio->id), $this->makeResponse(), [
            'id' => (string) $audio->id,
        ])->getBody();
        $this->assertStringNotContainsString('/files/' . $audio->id . '/edit', $html);
    }

    public function testNoBrowserEditingWithoutModule(): void
    {
        [$editor, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $detail = $this->buildContainer(false)->get(FileDetailController::class);
        $this->login((int) $editor->id);

        $html = (string) $detail->show($this->makeRequest('GET', '/files/' . $file->id), $this->makeResponse(), [
            'id' => (string) $file->id,
        ])->getBody();

        $this->assertStringNotContainsString('/files/' . $file->id . '/edit', $html);
    }

    public function testPagesRenderWithoutButtonsWhenOfficeServerIsDown(): void
    {
        [$editor, $file] = $this->sharedFile(Share::LEVEL_EDIT);
        $down = $this->buildContainer(true, static fn (string $url): ?string => null);
        $detail = $down->get(FileDetailController::class);
        $browser = $down->get(FileBrowserController::class);
        $this->login((int) $editor->id);

        $detailResponse = $detail->show($this->makeRequest('GET', '/files/' . $file->id), $this->makeResponse(), [
            'id' => (string) $file->id,
        ]);
        $folderResponse = $browser->folder(
            $this->makeRequest('GET', '/files/folders/' . $file->folder_id),
            $this->makeResponse(),
            ['id' => (string) $file->folder_id]
        );

        $this->assertSame(200, $detailResponse->getStatusCode());
        $this->assertSame(200, $folderResponse->getStatusCode());
        $this->assertStringNotContainsString('/files/' . $file->id . '/edit', (string) $detailResponse->getBody());
        $this->assertStringNotContainsString('/files/' . $file->id . '/edit', (string) $folderResponse->getBody());
    }
}
```

Hinweis zu `login()`: In `FileControllerFeatureTest` steht, dass der Aufbau von Twig die Sitzung neu aufsetzt – deshalb werden Controller **vor** `login()` aus dem Container geholt. Die Tests oben halten diese Reihenfolge ein.

In `tests/Feature/SecurityHeadersMiddlewareFeatureTest.php` ergänzen:

```php
    private function cspFor(SecurityHeadersMiddleware $middleware, string $path): string
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);
        $response = $middleware->process($request, new class () implements RequestHandlerInterface {
            public function handle(Request $request): ResponseInterface
            {
                return new Response();
            }
        });

        return $response->getHeaderLine('Content-Security-Policy');
    }

    /**
     * Die Editor-Seite der Dateiablage schickt das Zugangstoken per Formular in einen
     * Collabora-Rahmen. Nur dort und nur für genau diesen Ursprung öffnet sich die CSP.
     */
    public function testOfficeEditorPageMayFrameAndPostToOfficeServerOnly(): void
    {
        $middleware = new SecurityHeadersMiddleware('https://office.example.test');

        $csp = $this->cspFor($middleware, '/files/12/edit');
        $this->assertStringContainsString('frame-src https://office.example.test', $csp);
        $this->assertStringContainsString("form-action 'self' https://office.example.test", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);

        foreach (['/files/12', '/files/12/edit/x', '/files/folders/3', '/dashboard'] as $path) {
            $this->assertStringNotContainsString('office.example.test', $this->cspFor($middleware, $path), $path);
        }
        $this->assertStringNotContainsString('frame-src', $this->cspFor(new SecurityHeadersMiddleware(), '/files/12/edit'));
    }
```

In `tests/Feature/HtmlFormCsrfInjectorMiddlewareFeatureTest.php` ergänzen:

```php
    /**
     * Das Editor-Formular geht an den Office-Server. Unser CSRF-Token hat dort nichts
     * verloren - Formulare mit absoluter Adresse bleiben unangetastet.
     */
    public function testDoesNotSendTokenToFormsWithAbsoluteAction(): void
    {
        $_SESSION = [];

        $middleware = new HtmlFormCsrfInjectorMiddleware();
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write(
                    '<form action="https://office.example.test/cool.html?WOPISrc=x" method="post"></form>'
                    . '<form action="//office.example.test/x" method="post"></form>'
                    . '<form action="/profile" method="post"></form>'
                );

                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            }
        };

        $body = (string) $middleware->process($request, $handler)->getBody();

        $this->assertSame(1, substr_count($body, 'name="_csrf"'), 'Nur das eigene Formular bekommt das Token.');
        $this->assertStringContainsString('<form action="/profile" method="post"><input type="hidden" name="_csrf"', $body);
    }
```

- [ ] **Step 2: Failing Test schreiben (JS)**

`tests/js/office-editor-messages.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { actionFor, hostMessage } = require('../../public/js/office-editor-messages.js');

const office = 'https://office.example.test';

test('Frame_Ready von Collabora heißt: bereit für Host_PostmessageReady', () => {
    const data = JSON.stringify({ MessageId: 'App_LoadingStatus', Values: { Status: 'Frame_Ready' } });
    assert.equal(actionFor(office, data, office), 'ready');
});

test('UI_Close heißt: Editor schließen', () => {
    assert.equal(actionFor(office, JSON.stringify({ MessageId: 'UI_Close' }), office), 'close');
});

test('Nachrichten fremder Herkunft werden ignoriert', () => {
    assert.equal(actionFor('https://evil.example.test', JSON.stringify({ MessageId: 'UI_Close' }), office), null);
});

test('Kaputte oder unbekannte Daten werden ignoriert', () => {
    assert.equal(actionFor(office, '{kaputt', office), null);
    assert.equal(actionFor(office, null, office), null);
    assert.equal(actionFor(office, JSON.stringify({ MessageId: 'Doc_ModifiedStatus' }), office), null);
});

test('Ohne bekannten Office-Ursprung wird nichts angenommen', () => {
    assert.equal(actionFor('', JSON.stringify({ MessageId: 'UI_Close' }), ''), null);
});

test('Host-Nachricht ist JSON mit MessageId', () => {
    assert.equal(JSON.parse(hostMessage('Host_PostmessageReady')).MessageId, 'Host_PostmessageReady');
});
```

- [ ] **Step 3: Tests laufen lassen, Fehlschlag prüfen**

Run: `WT php vendor/bin/phpunit --filter "OfficeEditorControllerFeatureTest|SecurityHeadersMiddlewareFeatureTest|HtmlFormCsrfInjectorMiddlewareFeatureTest" | tail -8`
Expected: FAIL (`OfficeEditorController` fehlt, CSP ohne `frame-src`, zwei `_csrf` statt einem).

Run: `WT node --test tests/js/office-editor-messages.test.mjs`
Expected: FAIL, `Cannot find module '../../public/js/office-editor-messages.js'`.

- [ ] **Step 4: JS schreiben**

`public/js/office-editor-messages.js`:

```js
/**
 * Reine Nachrichtenlogik des Office-Editors - ohne DOM, damit sie unter `node --test`
 * prüfbar ist (tests/js/office-editor-messages.test.mjs). Im Browser hängt sie an
 * window.OfficeEditorMessages, in Node an module.exports.
 */
(function (global) {
    'use strict';

    /**
     * Was eine PostMessage von Collabora für die Seite bedeutet: 'ready', 'close'
     * oder null. Nachrichten anderer Herkunft werden nie ausgewertet.
     */
    function actionFor(origin, data, officeOrigin) {
        if (!officeOrigin || origin !== officeOrigin) {
            return null;
        }

        var message = data;
        if (typeof data === 'string') {
            try {
                message = JSON.parse(data);
            } catch (error) {
                return null;
            }
        }
        if (!message || typeof message.MessageId !== 'string') {
            return null;
        }

        if (message.MessageId === 'App_LoadingStatus' && message.Values && message.Values.Status === 'Frame_Ready') {
            return 'ready';
        }
        if (message.MessageId === 'UI_Close') {
            return 'close';
        }

        return null;
    }

    function hostMessage(messageId) {
        return JSON.stringify({ MessageId: messageId, SendTime: Date.now(), Values: {} });
    }

    var api = {
        actionFor: actionFor,
        hostMessage: hostMessage,
    };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        global.OfficeEditorMessages = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
```

`public/js/office-editor.js`:

```js
/**
 * Editor-Seite der Dateiablage: schickt das Formular mit dem Zugangstoken in den
 * Collabora-Rahmen und reagiert auf dessen Nachrichten. Die Auswertung der
 * Nachrichten steht in office-editor-messages.js.
 */
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('officeEditorForm');
    const frame = document.getElementById('officeEditorFrame');
    if (!form || !frame || !window.OfficeEditorMessages) {
        return;
    }

    const officeOrigin = form.dataset.officeOrigin || '';
    const backUrl = form.dataset.backUrl || '/files';

    window.addEventListener('message', function (event) {
        if (event.source !== frame.contentWindow) {
            return;
        }

        const action = window.OfficeEditorMessages.actionFor(event.origin, event.data, officeOrigin);
        if (action === 'ready') {
            frame.contentWindow.postMessage(window.OfficeEditorMessages.hostMessage('Host_PostmessageReady'), officeOrigin);
        } else if (action === 'close') {
            window.location.assign(backUrl);
        }
    });

    form.submit();
});
```

`public/css/style.css` am Ende ergänzen:

```css
/* Editor-Seite der Dateiablage: Collabora füllt den Platz unter der Kopfzeile. */
.office-editor-frame {
    display: block;
    width: 100%;
    height: calc(100vh - 12rem);
    min-height: 28rem;
    border: 0;
}
```

- [ ] **Step 5: Controller und Template**

`src/Controllers/OfficeEditorController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FileControllerSupport;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileService;
use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeSettings;
use App\Services\Office\OfficeTokenService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Seite mit dem Collabora-Editor. Sie stellt das Zugangstoken aus und schickt es
 * per Formular-POST in den Rahmen - so steht es nicht in der Adresszeile.
 */
final class OfficeEditorController
{
    use FileControllerSupport;

    public function __construct(
        private readonly Twig $view,
        private readonly FileService $files,
        private readonly FileAccessService $access,
        private readonly OfficeDiscovery $discovery,
        private readonly OfficeTokenService $tokens,
        private readonly OfficeSettings $settings
    ) {
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor();
        try {
            $file = $this->files->findReadable($actor, (int) $args['id']);
        } catch (FileManagementException) {
            $_SESSION['error'] = 'Die Datei existiert nicht oder ist nicht freigegeben.';

            return $this->redirect($response, '/files');
        }

        $backUrl = '/files/' . $file->id;
        if (!$this->discovery->isAvailable()) {
            $_SESSION['error'] = 'Der Office-Server ist gerade nicht erreichbar. Bitte später erneut versuchen.';

            return $this->redirect($response, $backUrl);
        }

        $action = $this->discovery->actionFor((string) $file->name);
        if ($action === null) {
            $_SESSION['error'] = 'Dieser Dateityp lässt sich nicht im Browser öffnen.';

            return $this->redirect($response, $backUrl);
        }

        $mode = $action->modeFor($this->access->fileLevelFor($actor, $file));
        $token = $this->tokens->issue($actor->userId, (int) $file->id);
        $wopiSrc = $this->settings->wopiBaseUrl . '/wopi/files/' . $file->id;

        return $this->view->render($response, 'files/office_editor.twig', [
            'file' => $file,
            'can_edit' => $mode === 'edit',
            'editor_url' => $action->urlSrc . 'WOPISrc=' . rawurlencode($wopiSrc) . '&lang=de&closebutton=1',
            'access_token' => $token->plain,
            'access_token_ttl' => $token->ttlMilliseconds(),
            'office_origin' => $this->settings->serverOrigin(),
            'back_url' => $backUrl,
        ]);
    }
}
```

`templates/files/office_editor.twig`:

```twig
{% extends "layout.twig" %}

{% block title %}
    {{ file.name }} - Dateien - {{ app_settings.app_name|default("Chor-Manager") }}
{% endblock title %}

{% block page_header %}
    <section class="page-header">
        <div class="files-header-main">
            <h1 class="h2 mb-1 text-break">{{ file.name }}</h1>
            <p class="text-muted mb-0 small">{{ can_edit ? "Bearbeiten im Browser" : "Nur ansehen" }}</p>
        </div>
        <div class="page-actions">
            <a class="btn btn-outline-secondary btn-sm" href="{{ back_url }}">
                <i class="bi bi-x-lg me-1" aria-hidden="true"></i> Schließen
            </a>
        </div>
    </section>
{% endblock page_header %}

{% block content %}
    <form id="officeEditorForm"
          class="d-none"
          method="post"
          action="{{ editor_url }}"
          target="officeEditorFrame"
          data-office-origin="{{ office_origin }}"
          data-back-url="{{ back_url }}">
        <input type="hidden" name="access_token" value="{{ access_token }}">
        <input type="hidden" name="access_token_ttl" value="{{ access_token_ttl }}">
    </form>
    <iframe id="officeEditorFrame"
            name="officeEditorFrame"
            class="office-editor-frame"
            title="Dokument-Editor"
            allow="clipboard-read; clipboard-write; fullscreen"></iframe>
{% endblock content %}

{% block scripts %}
    <script src="{{ asset_path("/js/office-editor-messages.js") }}"></script>
    <script src="{{ asset_path("/js/office-editor.js") }}"></script>
{% endblock scripts %}
```

- [ ] **Step 6: CSP, CSRF-Injektor, Container, Route, Twig-Funktion**

`src/Middleware/SecurityHeadersMiddleware.php`:

```php
    public function __construct(private readonly string $officeOrigin = '')
    {
    }
```

In `process()` den CSP-Aufruf ersetzen durch
`->withHeader('Content-Security-Policy', $this->buildCsp($allowsSelfFraming, $this->embedsOffice($request)))`, dazu:

```php
    /**
     * Die Editor-Seite der Dateiablage bettet Collabora ein: Ihr Formular schickt das
     * Zugangstoken per POST in den Rahmen. Nur dort und nur für genau den Ursprung aus
     * OFFICE_SERVER_URL öffnen sich frame-src und form-action.
     */
    private function embedsOffice(Request $request): bool
    {
        return $this->officeOrigin !== ''
            && (bool) preg_match('#^/files/\d+/edit$#', $request->getUri()->getPath());
    }
```

`buildCsp` bekommt den zweiten Parameter `bool $embedsOffice`; die Zeile `"form-action 'self'",` wird zu
`$embedsOffice ? "form-action 'self' " . $this->officeOrigin : "form-action 'self'",`, das `implode` wird zu:

```php
        $directives = [
            // ... bisherige Einträge unverändert, form-action wie oben ...
        ];
        if ($embedsOffice) {
            $directives[] = 'frame-src ' . $this->officeOrigin;
        }

        return implode('; ', $directives);
```

`src/Middleware/HtmlFormCsrfInjectorMiddleware.php`: im Callback direkt nach der `method`-Prüfung einfügen:

```php
                // Formulare an eine fremde Adresse bekommen kein Token: Der Editor der
                // Dateiablage schickt sein Formular an den Office-Server, und unser
                // CSRF-Token hat dort nichts verloren. Eigene Formulare nutzen relative Pfade.
                if (preg_match('/\saction\s*=\s*(["\'])\s*(?:[a-z][a-z0-9+.\-]*:|\/\/)/i', $openingTag)) {
                    return $matches[0];
                }
```

`src/Dependencies.php`: `use App\Controllers\OfficeEditorController;` und `use App\Middleware\SecurityHeadersMiddleware;` (falls nicht vorhanden) ergänzen. Nach `WopiController::class => \DI\autowire(),`:

```php
        OfficeEditorController::class => \DI\autowire(),
        SecurityHeadersMiddleware::class => function (ContainerInterface $c): SecurityHeadersMiddleware {
            $officeEnabled = (bool) ($c->get('settings')['modules']['office'] ?? false);

            return new SecurityHeadersMiddleware($officeEnabled ? $c->get(OfficeSettings::class)->serverOrigin() : '');
        },
```

Im Twig-Factory-Block direkt nach der Funktion `attachment_previewable`:

```php
            // Ob eine Datei "Im Browser bearbeiten/ansehen" anbietet. Die Discovery
            // wird erst beim ersten Aufruf geladen - Seiten ohne Dateien zahlen nichts.
            $officeEnabled = (bool) ($allSettings['modules']['office'] ?? false);
            $environment->addFunction(new TwigFunction(
                'office_mode',
                static function (string $fileName, int $level) use ($c, $officeEnabled): ?string {
                    if (!$officeEnabled) {
                        return null;
                    }

                    return $c->get(OfficeDiscovery::class)->actionFor($fileName)?->modeFor($level);
                }
            ));
```

Achtung: Im Twig-Factory heißen die Einstellungen `$allSettings` – vor dem Einfügen prüfen, dass die Variable an dieser Stelle definiert ist (siehe `$environment->addGlobal('settings', $allSettings);`).

`src/Routes.php`: `use App\Controllers\OfficeEditorController;` ergänzen. In der `/files`-Gruppe der Closure `use ($settings)` geben – `function (RouteCollectorProxy $files) use ($settings) {` – und nach `$files->get('/{id:[0-9]+}', ...)` einfügen:

```php
                        if ($settings['modules']['office'] ?? false) {
                            $files->get('/{id:[0-9]+}/edit', [OfficeEditorController::class, 'edit']);
                        }
```

In `tests/Feature/WopiControllerFeatureTest.php` in `testRoutesExistOnlyWithOfficeModule` ergänzen:

```php
            $this->assertSame($enabled, in_array('/files/{id:[0-9]+}/edit', $patterns, true));
```

- [ ] **Step 7: Knöpfe in Detail- und Ordnerseite**

`templates/files/file.twig`, im Block `page-actions` direkt vor dem Link „Herunterladen“:

```twig
            {% set _office_mode = office_mode(file.name, level) %}
            {% if _office_mode %}
                <a class="btn btn-outline-primary btn-sm" href="/files/{{ file.id }}/edit">
                    {% if _office_mode == "edit" %}
                        <i class="bi bi-pencil-square me-1" aria-hidden="true"></i> Im Browser bearbeiten
                    {% else %}
                        <i class="bi bi-eye me-1" aria-hidden="true"></i> Im Browser ansehen
                    {% endif %}
                </a>
            {% endif %}
```

`templates/files/folder.twig`, im Dropdown der Dateizeile direkt nach dem `<li>` mit „Details“ (vor „Herunterladen“):

```twig
                                                {% set _office_mode = office_mode(file.name, level) %}
                                                {% if _office_mode %}
                                                    <li>
                                                        <a class="dropdown-item" href="/files/{{ file.id }}/edit">
                                                            {% if _office_mode == "edit" %}
                                                                <i class="bi bi-pencil-square me-2" aria-hidden="true"></i>Im Browser bearbeiten
                                                            {% else %}
                                                                <i class="bi bi-eye me-2" aria-hidden="true"></i>Im Browser ansehen
                                                            {% endif %}
                                                        </a>
                                                    </li>
                                                {% endif %}
```

Überschreitet eine Zeile 130 Zeichen, den Text auf eine eigene Zeile setzen; `twigcs` entscheidet.

- [ ] **Step 8: Tests laufen lassen**

Run: `WT php vendor/bin/phpunit --filter "OfficeEditorControllerFeatureTest|SecurityHeadersMiddlewareFeatureTest|HtmlFormCsrfInjectorMiddlewareFeatureTest|AttachmentPreviewCspFeatureTest|WopiControllerFeatureTest|FileControllerFeatureTest|FileSharingControllerFeatureTest|TemplateSecurityFeatureTest" | tail -8`
Expected: PASS.

Run: `WT node --test tests/js/office-editor-messages.test.mjs`
Expected: PASS (6 Tests).

- [ ] **Step 9: Schärfeprobe**

1. In `embedsOffice` das `$` im Muster entfernen → `testOfficeEditorPageMayFrameAndPostToOfficeServerOnly` rot (`/files/12/edit/x`).
2. Die neue Prüfung im CSRF-Injektor auskommentieren → `testDoesNotSendTokenToFormsWithAbsoluteAction` rot.
3. In `actionFor` (JS) die Ursprungsprüfung entfernen → JS-Test „fremder Herkunft“ rot.

- [ ] **Step 10: Twig-Stil prüfen**

Run: `WT composer twigcs`
Expected: keine Befunde; sonst `WT composer twigcbf` und erneut prüfen.

- [ ] **Step 11: LF normalisieren und committen**

```bash
git add src/Controllers/OfficeEditorController.php templates/files/office_editor.twig templates/files/file.twig templates/files/folder.twig public/js/office-editor-messages.js public/js/office-editor.js public/css/style.css src/Middleware/SecurityHeadersMiddleware.php src/Middleware/HtmlFormCsrfInjectorMiddleware.php src/Dependencies.php src/Routes.php tests/Feature/OfficeEditorControllerFeatureTest.php tests/Feature/SecurityHeadersMiddlewareFeatureTest.php tests/Feature/HtmlFormCsrfInjectorMiddlewareFeatureTest.php tests/Feature/WopiControllerFeatureTest.php tests/js/office-editor-messages.test.mjs
git commit -m "feat(office): Editor-Seite mit Collabora-Rahmen und Knöpfe in der Dateiablage

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: DDEV-Container, `.env.example`, Seed-Daten

**Files:**
- Create: `.ddev/docker-compose.collabora.yaml`
- Modify: `.env.example`
- Modify: `src/Services/DevSeedAttachmentFixtures.php` (`odt()`, `docx()`, `zip()`)
- Modify: `src/Services/DevSeedService.php` (zwei Office-Dateien im Vorstandsordner)
- Test: `tests/Feature/DevSeedOfficeDocumentsFeatureTest.php`

**Interfaces:**
- Consumes: `office_access_tokens` im Reset (Task 2).
- Produces: `DevSeedAttachmentFixtures::odt(string $text): array{mime_type: string, extension: string, content: string}`, `DevSeedAttachmentFixtures::docx(string $text): array{...}`

- [ ] **Step 1: Failing Test schreiben**

`tests/Feature/DevSeedOfficeDocumentsFeatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DevSeedAttachmentFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Damit sich die Bearbeitung im Browser lokal ausprobieren lässt, legt der Seed
 * echte ODF- und OOXML-Dateien an.
 */
final class DevSeedOfficeDocumentsFeatureTest extends TestCase
{
    private function open(string $content): \ZipArchive
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'odt');
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        register_shutdown_function(static fn () => @unlink($path));

        return $zip;
    }

    public function testOdtHasUncompressedMimetypeFirstAndTheText(): void
    {
        $fixture = DevSeedAttachmentFixtures::odt('Protokoll der Vorstandssitzung');
        $zip = $this->open($fixture['content']);

        $this->assertSame('odt', $fixture['extension']);
        // ODF verlangt "mimetype" als ersten, unkomprimierten Eintrag.
        $this->assertSame('mimetype', $zip->statIndex(0)['name']);
        $this->assertSame(\ZipArchive::CM_STORE, $zip->statIndex(0)['comp_method']);
        $this->assertSame('application/vnd.oasis.opendocument.text', $zip->getFromName('mimetype'));
        $this->assertStringContainsString('Protokoll der Vorstandssitzung', (string) $zip->getFromName('content.xml'));
    }

    public function testDocxContainsDocumentPartAndText(): void
    {
        $fixture = DevSeedAttachmentFixtures::docx('Probenplan Herbst & Winter');
        $zip = $this->open($fixture['content']);

        $this->assertSame('docx', $fixture['extension']);
        $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
        $this->assertStringContainsString('Probenplan Herbst &amp; Winter', (string) $zip->getFromName('word/document.xml'));
    }

    public function testDevSeedUploadsOfficeDocumentsAndResetsTokens(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertStringContainsString('DevSeedAttachmentFixtures::odt(', $content);
        $this->assertStringContainsString('DevSeedAttachmentFixtures::docx(', $content);
        $this->assertStringContainsString("'office_access_tokens',", $content);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag prüfen**

Run: `WT php vendor/bin/phpunit --filter DevSeedOfficeDocumentsFeatureTest | tail -8`
Expected: FAIL, `Call to undefined method ...::odt()`.

- [ ] **Step 3: Fixtures schreiben**

In `src/Services/DevSeedAttachmentFixtures.php` nach `pdf()` einfügen:

```php
    /**
     * Minimales Textdokument (ODF), das Collabora öffnet.
     *
     * @return array{mime_type: string, extension: string, content: string}
     */
    public static function odt(string $text): array
    {
        $mimeType = 'application/vnd.oasis.opendocument.text';

        return [
            'mime_type' => $mimeType,
            'extension' => 'odt',
            'content' => self::zip([
                'mimetype' => $mimeType,
                'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"'
                    . ' manifest:version="1.2">'
                    . '<manifest:file-entry manifest:full-path="/" manifest:media-type="' . $mimeType . '"/>'
                    . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
                    . '</manifest:manifest>',
                'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
                    . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.2">'
                    . '<office:body><office:text><text:p>' . htmlspecialchars($text, ENT_XML1) . '</text:p>'
                    . '</office:text></office:body></office:document-content>',
            ]),
        ];
    }

    /**
     * Minimales Word-Dokument (OOXML), das Collabora öffnet.
     *
     * @return array{mime_type: string, extension: string, content: string}
     */
    public static function docx(string $text): array
    {
        return [
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'content' => self::zip([
                '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                    . '<Default Extension="xml" ContentType="application/xml"/>'
                    . '<Override PartName="/word/document.xml"'
                    . ' ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                    . '</Types>',
                '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1"'
                    . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
                    . ' Target="word/document.xml"/>'
                    . '</Relationships>',
                'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                    . '<w:body><w:p><w:r><w:t>' . htmlspecialchars($text, ENT_XML1) . '</w:t></w:r></w:p></w:body>'
                    . '</w:document>',
            ]),
        ];
    }

    /**
     * Packt Einträge in ein ZIP. Der erste bleibt unkomprimiert - ODF verlangt das
     * für `mimetype`, OOXML stört es nicht.
     *
     * @param array<string, string> $entries
     */
    private static function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'seedzip');
        if ($path === false) {
            throw new \RuntimeException('Could not create temporary file.');
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create zip archive.');
            }
            $first = true;
            foreach ($entries as $name => $content) {
                $zip->addFromString($name, $content);
                if ($first) {
                    $zip->setCompressionName($name, \ZipArchive::CM_STORE);
                    $first = false;
                }
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
```

In `src/Services/DevSeedService.php` direkt nach
`$upload($board, 'Einladung Jahreshauptversammlung.pdf', $pdf('Einladung Jahreshauptversammlung'));` einfügen:

```php
            // Office-Dokumente, damit sich die Bearbeitung im Browser lokal ausprobieren lässt.
            $upload(
                $board,
                'Protokoll-Vorlage Vorstandssitzung.odt',
                DevSeedAttachmentFixtures::odt('Protokoll der Vorstandssitzung')
            );
            $upload($board, 'Probenplan Herbst.docx', DevSeedAttachmentFixtures::docx('Probenplan Herbst'));
```

- [ ] **Step 4: DDEV-Container und `.env.example`**

`.ddev/docker-compose.collabora.yaml`:

```yaml
# Collabora Online (CODE) für die Bearbeitung von Office-Dokumenten der Dateiablage.
#
# Optional: Der Dienst hängt am Compose-Profil "collabora" und startet nur mit
#   ddev start --profiles=collabora
# (bzw. ddev restart --profiles=collabora). Die Tests brauchen ihn nicht.
#
# Adressen (in .env des Projekts, nicht in .ddev/.env*):
#   FEATURE_OFFICE=true
#   OFFICE_SERVER_URL=https://chormanager.ddev.site:9980   # Browser -> Collabora über den DDEV-Router
#   OFFICE_SERVER_INTERNAL_URL=http://collabora:9980       # ChorManager -> Collabora (Discovery)
#   OFFICE_WOPI_BASE_URL=http://web                        # Collabora -> ChorManager (WOPI)
#
# aliasgroup1 erlaubt genau einen WOPI-Host: den Web-Container. TLS endet am
# DDEV-Router, deshalb ssl.enable=false und ssl.termination=true. frame_ancestors
# erlaubt das Einbetten nur in die DDEV-Seite.
# Das Image-Tag beim Einrichten auf eine aktuelle Version von collabora/code prüfen
# und fest eintragen - "latest" wechselt still unter den Füßen.
services:
  collabora:
    image: collabora/code:25.04.5.1.1
    container_name: ddev-${DDEV_SITENAME}-collabora
    profiles: ["collabora"]
    labels:
      com.ddev.site-name: ${DDEV_SITENAME}
      com.ddev.approot: ${DDEV_APPROOT}
    restart: "no"
    cap_add:
      - MKNOD
    expose:
      - "9980"
    environment:
      - aliasgroup1=http://web
      - extra_params=--o:ssl.enable=false --o:ssl.termination=true --o:net.frame_ancestors=https://${DDEV_HOSTNAME}
      - username=admin
      - password=admin
      - VIRTUAL_HOST=${DDEV_HOSTNAME}
      - HTTP_EXPOSE=9979:9980
      - HTTPS_EXPOSE=9980:9980
```

`.env.example` direkt nach dem Block der Dateiablage (`FILES_MAX_ZIP_MB=500`):

```dotenv

# Office-Dokumente im Browser bearbeiten (Collabora Online, WOPI). Wirkt nur mit FEATURE_FILES=true.
FEATURE_OFFICE=false
# Adresse, unter der der Browser Collabora erreicht (HTTPS).
# OFFICE_SERVER_URL=https://office.example.org
# Adresse, unter der ChorManager die Discovery holt. Leer: OFFICE_SERVER_URL.
# OFFICE_SERVER_INTERNAL_URL=
# Adresse, unter der Collabora ChorManager erreicht. Leer: APP_URL.
# OFFICE_WOPI_BASE_URL=
# Lokal mit DDEV: siehe .ddev/docker-compose.collabora.yaml
```

Gibt es einen Test, der `.env.example` gegen die gelesenen Variablen prüft (`grep -rln "env.example" tests`), muss er grün bleiben.

- [ ] **Step 5: Tests laufen lassen**

Run: `WT php vendor/bin/phpunit --filter "DevSeed" | tail -8`
Expected: PASS.

- [ ] **Step 6: LF normalisieren und committen**

```bash
git add .ddev/docker-compose.collabora.yaml .env.example src/Services/DevSeedAttachmentFixtures.php src/Services/DevSeedService.php tests/Feature/DevSeedOfficeDocumentsFeatureTest.php
git commit -m "chore(office): Collabora als optionaler DDEV-Dienst, Office-Dateien im Seed

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Abschluss – Qualitätsgates und volle Suite

**Files:** keine neuen; nur Korrekturen aus den Prüfungen.

- [ ] **Step 1: PHP-Stil**

Run: `WT composer phpcs`
Expected: keine Befunde. Sonst `WT composer phpcbf`, Rest von Hand, erneut prüfen.

- [ ] **Step 2: Twig-Stil**

Run: `WT composer twigcs`
Expected: keine Befunde.

- [ ] **Step 3: JS-Tests**

Run: `WT npm run test:js`
Expected: alle grün.

- [ ] **Step 4: Volle Suite, einmal, parallel**

Run: `WT composer test:parallel 2>&1 | tail -15`
Expected: alles grün. Bricht der Lauf mit einer Sperre ab (`TestRunLock`), testet gerade die Sitzung im Hauptverzeichnis auf denselben Datenbanken – kurz warten und wiederholen; nicht mit `ALLOW_NON_TEST_DATABASE` umgehen.

- [ ] **Step 5: Spec und Plan festhalten, Branch zusammenführen**

Skill `/git-commit` laden und seinem Ablauf folgen (Squash, Rebase auf `main`, Fast-Forward, Branch aufräumen, `git push` nur nach ausdrücklichem Ja). Spec und Plan gehören in denselben Commit.

- [ ] **Step 6: Nach dem Merge (im Hauptverzeichnis)**

- `ddev exec vendor/bin/phinx migrate` (bzw. der Weg aus `/phinx-migration`) für die Entwicklungsdatenbank.
- Optional manuell: `ddev restart --profiles=collabora`, `.env` wie im Kopf von `.ddev/docker-compose.collabora.yaml`, Seed neu laden, `Protokoll-Vorlage Vorstandssitzung.odt` im Ordner „Vorstand“ öffnen, ändern, schließen, Versionen prüfen. Kein Playwright-Lauf ohne ausdrückliche Bitte.
