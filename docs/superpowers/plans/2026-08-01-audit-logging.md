# Audit-Logging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sicherheitsrelevante Vorgänge werden dauerhaft protokolliert, das Log-Level ist zur Laufzeit über `/settings` umschaltbar, und schreibende SQL-Statements lassen sich separat zuschalten.

**Architecture:** Der Monolog-Logger wird fest auf `debug` gebaut; ein vorgeschalteter Gate-Handler entscheidet pro Record anhand eines Resolvers, der `app_settings` liest. Der Resolver greift erst beim ersten Aufruf auf die Datenbank zu, damit die Konstruktion des Loggers unabhängig von ihr bleibt. Ein Request-Kontext hängt `request_id`, `user_id`, `ip`, `method` und `path` an jeden Record. Ein Capsule-Listener protokolliert Schreib-Statements ohne Bindings.

**Tech Stack:** PHP 8.5, Slim 4, PHP-DI, Monolog 3 (`Level`-Enum, `HandlerInterface`, `ProcessorInterface`), Eloquent/Capsule, PHPUnit 10, Twig.

**Spec:** `docs/superpowers/specs/2026-08-01-audit-logging-design.md`

## Global Constraints

- PSR-3 kennt kein `trace`. Die Stufen sind `debug, info, notice, warning, error, critical, alert, emergency`. Schreib-SQL liegt auf `debug`.
- Der `LogLevelResolver` darf die Datenbank **nicht** im Konstruktor anfassen, nur beim ersten tatsächlichen Zugriff. Er wirft nie, sondern fällt auf `APP_LOG_LEVEL` zurück.
- Schreib-SQL wird nur protokolliert, wenn Level `debug` **und** `log_db_writes` aktiv sind.
- SQL-Bindings werden nie protokolliert. Passwörter, Tokens und Session-IDs erscheinen nirgends im Log, auch nicht gekürzt (`instructions/security-baseline.md`).
- Der DB-Listener braucht einen Re-Entrancy-Guard und muss Statements auf `app_settings` überspringen, sonst ruft er sich über den Resolver selbst auf.
- Jeder Logaufruf trägt einen `event`-Key in Punktnotation (`instructions/logging.md`). `error_log()` ist in `src/` verboten.
- Setting-Schlüssel heißen exakt `log_level` und `log_db_writes`. Werte: Levelname in Großbuchstaben bzw. `"1"`/`"0"`.
- PSR-12, 4 Leerzeichen, weiche Zeilenlänge 120. Für PHP-Änderungen `ddev composer phpcs`, für Twig `ddev composer twigcs`.
- Repo-Textdateien mit LF, Ausnahme `.bat`, `.cmd`, `.ps1`.
- TDD: erst der fehlschlagende Test, dann die Implementierung (`instructions/feature-tests.md`).
- Keine Phinx-Migration. `app_settings` ist eine Key-Value-Tabelle.

## Aufgabenüberblick

| Phase | Task | Deliverable |
|---|---|---|
| 1 Infrastruktur | 1 | `LogLevelResolver` mit Cache und Rückfall |
| | 2 | `RuntimeLevelHandler`, `AppLoggerFactory` umgebaut |
| | 3 | Request-Kontext, Prozessor, Middleware |
| | 4 | Schalter in `/settings`, Seed |
| 2 Events | 5 | Authentifizierungs-Events |
| | 6 | Benutzer- und Rechte-Events inkl. Rollen-Diff |
| | 7 | `authz.denied` und übrige Events |
| 3 SQL | 8 | `DatabaseWriteLogger` |

Die Reihenfolge ist bindend: Task 2 braucht den Resolver aus Task 1, Task 8 ebenfalls. Phase 2 ist erst nach Phase 1 sinnvoll, weil die Events sonst ohne Request-Kontext geschrieben werden.

---

### Task 1: LogLevelResolver

**Files:**
- Create: `src/Logging/LogLevelResolver.php`
- Test: `tests/Unit/Logging/LogLevelResolverTest.php`

**Interfaces:**
- Consumes: nichts
- Produces:
  - `LogLevelResolver::__construct(\Closure $reader, string $fallbackLevel = 'INFO')` — `$reader` ist `fn(): array<string,string>` und liefert die Settings als Key-Value-Paare
  - `LogLevelResolver::level(): \Monolog\Level`
  - `LogLevelResolver::isDbWriteLoggingEnabled(): bool`
  - `LogLevelResolver::reset(): void` — verwirft den Cache, für Tests und langlebige Worker

Die Closure statt einer direkten Modellabfrage ist der Kern: Sie wird erst beim ersten Zugriff aufgerufen, hält die Datenbank aus dem Konstruktor heraus und macht die Klasse ohne Datenbank testbar.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\LogLevelResolver;
use Monolog\Level;
use PHPUnit\Framework\TestCase;

final class LogLevelResolverTest extends TestCase
{
    public function testReturnsLevelFromSettings(): void
    {
        $resolver = new LogLevelResolver(static fn (): array => ['log_level' => 'DEBUG']);

        $this->assertSame(Level::Debug, $resolver->level());
    }

    public function testFallsBackWhenReaderThrows(): void
    {
        $resolver = new LogLevelResolver(
            static function (): array {
                throw new \RuntimeException('database unavailable');
            },
            'WARNING'
        );

        $this->assertSame(Level::Warning, $resolver->level());
    }

    public function testFallsBackOnUnknownLevelName(): void
    {
        $resolver = new LogLevelResolver(static fn (): array => ['log_level' => 'TRACE'], 'NOTICE');

        $this->assertSame(Level::Notice, $resolver->level());
    }

    public function testReadsSettingsOnlyOnce(): void
    {
        $calls = 0;
        $resolver = new LogLevelResolver(static function () use (&$calls): array {
            $calls++;

            return ['log_level' => 'ERROR'];
        });

        $resolver->level();
        $resolver->level();
        $resolver->isDbWriteLoggingEnabled();

        $this->assertSame(1, $calls);
    }

    public function testDoesNotTouchReaderOnConstruction(): void
    {
        $calls = 0;
        new LogLevelResolver(static function () use (&$calls): array {
            $calls++;

            return [];
        });

        $this->assertSame(0, $calls);
    }

    public function testDbWriteLoggingIsOffByDefault(): void
    {
        $resolver = new LogLevelResolver(static fn (): array => []);

        $this->assertFalse($resolver->isDbWriteLoggingEnabled());
    }

