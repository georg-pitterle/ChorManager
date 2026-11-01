<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\NotificationReminderMiddleware;
use App\Middleware\OpportunisticReminderMiddleware;
use App\Models\AppSetting;
use App\Util\AfterResponse;
use Closure;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;
use App\Middleware\RegistrationReminderMiddleware;
use Tests\Unit\Bootstrap;

/**
 * Die beiden Erinnerungs-Middlewares waren Zeile für Zeile dieselbe Klasse -
 * verschieden waren nur der Merker-Schlüssel und der Name des Log-Ereignisses.
 * Der gemeinsame Ablauf steht jetzt einmal in der Basisklasse; die beiden
 * Ableitungen sagen nur noch, wofür sie zuständig sind.
 */
final class OpportunisticReminderMiddlewareFeatureTest extends TestCase
{
    /** @var list<string> */
    private array $markerKeys = [
        'registration_reminder_last_check_at',
        'notification_reminder_last_check_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        AppSetting::query()->whereIn('setting_key', $this->markerKeys)->delete();
        AppSetting::query()->where('setting_key', 'mailqueue_trigger_mode')->delete();
    }

    protected function tearDown(): void
    {
        AppSetting::query()->whereIn('setting_key', $this->markerKeys)->delete();

        parent::tearDown();
    }

    public function testBothMiddlewaresShareTheSameBaseClass(): void
    {
        $this->assertInstanceOf(
            OpportunisticReminderMiddleware::class,
            new RegistrationReminderMiddleware($this->neverCalledFactory(), new Logger('test'), $this->afterResponse())
        );
        $this->assertInstanceOf(
            OpportunisticReminderMiddleware::class,
            new NotificationReminderMiddleware($this->neverCalledFactory(), new Logger('test'), $this->afterResponse())
        );
        $this->assertInstanceOf(
            MiddlewareInterface::class,
            new RegistrationReminderMiddleware($this->neverCalledFactory(), new Logger('test'), $this->afterResponse())
        );
    }

    /**
     * @return iterable<string, array{class-string<OpportunisticReminderMiddleware>, string, string}>
     */
    public static function reminderProvider(): iterable
    {
        yield 'Anmelde-Erinnerung' => [
            RegistrationReminderMiddleware::class,
            'registration_reminder_last_check_at',
            'registration_reminder.opportunistic.failed',
        ];

        yield 'Benachrichtigungs-Erinnerung' => [
            NotificationReminderMiddleware::class,
            'notification_reminder_last_check_at',
            'notification_reminder.opportunistic.failed',
        ];
    }

    /**
     * @param class-string<OpportunisticReminderMiddleware> $middlewareClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reminderProvider')]
    public function testEachReminderKeepsItsOwnMarkerAndFailureEvent(
        string $middlewareClass,
        string $markerKey,
        string $failureEvent
    ): void {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $afterResponse = $this->afterResponse();
        $middleware = new $middlewareClass(
            static function (): never {
                throw new RuntimeException('Dienst nicht verfügbar');
            },
            $logger,
            $afterResponse
        );

        $response = $middleware->process($this->request(), $this->passthroughHandler());
        $afterResponse->runPending();

        // Ein Fehler in der Nebenbei-Arbeit darf die Anfrage nie mitreißen.
        $this->assertSame(200, $response->getStatusCode());

        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn (\Monolog\LogRecord $record): bool => ($record->context['event'] ?? null) === $failureEvent
        ));
        $this->assertCount(1, $records, 'Erwartetes Ereignis: ' . $failureEvent);
        $this->assertArrayHasKey('exception', $records[0]->context);

        // Der Merker wird vor der Arbeit gesetzt: Bricht sie ab, wartet die
        // nächste Anfrage die volle Wartezeit statt es sofort erneut zu
        // versuchen.
        $this->assertNotNull(
            AppSetting::query()->where('setting_key', $markerKey)->value('setting_value')
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reminderProvider')]
    public function testASecondRequestWithinTheWaitingPeriodDoesNotResolveTheService(
        string $middlewareClass,
        string $markerKey,
        string $failureEvent
    ): void {
        $logger = new Logger('test', [new TestHandler()]);

        $calls = 0;
        $factory = static function () use (&$calls): never {
            $calls++;
            throw new RuntimeException('Dienst nicht verfügbar');
        };

        $afterResponse = $this->afterResponse();
        $middleware = new $middlewareClass($factory, $logger, $afterResponse);
        $middleware->process($this->request(), $this->passthroughHandler());
        $afterResponse->runPending();
        $middleware->process($this->request(), $this->passthroughHandler());
        $afterResponse->runPending();

        $this->assertSame(1, $calls, 'Die Wartezeit aus ' . $markerKey . ' muss den zweiten Lauf bremsen.');
        $this->assertNotSame('', $failureEvent);
    }

    /**
     * Eine Datenbank-Störung (z. B. der db-Container startet beim Ausrollen neu)
     * ist bei der Nebenbei-Arbeit erwartbar und kein Fehler: Sie geht wie bei der
     * Mailqueue als WARNING `….opportunistic.skipped` ins Protokoll.
     *
     * @param class-string<OpportunisticReminderMiddleware> $middlewareClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('skippedProvider')]
    public function testDatabaseOutageIsLoggedAsSkippedWarning(string $middlewareClass, string $skippedEvent): void
    {
        $handler = new TestHandler();
        $afterResponse = $this->afterResponse();
        $middleware = new $middlewareClass(
            static function (): never {
                throw new \Illuminate\Database\QueryException(
                    'default',
                    'select 1',
                    [],
                    new \PDOException('SQLSTATE[HY000] [2002] Connection refused')
                );
            },
            new Logger('test', [$handler]),
            $afterResponse
        );

        $response = $middleware->process($this->request(), $this->passthroughHandler());
        $afterResponse->runPending();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($handler->hasErrorRecords(), 'Eine Datenbank-Störung ist kein ERROR.');
        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn (\Monolog\LogRecord $record): bool => ($record->context['event'] ?? null) === $skippedEvent
        ));
        $this->assertCount(1, $records, 'Erwartetes Ereignis: ' . $skippedEvent);
        $this->assertSame('WARNING', $records[0]->level->getName());
        $this->assertArrayHasKey('exception', $records[0]->context);
    }

    /**
     * @return iterable<string, array{class-string<OpportunisticReminderMiddleware>, string}>
     */
    public static function skippedProvider(): iterable
    {
        yield 'Anmelde-Erinnerung' => [
            RegistrationReminderMiddleware::class,
            'registration_reminder.opportunistic.skipped',
        ];
        yield 'Benachrichtigungs-Erinnerung' => [
            NotificationReminderMiddleware::class,
            'notification_reminder.opportunistic.skipped',
        ];
    }

