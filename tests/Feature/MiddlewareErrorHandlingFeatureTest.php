<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\RequestContext;
use App\Middleware\MailBadgeRefreshMiddleware;
use App\Middleware\MailQueueProcessingMiddleware;
use App\Middleware\NotificationReminderMiddleware;
use DI\ContainerBuilder;
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

    private function handleWithFailingOpportunisticMiddleware(): ResponseInterface
    {
        AppFactory::setContainer($this->buildContainer());
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
    private function buildContainer(): ContainerInterface
    {
        $failing = new class () implements MiddlewareInterface {
            public function process(Request $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new RuntimeException('Nebenbei-Arbeit fehlgeschlagen');
            }
        };

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            LoggerInterface::class => new NullLogger(),
            'settings' => ['modules' => ['registration' => false]],
            RequestContext::class => new RequestContext(),
            MailBadgeRefreshMiddleware::class => $failing,
            NotificationReminderMiddleware::class => $failing,
            MailQueueProcessingMiddleware::class => $failing,
        ]);

        return $builder->build();
    }
}