    public function testDbWriteLoggingReadsFlag(): void
    {
        $resolver = new LogLevelResolver(static fn (): array => ['log_db_writes' => '1']);

        $this->assertTrue($resolver->isDbWriteLoggingEnabled());
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Logging/LogLevelResolverTest.php`
Expected: FAIL mit `Class "App\Logging\LogLevelResolver" not found`.

- [ ] **Step 3: Implementierung schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Level;

/**
 * Liest das Log-Level und den SQL-Schalter aus den Anwendungseinstellungen.
 *
 * Der Lesezugriff steckt bewusst in einer Closure, die erst beim ersten Bedarf
 * aufgerufen wird: Der Logger wird dadurch nicht von der Datenbank abhaengig und
 * bleibt funktionsfaehig, wenn diese gerade nicht erreichbar ist.
 */
final class LogLevelResolver
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    /**
     * @param \Closure(): array<string, string> $reader
     */
    public function __construct(
        private readonly \Closure $reader,
        private readonly string $fallbackLevel = 'INFO'
    ) {
    }

    public function level(): Level
    {
        $name = $this->settings()['log_level'] ?? $this->fallbackLevel;

        return self::toLevel($name) ?? self::toLevel($this->fallbackLevel) ?? Level::Info;
    }

    public function isDbWriteLoggingEnabled(): bool
    {
        return ($this->settings()['log_db_writes'] ?? '0') === '1';
    }

    public function reset(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        if ($this->cache === null) {
            try {
                $this->cache = ($this->reader)();
            } catch (\Throwable) {
                // Ohne Einstellungen greift der Rueckfallwert. Ein Fehler beim Lesen
                // darf das Logging nie zum Erliegen bringen.
                $this->cache = [];
            }
        }

        return $this->cache;
    }

    private static function toLevel(string $name): ?Level
    {
        try {
            return Level::fromName(strtoupper(trim($name)));
        } catch (\Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Logging/LogLevelResolverTest.php`
Expected: `OK (7 tests, ...)`

- [ ] **Step 5: Zeilenenden normalisieren und committen**

```powershell
foreach ($p in @("d:\Proggen\ChorManager\src\Logging\LogLevelResolver.php","d:\Proggen\ChorManager\tests\Unit\Logging\LogLevelResolverTest.php")) { [System.IO.File]::WriteAllText($p, ((Get-Content $p -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false)) }
```

```bash
git add src/Logging/LogLevelResolver.php tests/Unit/Logging/LogLevelResolverTest.php
git commit -m "feat(logging): LogLevelResolver mit Rueckfall auf die Umgebung"
```

---

### Task 2: RuntimeLevelHandler und AppLoggerFactory

**Files:**
- Create: `src/Logging/RuntimeLevelHandler.php`
- Modify: `src/Logging/AppLoggerFactory.php`
- Test: `tests/Unit/Logging/RuntimeLevelHandlerTest.php`

**Interfaces:**
- Consumes: `LogLevelResolver::level()` aus Task 1
- Produces:
  - `RuntimeLevelHandler::__construct(HandlerInterface $inner, LogLevelResolver $resolver)`
  - `AppLoggerFactory::create(array $settings = [], ?LogLevelResolver $resolver = null): LoggerInterface` — der zweite Parameter ist neu und optional; ohne ihn verhält sich die Factory wie bisher, was die bestehenden Aufrufer und CLI-Kommandos unverändert lässt

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\LogLevelResolver;
use App\Logging\RuntimeLevelHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RuntimeLevelHandlerTest extends TestCase
{
    public function testPassesRecordAtOrAboveConfiguredLevel(): void
    {
        $inner = new TestHandler(Level::Debug);
        $handler = new RuntimeLevelHandler($inner, new LogLevelResolver(
            static fn (): array => ['log_level' => 'INFO']
        ));

        $handler->handle($this->record(Level::Error, 'boom'));

        $this->assertTrue($inner->hasErrorThatContains('boom'));
    }

    public function testDropsRecordBelowConfiguredLevel(): void
    {
        $inner = new TestHandler(Level::Debug);
        $handler = new RuntimeLevelHandler($inner, new LogLevelResolver(
            static fn (): array => ['log_level' => 'INFO']
        ));

        $handler->handle($this->record(Level::Debug, 'noise'));

        $this->assertFalse($inner->hasDebugRecords());
    }

    public function testFollowsResolverWhenLevelIsLowered(): void
    {
        $inner = new TestHandler(Level::Debug);
        $handler = new RuntimeLevelHandler($inner, new LogLevelResolver(
            static fn (): array => ['log_level' => 'DEBUG']
        ));

        $handler->handle($this->record(Level::Debug, 'detail'));

        $this->assertTrue($inner->hasDebugThatContains('detail'));
    }

    private function record(Level $level, string $message): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'chormanager', $level, $message);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Logging/RuntimeLevelHandlerTest.php`
Expected: FAIL mit `Class "App\Logging\RuntimeLevelHandler" not found`.

- [ ] **Step 3: Handler schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\HandlerInterface;
use Monolog\LogRecord;

/**
 * Reicht Records nur durch, wenn der Resolver das aktuelle Level zulaesst.
 *
 * Der umschlossene Handler wird auf der niedrigsten Stufe gebaut; die
 * Entscheidung faellt hier bei jedem Record neu. Dadurch wirkt eine Aenderung der
 * Einstellung sofort, ohne den Logger neu zu bauen.
 */
final class RuntimeLevelHandler implements HandlerInterface
{
    public function __construct(
        private readonly HandlerInterface $inner,
        private readonly LogLevelResolver $resolver
    ) {
    }

    public function isHandling(LogRecord $record): bool
    {
        if ($record->level->value < $this->resolver->level()->value) {
            return false;
        }

        return $this->inner->isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        return $this->inner->handle($record);
    }

    /**
     * @param array<int, LogRecord> $records
     */
    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
```

- [ ] **Step 4: AppLoggerFactory umbauen**

In `src/Logging/AppLoggerFactory.php` die Signatur und den Handler-Aufbau ersetzen.

Suchen:

```php
    public static function create(array $settings = []): LoggerInterface
    {
        $channel = self::stringSetting($settings, 'channel', 'chormanager');
        $stream = self::stringSetting($settings, 'stream', 'php://stderr');
        $service = self::stringSetting($settings, 'service', 'chormanager');
        $environment = self::stringSetting($settings, 'environment', 'production');

        $logger = new Logger($channel);
        $handler = new StreamHandler($stream, self::resolveLevel($settings));

        $formatter = new JsonFormatter();
        $formatter->includeStacktraces(true);
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
```

Ersetzen durch:

```php
    public static function create(array $settings = [], ?LogLevelResolver $resolver = null): LoggerInterface
    {
        $channel = self::stringSetting($settings, 'channel', 'chormanager');
        $stream = self::stringSetting($settings, 'stream', 'php://stderr');
        $service = self::stringSetting($settings, 'service', 'chormanager');
        $environment = self::stringSetting($settings, 'environment', 'production');

        $logger = new Logger($channel);

        // Mit Resolver wird der Stream-Handler auf der niedrigsten Stufe gebaut und
        // die Entscheidung an den Gate-Handler abgegeben, damit eine Aenderung der
        // Einstellung ohne Neustart wirkt. Ohne Resolver bleibt das feste Level aus
        // der Konfiguration bestehen.
        $handler = new StreamHandler(
            $stream,
            $resolver instanceof LogLevelResolver ? Level::Debug : self::resolveLevel($settings)
        );

        $formatter = new JsonFormatter();
        $formatter->includeStacktraces(true);
        $handler->setFormatter($formatter);

        $logger->pushHandler(
            $resolver instanceof LogLevelResolver
                ? new RuntimeLevelHandler($handler, $resolver)
                : $handler
        );
```

- [ ] **Step 5: Beide Testdateien laufen lassen**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Logging`
Expected: alle Tests grün, insbesondere die aus Task 1 unverändert.

- [ ] **Step 5b: Bestehenden Factory-Test prüfen**

`tests/Feature/AppLoggerFactoryFeatureTest.php` prüft die Factory bereits. Der neue optionale Parameter darf ihn nicht brechen — ohne Resolver muss sich `create()` exakt wie vorher verhalten.

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/AppLoggerFactoryFeatureTest.php`
Expected: unverändert grün. Schlägt er fehl, ist der Rückfallpfad ohne Resolver falsch verdrahtet — nicht den Test anpassen, sondern die Factory.

- [ ] **Step 6: Stilprüfung, Normalisierung, Commit**

Run: `ddev composer phpcs`
Bei Beanstandungen: `ddev composer phpcbf`, danach `ddev composer phpcs` erneut.

```bash
git add src/Logging/RuntimeLevelHandler.php src/Logging/AppLoggerFactory.php tests/Unit/Logging/RuntimeLevelHandlerTest.php
git commit -m "feat(logging): Log-Level zur Laufzeit ueber Gate-Handler steuern"
```

---

### Task 3: Request-Kontext

**Files:**
- Create: `src/Logging/RequestContext.php`
- Create: `src/Logging/RequestContextProcessor.php`
- Create: `src/Middleware/RequestContextMiddleware.php`
- Modify: `src/Logging/AppLoggerFactory.php` (Prozessor registrieren)
- Modify: `src/Middleware.php` (Middleware registrieren)
- Test: `tests/Unit/Logging/RequestContextProcessorTest.php`

**Interfaces:**
- Consumes: `AppLoggerFactory::create()` aus Task 2
- Produces:
  - `RequestContext::assign(array $data): void`, `RequestContext::setUserId(?int $userId): void`, `RequestContext::all(): array<string, scalar>`
  - `RequestContextProcessor::__construct(RequestContext $context)` — implementiert `Monolog\Processor\ProcessorInterface`
  - `AppLoggerFactory::create(array $settings = [], ?LogLevelResolver $resolver = null, ?RequestContext $context = null)`

Der Kontext ist ein veränderliches Singleton im Container. Die Middleware füllt ihn zu Beginn des Requests, der Prozessor liest ihn bei jedem Record.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\RequestContext;
use App\Logging\RequestContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RequestContextProcessorTest extends TestCase
{
    public function testAddsContextToExtra(): void
    {
        $context = new RequestContext();
        $context->assign([
            'request_id' => 'abc123',
            'method' => 'POST',
            'path' => '/login',
            'ip' => '203.0.113.7',
        ]);
        $context->setUserId(42);

        $processor = new RequestContextProcessor($context);
        $record = $processor($this->record());

        $this->assertSame('abc123', $record->extra['request_id']);
        $this->assertSame('POST', $record->extra['method']);
        $this->assertSame('/login', $record->extra['path']);
        $this->assertSame('203.0.113.7', $record->extra['ip']);
        $this->assertSame(42, $record->extra['user_id']);
    }

    public function testLeavesRecordUntouchedWhenContextIsEmpty(): void
    {
        $processor = new RequestContextProcessor(new RequestContext());
        $record = $processor($this->record());

        $this->assertSame([], $record->extra);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'chormanager', Level::Info, 'test');
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Logging/RequestContextProcessorTest.php`
Expected: FAIL mit `Class "App\Logging\RequestContext" not found`.

- [ ] **Step 3: Kontext und Prozessor schreiben**

`src/Logging/RequestContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Logging;

/**
 * Haelt die Kenndaten des laufenden Requests fuer das Logging.
 *
 * Eine Instanz pro Request, veraenderlich: Die Middleware fuellt sie zu Beginn,
 * die Benutzerkennung kommt erst nach der Authentifizierung dazu.
 */
final class RequestContext
{
    /** @var array<string, scalar> */
    private array $data = [];

    /**
     * @param array<string, scalar> $data
     */
    public function assign(array $data): void
    {
        $this->data = array_merge($this->data, $data);
    }

    public function setUserId(?int $userId): void
    {
        if ($userId === null) {
            unset($this->data['user_id']);

            return;
        }

        $this->data['user_id'] = $userId;
    }

    /**
     * @return array<string, scalar>
     */
    public function all(): array
    {
        return $this->data;
    }
}
```

`src/Logging/RequestContextProcessor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Haengt die Kenndaten des Requests an jeden Record.
 *
 * Damit lassen sich alle Zeilen eines Aufrufs ueber die request_id zusammenfuehren,
 * was bei Fehlermeldungen aus der Testphase mehr wert ist als jedes Einzelevent.
 */
final class RequestContextProcessor implements ProcessorInterface
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $data = $this->context->all();

        if ($data === []) {
            return $record;
        }

        return $record->with(extra: array_merge($record->extra, $data));
    }
}
```

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Logging/RequestContextProcessorTest.php`
Expected: `OK (2 tests, ...)`

- [ ] **Step 5: Middleware schreiben**

`src/Middleware/RequestContextMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Logging\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Erzeugt je Request eine Kennung und legt sie mit Methode, Pfad und IP im
 * Logging-Kontext ab.
 */
final class RequestContextMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $serverParams = $request->getServerParams();
        $userId = $_SESSION['user_id'] ?? null;

        $this->context->assign([
            'request_id' => bin2hex(random_bytes(8)),
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'ip' => (string) ($serverParams['REMOTE_ADDR'] ?? ''),
        ]);
        $this->context->setUserId(is_numeric($userId) ? (int) $userId : null);

        return $handler->handle($request);
    }
}
```

- [ ] **Step 6: Prozessor in der Factory registrieren**

In `src/Logging/AppLoggerFactory.php` die Signatur um den Kontext erweitern und den Prozessor anhängen.

Suchen:

```php
    public static function create(array $settings = [], ?LogLevelResolver $resolver = null): LoggerInterface
```

Ersetzen durch:

```php
    public static function create(
        array $settings = [],
        ?LogLevelResolver $resolver = null,
        ?RequestContext $context = null
    ): LoggerInterface
```

Und nach dem bestehenden `$logger->pushProcessor(...)`-Block ergänzen:

```php
        if ($context instanceof RequestContext) {
            $logger->pushProcessor(new RequestContextProcessor($context));
        }
```

- [ ] **Step 7: Middleware registrieren**

In `src/Middleware.php` suchen:

```php
    $app->add(HtmlFormCsrfInjectorMiddleware::class);
```

Ersetzen durch:

```php
    // Zuletzt hinzugefuegt heisst zuerst ausgefuehrt: Der Request-Kontext steht
    // damit allen nachfolgenden Middlewares und Controllern zur Verfuegung.
    $app->add(HtmlFormCsrfInjectorMiddleware::class);
```

und am Ende der Datei, nach `$app->add(SecurityHeadersMiddleware::class);`, ergänzen:

```php
    $app->add(RequestContextMiddleware::class);
```

Dazu oben den Import ergänzen, nach `use App\Middleware\MailQueueProcessingMiddleware;`:

```php
use App\Middleware\RequestContextMiddleware;
```

- [ ] **Step 8: Verdrahtung im Container**

In `src/Dependencies.php` innerhalb von `addDefinitions([...])` ergänzen, direkt vor der `LoggerInterface::class`-Definition:

```php
        RequestContext::class => \DI\create(RequestContext::class),
        LogLevelResolver::class => function (ContainerInterface $c): LogLevelResolver {
            $settings = $c->get('settings');
            $fallback = is_array($settings['logging'] ?? null)
                ? (string) ($settings['logging']['level'] ?? 'INFO')
                : 'INFO';

            // Der Container wird hier absichtlich nur in der Closure benutzt: Die
            // Datenbank wird erst beim ersten Logaufruf angefasst, nicht beim Bau
            // des Loggers.
            return new LogLevelResolver(
                static function () use ($c): array {
                    $c->get(Capsule::class);

                    return AppSetting::query()
                        ->whereIn('setting_key', ['log_level', 'log_db_writes'])
                        ->pluck('setting_value', 'setting_key')
                        ->map(static fn ($value): string => (string) $value)
                        ->toArray();
                },
                $fallback
            );
        },
```

und die `LoggerInterface`-Definition ersetzen durch:

```php
        LoggerInterface::class => function (ContainerInterface $c): LoggerInterface {
            $settings = $c->get('settings');
            $loggingSettings = is_array($settings['logging'] ?? null) ? $settings['logging'] : [];

            return AppLoggerFactory::create(
                $loggingSettings,
                $c->get(LogLevelResolver::class),
                $c->get(RequestContext::class)
            );
        },
```

Dazu die Importe ergänzen:

```php
use App\Logging\LogLevelResolver;
use App\Logging\RequestContext;
use App\Models\AppSetting;
```

- [ ] **Step 9: Gesamte Suite laufen lassen**

Run: `ddev composer test`
Expected: `OK (1032 tests, ...)` plus die neuen Tests, keine Fehler. Schlägt etwas fehl, ist meist die Middleware-Reihenfolge schuld — der Kontext muss vor `CsrfMiddleware` greifen.

- [ ] **Step 10: Stilprüfung, Normalisierung, Commit**

Run: `ddev composer phpcs`

```bash
git add src/Logging src/Middleware/RequestContextMiddleware.php src/Middleware.php src/Dependencies.php tests/Unit/Logging
git commit -m "feat(logging): Request-Kontext an jeden Logeintrag haengen"
```

---

### Task 4: Schalter in /settings und Seed

**Files:**
- Modify: `src/Controllers/AppSettingController.php`
- Modify: `templates/settings/index.twig`
- Modify: `src/Services/DevSeedService.php`
- Test: `tests/Unit/Controllers/AppSettingLogSettingsTest.php`

**Interfaces:**
- Consumes: die Schlüssel `log_level` und `log_db_writes`, die `LogLevelResolver` aus Task 1 liest
- Produces: die beiden Zeilen in `app_settings`

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\AppSettingController;
use PHPUnit\Framework\TestCase;

final class AppSettingLogSettingsTest extends TestCase
{
    public function testNormalizesKnownLevel(): void
    {
        $this->assertSame('DEBUG', AppSettingController::normalizeLogLevel('debug'));
    }