    /**
     * Eine Warteschlange, die der Test selbst auslöst.
     *
     * Im CLI-Lauf hängt sich `AfterResponse` bewusst nicht ans Prozessende -
     * sonst führte ein PHPUnit-Lauf die gesammelte Arbeit erst bei seinem
     * eigenen Ende aus. Die Tests rufen `runPending()` deshalb von Hand.
     */
    private function afterResponse(): AfterResponse
    {
        return new AfterResponse(static function (): void {
        }, false);
    }

    private function neverCalledFactory(): Closure
    {
        return static function (): never {
            throw new RuntimeException('Der Dienst darf beim Bauen nicht aufgelöst werden.');
        };
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/dashboard');
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new SlimResponse(200);
            }
        };
    }

    /**
     * Die Erinnerung läuft erst, wenn die Antwort beim Browser und die Sitzung
     * freigegeben ist - nicht mehr vor dem Controller.
     *
     * Vorher lag die Arbeit im Anfrageweg: Einmal pro Stunde wartete genau die
     * Person, deren Aufruf den Lauf auslöste, zusätzlich auf Datenbankabfragen
     * über Aufgaben, Wiedervorlagen und Terminanmeldungen und auf das Aufräumen
     * von Versandprotokoll und Glocken-Einträgen. Die Mailwarteschlange hatte
     * denselben Weg aus demselben Grund schon verlassen; hier fehlte er noch.
     *
     * @param class-string<OpportunisticReminderMiddleware> $middlewareClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reminderProvider')]
    public function testReminderWorkRunsOnlyAfterTheResponse(
        string $middlewareClass,
        string $markerKey,
        string $failureEvent
    ): void {
        $calls = [];
        $afterResponse = new AfterResponse(static function (): void {
        }, false);

        $middleware = new $middlewareClass(
            static function () use (&$calls): object {
                $calls[] = 'reminder';

                return new class {
                    public function processDue(string $baseUrl): int
                    {
                        return 0;
                    }
                };
            },
            new Logger('test', [new TestHandler()]),
            $afterResponse
        );

        $handler = new class ($calls) implements RequestHandlerInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls[] = 'handler';

                return new SlimResponse(200);
            }
        };

        $response = $middleware->process($this->request(), $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['handler'], $calls, 'Vor der Antwort darf die Erinnerung nicht laufen.');

        $afterResponse->runPending();

        $this->assertSame(['handler', 'reminder'], $calls);
        $this->assertNotNull(
            AppSetting::query()->where('setting_key', $markerKey)->value('setting_value')
        );
        $this->assertNotSame('', $failureEvent);
    }
}
