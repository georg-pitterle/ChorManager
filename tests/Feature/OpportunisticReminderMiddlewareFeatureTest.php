<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\NotificationReminderMiddleware;
use App\Middleware\OpportunisticReminderMiddleware;
use App\Models\AppSetting;
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
            new RegistrationReminderMiddleware($this->neverCalledFactory(), new Logger('test'))
        );
        $this->assertInstanceOf(
            OpportunisticReminderMiddleware::class,
            new NotificationReminderMiddleware($this->neverCalledFactory(), new Logger('test'))
        );
        $this->assertInstanceOf(
            MiddlewareInterface::class,
            new RegistrationReminderMiddleware($this->neverCalledFactory(), new Logger('test'))
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

        $middleware = new $middlewareClass(
            static function (): never {
                throw new RuntimeException('Dienst nicht verfügbar');
            },
            $logger
        );

        $response = $middleware->process($this->request(), $this->passthroughHandler());

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

        $middleware = new $middlewareClass($factory, $logger);
        $middleware->process($this->request(), $this->passthroughHandler());
        $middleware->process($this->request(), $this->passthroughHandler());

        $this->assertSame(1, $calls, 'Die Wartezeit aus ' . $markerKey . ' muss den zweiten Lauf bremsen.');
        $this->assertNotSame('', $failureEvent);
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
}