    public function testFallsBackToInfoOnUnknownLevel(): void
    {
        $this->assertSame('INFO', AppSettingController::normalizeLogLevel('trace'));
    }

    public function testFallsBackToInfoOnNull(): void
    {
        $this->assertSame('INFO', AppSettingController::normalizeLogLevel(null));
    }

    public function testNormalizesCheckboxToFlag(): void
    {
        $this->assertSame('1', AppSettingController::normalizeBooleanFlag('on'));
        $this->assertSame('0', AppSettingController::normalizeBooleanFlag(null));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Controllers/AppSettingLogSettingsTest.php`
Expected: FAIL mit `Call to undefined method App\Controllers\AppSettingController::normalizeLogLevel()`.

- [ ] **Step 3: Normalisierer ergänzen**

In `src/Controllers/AppSettingController.php` bei den übrigen `normalize*`-Methoden ergänzen:

```php
    public const LOG_LEVELS = [
        'DEBUG',
        'INFO',
        'NOTICE',
        'WARNING',
        'ERROR',
        'CRITICAL',
        'ALERT',
        'EMERGENCY',
    ];

    public static function normalizeLogLevel(?string $value): string
    {
        $candidate = strtoupper(trim((string) $value));

        return in_array($candidate, self::LOG_LEVELS, true) ? $candidate : 'INFO';
    }

    public static function normalizeBooleanFlag(?string $value): string
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'true', 'yes'], true) ? '1' : '0';
    }
