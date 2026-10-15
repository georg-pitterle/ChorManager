<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\RequestContext;
use App\Middleware\MailBadgeRefreshMiddleware;
use App\Middleware\MailQueueProcessingMiddleware;
use App\Middleware\NotificationReminderMiddleware;
use DI\ContainerBuilder;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class MiddlewareErrorHandlingFeatureTest extends TestCase
{
    /**
     * Die Middleware ruft `session_start()` selbst auf. Eine bereits offene Sitzung
     * lässt sie in Ruhe - und nur so bleibt der Aufruf unter PHPUnit frei von der
     * Warnung über bereits gesendete Kopfzeilen.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SESSION = [];
    }

    public function testMiddlewareRegistersDedicatedNotFoundHandlerWithTwigTemplate(): void
    {
        $middlewareConfig = file_get_contents(dirname(__DIR__) . '/../src/Middleware.php');

        $this->assertIsString($middlewareConfig);
        $this->assertStringContainsString('HttpNotFoundException::class', $middlewareConfig);
        $this->assertStringContainsString('Twig::class', $middlewareConfig);
        $this->assertStringContainsString("'errors/404.twig'", $middlewareConfig);
        $this->assertStringContainsString("['requested_path' => \$request->getUri()->getPath()]", $middlewareConfig);
        $this->assertStringContainsString('setErrorHandler(', $middlewareConfig);
        $this->assertStringContainsString(
            'return $defaultErrorHandler($request, $exception, $displayErrorDetails, false, false);',
            $middlewareConfig
        );
    }

    public function testNotFoundTemplateProvidesFriendlyNavigation(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/errors/404.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('{% extends "layout.twig" %}', $template);
        $this->assertStringContainsString('Seite nicht gefunden', $template);
        $this->assertStringContainsString('href="/dashboard"', $template);
        $this->assertStringContainsString('href="/"', $template);
        $this->assertStringContainsString('{{ requested_path|default("/") }}', $template);
    }

    /**
     * Eine Ausnahme aus einer der umschließenden Middlewares darf nicht am
     * Fehler-Handler vorbeilaufen.
     *
     * In Slim heißt zuletzt hinzugefügt zuerst ausgeführt: Der Fehler-Handler fängt
     * nur, was **nach** ihm hinzugefügt wurde. Stand er wie zuvor ganz oben in der
     * Datei, lag er innen - und eine Ausnahme aus der Mailwarteschlange, einer der
     * Erinnerungen, dem Postfach-Zähler, der CsrfMiddleware oder dem
     * Formular-Einschub verließ `$app->handle()` ungefangen. Weder Fehlerseite noch
     * Protokollzeile, sondern ein nackter Abbruch.
     */
    public function testErrorHandlerAlsoCatchesFailuresFromTheOuterMiddlewares(): void
    {
        $response = $this->handleWithFailingOpportunisticMiddleware();

        $this->assertSame(500, $response->getStatusCode());
    }

    /**
     * Umgekehrt bleibt der Fehler-Handler innerhalb der Kopfzeilen-Middleware: Auch
     * eine Fehlerseite trägt CSP und `no-store`, sonst landete ausgerechnet die Seite
     * mit den Fehlerdetails in einem Zwischenspeicher.
     */
    public function testErrorResponseStillCarriesTheSecurityHeaders(): void
    {
        $response = $this->handleWithFailingOpportunisticMiddleware();

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * Eine Route, die nur POST kennt, per GET aufgerufen: Das ist ein Fehler der
     * aufrufenden Seite, kein Absturz. Bisher stand dafür eine ERROR-Zeile
     * "405 Method Not Allowed" mit vollem Stapelverlauf und ohne `event` im
     * Protokoll - aus der Zeile allein war nicht zu erkennen, dass das Postfach
     * aus der installierten App heraus per GET geöffnet wurde.
     */
    public function testMethodNotAllowedIsLoggedAsWarningWithEventAndAllowedMethods(): void
    {
        $handler = new TestHandler();
        AppFactory::setContainer($this->buildContainer($handler, $this->passThroughMiddleware()));
        $app = AppFactory::create();
        (require dirname(__DIR__) . '/../src/Middleware.php')($app);
        $app->post('/only-post', static function (Request $request, ResponseInterface $response): ResponseInterface {
            return $response;
        });

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/only-post')
        );

        $this->assertSame(405, $response->getStatusCode());
        $this->assertFalse($handler->hasRecordThatPasses(
            static fn ($record): bool => $record->level->isHigherThan(Level::Warning),
            Level::Error
        ));
        $record = $this->recordWithEvent($handler, 'http.method_not_allowed');
        $this->assertNotNull($record, 'Der 405 braucht ein eigenes Ereignis.');
        $this->assertSame(Level::Warning, $record->level);
        $this->assertSame(['POST'], $record->context['allowed_methods'] ?? null);
        $this->assertSame(405, $record->context['status'] ?? null);
    }

    /**
     * Ein echter Absturz bleibt ERROR, bekommt aber ein `event` und die Ausnahme
     * im Schlüssel `exception` - so verlangt es der Logging-Standard.
     */
    public function testUnhandledExceptionIsLoggedAsErrorWithEventAndException(): void
    {
        $handler = new TestHandler();
        $response = $this->handleWithFailingOpportunisticMiddleware($handler);

        $this->assertSame(500, $response->getStatusCode());
        $record = $this->recordWithEvent($handler, 'app.unhandled_exception');
        $this->assertNotNull($record);
        $this->assertSame(Level::Error, $record->level);
        $this->assertInstanceOf(RuntimeException::class, $record->context['exception'] ?? null);
    }

    private function recordWithEvent(TestHandler $handler, string $event): ?\Monolog\LogRecord
    {
        foreach ($handler->getRecords() as $record) {
            if (($record->context['event'] ?? null) === $event) {
                return $record;
            }
        }

        return null;
    }

    private function passThroughMiddleware(): MiddlewareInterface
    {
        return new class () implements MiddlewareInterface {
            public function process(Request $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };
    }

    private function handleWithFailingOpportunisticMiddleware(?TestHandler $handler = null): ResponseInterface
    {
        AppFactory::setContainer($this->buildContainer($handler));
        $app = AppFactory::create();

        (require dirname(__DIR__) . '/../src/Middleware.php')($app);
        $app->get('/dashboard', static function (Request $request, ResponseInterface $response): ResponseInterface {
            return $response;
        });

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/dashboard')
        );
    }

    /**
     * Ein Container mit genau dem, was die Registrierung aus `src/Middleware.php`
     * anfasst. An der Stelle der nebenbei laufenden Middlewares - Mailwarteschlange,
     * Erinnerungen, Postfach-Zähler - steht ein Platzhalter, der scheitert: Sie
     * bräuchten sonst Datenbank und Twig, und geprüft wird hier die Reihenfolge der
     * Registrierung, nicht ihre Arbeit.
     */
    private function buildContainer(?TestHandler $handler = null, ?MiddlewareInterface $sideWork = null): ContainerInterface
    {
        $failing = $sideWork ?? new class () implements MiddlewareInterface {
            public function process(Request $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new RuntimeException('Nebenbei-Arbeit fehlgeschlagen');
            }
        };

        $logger = new NullLogger();
        if ($handler !== null) {
            $logger = new Logger('test');
            $logger->pushHandler($handler);
        }

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            LoggerInterface::class => $logger,
            'settings' => ['modules' => ['registration' => false]],
            RequestContext::class => new RequestContext(),
            MailBadgeRefreshMiddleware::class => $failing,
            NotificationReminderMiddleware::class => $failing,
            MailQueueProcessingMiddleware::class => $failing,
        ]);

        return $builder->build();
    }
}