```

- [ ] **Step 4: Werte in `save()` übernehmen**

In `src/Controllers/AppSettingController::save()` nach der Zeile

```php
        $nameDisplayFormat = NameFormatterService::normalizeFormat($data['name_display_format'] ?? null);
```

ergänzen:

```php
        $logLevel = self::normalizeLogLevel($data['log_level'] ?? null);
        $logDbWrites = self::normalizeBooleanFlag($data['log_db_writes'] ?? null);
```

und im `try`-Block nach dem letzten bestehenden `AppSetting::updateOrCreate(...)` ergänzen:

```php
            AppSetting::updateOrCreate(
                ['setting_key' => 'log_level'],
                [
                    'setting_value' => $logLevel,
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );

            AppSetting::updateOrCreate(
                ['setting_key' => 'log_db_writes'],
                [
                    'setting_value' => $logDbWrites,
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );
```

- [ ] **Step 5: Bedienelemente in die Vorlage**

In `templates/settings/index.twig` vor dem Absenden-Button (`<button type="submit" class="btn btn-primary">`, derzeit Zeile 201) einfügen:

```twig
                        <hr class="my-4">

                        <h2 class="h5 mb-3">Protokollierung</h2>

                        <div class="mb-3">
                            <label for="log_level" class="form-label">Log-Level</label>
                            <select class="form-select"
                                    id="log_level"
                                    name="log_level">
                                {% for level in log_levels %}
                                    <option value="{{ level }}"
                                            {{ settings.log_level|default("INFO") == level ? "selected" : "" }}>
                                        {{ level }}
                                    </option>
                                {% endfor %}
                            </select>
                            <div class="form-text">
                                Wirkt sofort, ohne Neustart. Höhere Ausführlichkeit erzeugt deutlich mehr Daten.
                            </div>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input"
                                   type="checkbox"
                                   id="log_db_writes"
                                   name="log_db_writes"
                                   value="1"
                                   {{ settings.log_db_writes|default("0") == "1" ? "checked" : "" }}>
                            <label class="form-check-label" for="log_db_writes">
                                SQL-Schreiboperationen protokollieren
                            </label>
                            <div class="form-text">
                                Wirkt nur bei Log-Level DEBUG. Parameter werden nie protokolliert.
                            </div>
                        </div>
```

- [ ] **Step 6: Levelliste an die Vorlage übergeben**

In `AppSettingController::index()` das Render-Array um die Liste ergänzen:

```php
            'log_levels' => self::LOG_LEVELS,
```

- [ ] **Step 7: Seed ergänzen**

In `src/Services/DevSeedService.php` bei den übrigen `app_settings`-Einträgen ergänzen:

```php
        AppSetting::updateOrCreate(
            ['setting_key' => 'log_level'],
            ['setting_value' => 'INFO', 'binary_content' => '', 'mime_type' => 'text/plain']
        );

        AppSetting::updateOrCreate(
            ['setting_key' => 'log_db_writes'],
            ['setting_value' => '0', 'binary_content' => '', 'mime_type' => 'text/plain']
        );
```

- [ ] **Step 8: Seed ausführen und Bericht prüfen**

Run: `ddev composer seed:dev`
Expected: Durchlauf ohne Fehler. Danach in `/settings` prüfen, dass beide Bedienelemente erscheinen und `INFO` vorausgewählt ist.

- [ ] **Step 9: Tests, Stil, Commit**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Controllers/AppSettingLogSettingsTest.php`
Run: `ddev composer phpcs`
Run: `ddev composer twigcs`

```bash
git add src/Controllers/AppSettingController.php templates/settings/index.twig src/Services/DevSeedService.php tests/Unit/Controllers/AppSettingLogSettingsTest.php
git commit -m "feat(settings): Log-Level und SQL-Schalter in den Stammdaten"
```

---

### Task 5: Authentifizierungs-Events

**Files:**
- Modify: `src/Controllers/AuthController.php`
- Modify: `src/Controllers/PasswordResetController.php`
- Modify: `src/Services/RememberLoginService.php`
- Test: `tests/Feature/AuthLoggingTest.php`

**Interfaces:**
- Consumes: den Logger aus dem Container, Request-Kontext aus Task 3
- Produces: die Events `auth.login.succeeded`, `auth.login.failed`, `auth.login.rate_limited`, `auth.logout`, `auth.password.changed`, `auth.password_reset.requested`, `auth.password_reset.completed`, `auth.remember_me.used`, `auth.remember_me.rejected`

**Kein Passwort, kein Token im Kontext.** Bei `auth.login.failed` wird die eingegebene Mailadresse protokolliert, weil ohne sie kein Fehlversuch zuzuordnen ist; das Passwort niemals, auch nicht als Länge oder Hash.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class AuthLoggingTest extends TestCase
{
    public function testSuccessfulLoginIsLogged(): void
    {
        [$logger, $handler] = $this->logger();

        $controller = $this->makeAuthController($logger);
        $this->submitLogin($controller, 'admin@example.org', 'correct-password');

        $this->assertTrue($this->hasEvent($handler, 'auth.login.succeeded'));
    }

    public function testFailedLoginIsLoggedWithReason(): void
    {
        [$logger, $handler] = $this->logger();

        $controller = $this->makeAuthController($logger);
        $this->submitLogin($controller, 'admin@example.org', 'wrong-password');

        $records = $handler->getRecords();
        $match = array_filter(
            $records,
            static fn ($record): bool => ($record->context['event'] ?? null) === 'auth.login.failed'
        );

        $this->assertNotEmpty($match);
        $this->assertSame('bad_credentials', array_values($match)[0]->context['reason']);
    }

    public function testPasswordIsNeverLogged(): void
    {
        [$logger, $handler] = $this->logger();

        $controller = $this->makeAuthController($logger);
        $this->submitLogin($controller, 'admin@example.org', 'super-secret-value');

        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString('super-secret-value', json_encode($record->context));
        }
    }

    /**
     * @return array{0: Logger, 1: TestHandler}
     */
    private function logger(): array
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        return [$logger, $handler];
    }

    private function hasEvent(TestHandler $handler, string $event): bool
    {
        foreach ($handler->getRecords() as $record) {
            if (($record->context['event'] ?? null) === $event) {
                return true;
            }
        }

        return false;
    }
}
```

Die Hilfsmethoden `makeAuthController()` und `submitLogin()` werden **exakt nach dem Muster von `tests/Feature/AuthFeatureTest.php`** gebaut. Diese Datei vor dem Schreiben des Tests vollständig lesen: Sie richtet bereits Datenbank, Session und den Controller mit seinen Abhängigkeiten ein. Der einzige Unterschied hier ist, dass statt eines `NullLogger` ein `Logger` mit `TestHandler` übergeben wird.

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/AuthLoggingTest.php`
Expected: FAIL — kein Record mit `auth.login.succeeded`.

- [ ] **Step 3: Events im AuthController ergänzen**

In `src/Controllers/AuthController::login()` nach `$this->rateLimiterService->reset('auth:login:' . $clientIp);` ergänzen:

```php
            $this->logger->info('User signed in.', [
                'event' => 'auth.login.succeeded',
                'user_id' => (int) $user->id,
                'remember_me' => $remember,
            ]);
```

Vor dem abschließenden `$_SESSION['error'] = 'Ungültige E-Mail-Adresse oder Passwort.';` am Ende der Methode ergänzen:

```php
        $this->logger->info('Sign-in attempt rejected.', [
            'event' => 'auth.login.failed',
            'reason' => $user === null ? 'unknown_user' : 'bad_credentials',
            'email' => $email,
        ]);
```

Im Rate-Limit-Zweig, vor dem `return`, ergänzen:

```php
            $this->logger->info('Sign-in blocked by rate limit.', [
                'event' => 'auth.login.rate_limited',
            ]);
```

In `logout()` vor dem `return` ergänzen:

```php
        $this->logger->info('User signed out.', [
            'event' => 'auth.logout',
        ]);
```

Die IP steht nicht im Kontext der Events: Sie kommt bereits über den Request-Kontext aus Task 3 an jeden Record.

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/AuthLoggingTest.php`
Expected: `OK (3 tests, ...)`

- [ ] **Step 5: Passwort- und Remember-Events ergänzen**

`PasswordResetController` — beim Anfordern:

```php
        $this->logger->info('Password reset requested.', [
            'event' => 'auth.password_reset.requested',
            'email' => $email,
        ]);
```

und nach erfolgreichem Setzen:

```php
        $this->logger->info('Password reset completed.', [
            'event' => 'auth.password_reset.completed',
            'user_id' => (int) $user->id,
        ]);
```

`ProfileController` beim Ändern des eigenen Passworts:

```php
        $this->logger->info('Password changed.', [
            'event' => 'auth.password.changed',
            'user_id' => (int) $user->id,
        ]);
```

`RememberLoginService` beim erfolgreichen Einlösen eines Tokens:

```php
        $this->logger->info('Remember-me token accepted.', [
            'event' => 'auth.remember_me.used',
            'user_id' => $userId,
        ]);
```

und beim Ablehnen:

```php
        $this->logger->info('Remember-me token rejected.', [
            'event' => 'auth.remember_me.rejected',
            'reason' => $reason,
        ]);
```

Der Tokenwert selbst wird in keinem der beiden Fälle protokolliert.

- [ ] **Step 6: Gesamte Suite, Stil, Commit**

Run: `ddev composer test`
Run: `ddev composer phpcs`

```bash
git add src/Controllers/AuthController.php src/Controllers/PasswordResetController.php src/Controllers/ProfileController.php src/Services/RememberLoginService.php tests/Feature/AuthLoggingTest.php
git commit -m "feat(logging): Anmeldung, Abmeldung und Passwortvorgaenge protokollieren"
```

---

### Task 6: Benutzer- und Rechte-Events

**Files:**
- Modify: `src/Controllers/UserController.php`
- Modify: `src/Controllers/RoleController.php`
- Test: `tests/Feature/RoleChangeLoggingTest.php`

**Interfaces:**
- Consumes: Logger und Request-Kontext
- Produces: `user.created`, `user.activated`, `user.deactivated`, `user.deleted`, `user.email.changed`, `user.role.assigned`, `user.role.revoked`, `role.created`, `role.updated`, `role.deleted`

Der Kern dieser Aufgabe ist der **Diff der `can_*`-Flags** bei `role.updated`. Ohne ihn beantwortet das Event die einzige Frage nicht, für die man es liest.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use PHPUnit\Framework\TestCase;

final class RoleChangeLoggingTest extends TestCase
{
    public function testPermissionDiffListsGrantedAndRevoked(): void
    {
        $before = [
            'can_manage_users' => true,
            'can_manage_roles' => false,
            'can_manage_events' => true,
        ];
        $after = [
            'can_manage_users' => true,
            'can_manage_roles' => true,
            'can_manage_events' => false,
        ];

        $diff = RoleController::permissionDiff($before, $after);

        $this->assertSame(['can_manage_roles'], $diff['granted']);
        $this->assertSame(['can_manage_events'], $diff['revoked']);
    }

    public function testUnchangedPermissionsProduceEmptyDiff(): void
    {
        $flags = ['can_manage_users' => true, 'can_manage_roles' => false];

        $diff = RoleController::permissionDiff($flags, $flags);

        $this->assertSame([], $diff['granted']);
        $this->assertSame([], $diff['revoked']);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/RoleChangeLoggingTest.php`
Expected: FAIL mit `Call to undefined method App\Controllers\RoleController::permissionDiff()`.

- [ ] **Step 3: Diff-Helfer implementieren**

In `src/Controllers/RoleController.php`:

```php
    /**
     * @param array<string, bool> $before
     * @param array<string, bool> $after
     * @return array{granted: list<string>, revoked: list<string>}
     */
    public static function permissionDiff(array $before, array $after): array
    {
        $granted = [];
        $revoked = [];

        foreach ($after as $permission => $value) {
            $previous = (bool) ($before[$permission] ?? false);

            if ($value && !$previous) {
                $granted[] = $permission;
            }

            if (!$value && $previous) {
                $revoked[] = $permission;
            }
        }

        return ['granted' => $granted, 'revoked' => $revoked];
    }
```

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/RoleChangeLoggingTest.php`
Expected: `OK (2 tests, ...)`

- [ ] **Step 5: Events verdrahten**

In `RoleController::update()` die Flags vor und nach dem Speichern einsammeln und protokollieren:

```php
        $diff = self::permissionDiff($permissionsBefore, $permissionsAfter);

        $this->logger->info('Role updated.', [
            'event' => 'role.updated',
            'role_id' => (int) $role->id,
            'role_name' => $role->name,
            'granted' => $diff['granted'],
            'revoked' => $diff['revoked'],
        ]);
```

In `RoleController::create()` und `delete()` analog `role.created` und `role.deleted` mit `role_id` und `role_name`.

In `UserController` bei der Zuweisung und beim Entzug von Rollen:

```php
        $this->logger->info('Role assigned to user.', [
            'event' => 'user.role.assigned',
            'user_id' => (int) $user->id,
            'role_id' => (int) $roleId,
        ]);
```

```php
        $this->logger->info('Role revoked from user.', [
            'event' => 'user.role.revoked',
            'user_id' => (int) $user->id,
            'role_id' => (int) $roleId,
        ]);
```

Beim Anlegen, Aktivieren, Deaktivieren und Löschen eines Benutzers entsprechend `user.created`, `user.activated`, `user.deactivated`, `user.deleted` jeweils mit `user_id`. Bei einer geänderten Mailadresse zusätzlich:

```php
        $this->logger->info('User email changed.', [
            'event' => 'user.email.changed',
            'user_id' => (int) $user->id,
            'old_email' => $previousEmail,
            'new_email' => $newEmail,
        ]);
```

- [ ] **Step 6: Gesamte Suite, Stil, Commit**

Run: `ddev composer test`
Run: `ddev composer phpcs`

```bash
git add src/Controllers/UserController.php src/Controllers/RoleController.php tests/Feature/RoleChangeLoggingTest.php
git commit -m "feat(logging): Benutzer- und Rechteaenderungen protokollieren"
```

---

### Task 7: authz.denied und übrige Events

**Files:**
- Modify: die Middleware oder der Helfer, der Rechte prüft (vor der Umsetzung mit `grep -rn "can_manage_\|hasPermission" src/Middleware src/Util` bestimmen)
- Modify: `src/Controllers/AppSettingController.php`
- Test: `tests/Feature/AuthorizationLoggingTest.php`

**Interfaces:**
- Consumes: Logger und Request-Kontext
- Produces: `authz.denied`, `settings.updated`

- [ ] **Step 1: Prüfstelle bestimmen**

Run: `ddev exec grep -rn "hasPermission\|can_manage_\|403" src/Middleware src/Util src/Policies | head -20`

Ziel ist die eine Stelle, an der ein fehlendes Recht in eine Abweisung mündet. Gibt es mehrere, wird das Event in der gemeinsam genutzten Prüffunktion gesetzt, nicht an jeder Aufrufstelle.

- [ ] **Step 2: Fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class AuthorizationLoggingTest extends TestCase
{
    public function testDeniedAccessIsLogged(): void
    {
        // Benutzer ohne can_manage_roles ruft /roles auf.
        // Erwartet: ein Record mit event=authz.denied, der die Route und das
        // fehlende Recht nennt.
        $handler = $this->callRouteWithoutPermission('/roles', 'can_manage_roles');

        $records = array_filter(
            $handler->getRecords(),
            static fn ($record): bool => ($record->context['event'] ?? null) === 'authz.denied'
        );

        $this->assertNotEmpty($records);
        $record = array_values($records)[0];
        $this->assertSame('can_manage_roles', $record->context['permission']);
        $this->assertSame('/roles', $record->context['route']);
    }
}
```

Die Hilfsmethode `callRouteWithoutPermission()` folgt dem Muster der bestehenden Feature-Tests.

- [ ] **Step 3: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/AuthorizationLoggingTest.php`
Expected: FAIL — kein Record mit `authz.denied`.

- [ ] **Step 4: Event an der Prüfstelle setzen**

```php
        $this->logger->info('Access denied.', [
            'event' => 'authz.denied',
            'permission' => $permission,
            'route' => $request->getUri()->getPath(),
        ]);
```

Benutzerkennung und IP kommen über den Request-Kontext und werden hier nicht wiederholt.

- [ ] **Step 5: settings.updated ergänzen**

In `AppSettingController::save()` nach dem `try`-Block, vor der Weiterleitung:

```php
        $this->logger->info('Application settings updated.', [
            'event' => 'settings.updated',
            'keys' => array_values(array_intersect(
                array_keys($data),
                ['app_name', 'primary_color', 'name_display_format', 'log_level', 'log_db_writes']
            )),
        ]);
```

Protokolliert werden die geänderten Schlüssel, nicht deren Werte — unter den Einstellungen stehen auch Zugangsdaten.

- [ ] **Step 6: Restliche INFO-Events aus dem Spec**

Diese vier fehlen sonst, obwohl der Spec sie führt. Aufrufstellen zuvor mit `grep` bestimmen.

`mail.credentials.changed` beim Speichern der Mail-Zugangsdaten — ohne jeden Wert, nur die Tatsache:

```php
        $this->logger->info('Mail credentials changed.', [
            'event' => 'mail.credentials.changed',
        ]);
```

`export.generated` an den Stellen, die eine Datei zum Download erzeugen (Mitgliederliste, Finanzen):

```php
        $this->logger->info('Export generated.', [
            'event' => 'export.generated',
            'kind' => $kind,
            'row_count' => $rowCount,
        ]);
```

`invitation.created` und `invitation.consumed` im Einladungs- beziehungsweise Registrierungspfad, jeweils mit `user_id` beziehungsweise `email`, niemals mit dem Token.

- [ ] **Step 7: WARNING-Events**

```php
        $this->logger->warning('CSRF token rejected.', [
            'event' => 'security.csrf.rejected',
        ]);
```

in `CsrfMiddleware` beim Abweisen, und

```php
        $this->logger->warning('File upload rejected.', [
            'event' => 'security.upload.rejected',
            'reason' => $reason,
        ]);
```

an der Stelle, die Uploads nach Typ oder Größe ablehnt. Der Dateiname wird nicht protokolliert, der Ablehnungsgrund schon.

- [ ] **Step 8: Gesamte Suite, Stil, Commit**

Run: `ddev composer test`
Run: `ddev composer phpcs`

```bash
git add src tests/Feature/AuthorizationLoggingTest.php
git commit -m "feat(logging): abgewiesene Zugriffe, Exporte und Einstellungsaenderungen protokollieren"
```

---

### Task 8: DatabaseWriteLogger

**Files:**
- Create: `src/Logging/DatabaseWriteLogger.php`
- Modify: `src/Dependencies.php`
- Test: `tests/Unit/Logging/DatabaseWriteLoggerTest.php`

**Interfaces:**
- Consumes: `LogLevelResolver::isDbWriteLoggingEnabled()` aus Task 1
- Produces:
  - `DatabaseWriteLogger::__construct(LoggerInterface $logger, LogLevelResolver $resolver)`
  - `DatabaseWriteLogger::handle(string $sql, float $timeMs): void`
  - `DatabaseWriteLogger::register(Capsule $capsule): void`

`handle()` ist absichtlich öffentlich und nimmt einfache Werte statt eines `QueryExecuted`-Objekts entgegen: Damit ist die Filterlogik ohne Datenbank testbar.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\DatabaseWriteLogger;
use App\Logging\LogLevelResolver;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class DatabaseWriteLoggerTest extends TestCase
{
    public function testLogsInsertWhenEnabled(): void
    {
        [$logger, $handler] = $this->logger();
        $writeLogger = new DatabaseWriteLogger($logger, $this->resolver(true));

        $writeLogger->handle('insert into `users` (`email`) values (?)', 1.5);

        $this->assertTrue($handler->hasDebugRecords());
        $record = $handler->getRecords()[0];
        $this->assertSame('db.write', $record->context['event']);
        $this->assertSame('users', $record->context['table']);
    }

    public function testIgnoresSelect(): void
    {
        [$logger, $handler] = $this->logger();
        $writeLogger = new DatabaseWriteLogger($logger, $this->resolver(true));

        $writeLogger->handle('select * from `users` where `id` = ?', 0.4);

        $this->assertSame([], $handler->getRecords());
    }

    public function testIgnoresEverythingWhenDisabled(): void
    {
        [$logger, $handler] = $this->logger();
        $writeLogger = new DatabaseWriteLogger($logger, $this->resolver(false));

        $writeLogger->handle('insert into `users` (`email`) values (?)', 1.5);

        $this->assertSame([], $handler->getRecords());
    }

    public function testIgnoresAppSettingsToAvoidRecursion(): void
    {
        [$logger, $handler] = $this->logger();
        $writeLogger = new DatabaseWriteLogger($logger, $this->resolver(true));

        $writeLogger->handle('update `app_settings` set `setting_value` = ?', 0.9);

        $this->assertSame([], $handler->getRecords());
    }

    public function testNeverLogsBindings(): void
    {
        [$logger, $handler] = $this->logger();
        $writeLogger = new DatabaseWriteLogger($logger, $this->resolver(true));

        $writeLogger->handle('insert into `users` (`password`) values (?)', 1.0);

        $encoded = json_encode($handler->getRecords()[0]->context);
        $this->assertStringNotContainsString('bindings', (string) $encoded);
    }

    private function resolver(bool $enabled): LogLevelResolver
    {
        return new LogLevelResolver(static fn (): array => ['log_db_writes' => $enabled ? '1' : '0']);
    }

    /**
     * @return array{0: Logger, 1: TestHandler}
     */
    private function logger(): array
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        return [$logger, $handler];
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Logging/DatabaseWriteLoggerTest.php`
Expected: FAIL mit `Class "App\Logging\DatabaseWriteLogger" not found`.

- [ ] **Step 3: Implementierung schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\QueryExecuted;
use Psr\Log\LoggerInterface;

/**
 * Protokolliert schreibende SQL-Statements, wenn der Schalter in den
 * Einstellungen aktiv ist.
 *
 * Bindings werden bewusst verworfen: Sie enthalten Passwort-Hashes und
 * verschluesselte Zugangsdaten. Statements auf app_settings werden uebersprungen,
 * weil der Resolver seine Werte von dort liest und die Protokollierung sich sonst
 * selbst aufruft.
 */
final class DatabaseWriteLogger
{
    private const WRITE_STATEMENT = '/^(insert|update|delete|replace|truncate|create|alter|drop)\b/i';

    private bool $handling = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LogLevelResolver $resolver
    ) {
    }

    public function register(Capsule $capsule): void
    {
        $capsule->getConnection()->listen(function (QueryExecuted $query): void {
            $this->handle($query->sql, (float) $query->time);
        });
    }

    public function handle(string $sql, float $timeMs): void
    {
        if ($this->handling) {
            return;
        }

        $statement = ltrim($sql);

        if (preg_match(self::WRITE_STATEMENT, $statement) !== 1) {
            return;
        }

        if (stripos($statement, 'app_settings') !== false) {
            return;
        }

        if (!$this->resolver->isDbWriteLoggingEnabled()) {
            return;
        }

        $this->handling = true;

        try {
            $this->logger->debug('Database write executed.', [
                'event' => 'db.write',
                'statement' => $statement,
                'table' => self::tableFromStatement($statement),
                'duration_ms' => $timeMs,
            ]);
        } finally {
            $this->handling = false;
        }
    }

    private static function tableFromStatement(string $statement): ?string
    {
        if (preg_match('/(?:into|update|from|table)\s+[`"]?([a-z0-9_]+)[`"]?/i', $statement, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
```

Die Reihenfolge der Prüfungen ist beabsichtigt: Der Guard und die billigen Textprüfungen laufen vor `isDbWriteLoggingEnabled()`, weil dieser Aufruf beim ersten Mal eine Datenbankabfrage auslöst.

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `ddev exec ./vendor/bin/phpunit tests/Unit/Logging/DatabaseWriteLoggerTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: Im Container registrieren**

In `src/Dependencies.php` die `Capsule`-Definition ergänzen; nach `$capsule->bootEloquent();` und vor `return $capsule;`:

```php
            $c->get(DatabaseWriteLogger::class)->register($capsule);
```

und die Definition ergänzen:

```php
        DatabaseWriteLogger::class => function (ContainerInterface $c): DatabaseWriteLogger {
            return new DatabaseWriteLogger(
                $c->get(LoggerInterface::class),
                $c->get(LogLevelResolver::class)
            );
        },
```

Dazu den Import `use App\Logging\DatabaseWriteLogger;`.

- [ ] **Step 6: Ende-zu-Ende prüfen**

In `/settings` Level auf `DEBUG` stellen und den SQL-Schalter aktivieren. Dann eine schreibende Aktion auslösen, etwa einen Benutzer speichern, und prüfen:

```bash
ddev logs -s web | grep db.write
```

Expected: mindestens eine JSON-Zeile mit `"event":"db.write"`, darin `table` und `duration_ms`, **ohne** Parameterwerte. Danach den Schalter wieder ausschalten und prüfen, dass keine weiteren Zeilen erscheinen.

- [ ] **Step 7: Gesamte Suite, Stil, Commit**

Run: `ddev composer test`
Run: `ddev composer phpcs`

```bash
git add src/Logging/DatabaseWriteLogger.php src/Dependencies.php tests/Unit/Logging/DatabaseWriteLoggerTest.php
git commit -m "feat(logging): schreibende SQL-Statements optional protokollieren"
```

---

## Definition of Done

- [ ] `ddev composer test` grün, inklusive der neuen Unit- und Feature-Tests
- [ ] `ddev composer phpcs` und `ddev composer twigcs` ohne Beanstandung
- [ ] `/settings` zeigt Log-Level und SQL-Schalter, beide wirken ohne Neustart
- [ ] `ddev composer seed:dev` läuft und legt beide Schlüssel an
- [ ] Eine Anmeldung erzeugt `auth.login.succeeded`, ein Fehlversuch `auth.login.failed` mit Grund
- [ ] Eine Rechteänderung erzeugt `role.updated` mit `granted` und `revoked`
- [ ] Alle Zeilen eines Requests tragen dieselbe `request_id`
- [ ] Bei aktivem SQL-Schalter erscheinen `db.write`-Zeilen ohne Parameterwerte
- [ ] Kein Passwort, kein Token und keine Session-ID taucht im Log auf
