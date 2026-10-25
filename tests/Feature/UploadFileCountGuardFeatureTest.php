<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\UploadFileCountMiddleware;
use App\Util\UploadFileCountGuard;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Mehr Dateien als `max_file_uploads` in einer Anfrage: PHP wirft den Rest weg und
 * meldet das nur als Warnung beim Start der Anfrage. Ohne Wächter speicherte der
 * Controller stillschweigend die ersten Dateien, und mit eingeschaltetem
 * `display_errors` landete die Warnung samt Folgefehlern im Browser.
 */
class UploadFileCountGuardFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private const STARTUP_WARNING = [
        'type' => E_WARNING,
        'message' => 'PHP Request Startup: Maximum number of allowable file uploads has been exceeded',
        'file' => 'Unknown',
        'line' => 0,
    ];

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
        UploadFileCountGuard::reset();
    }

    protected function tearDown(): void
    {
        UploadFileCountGuard::reset();
    }

    public function testRecognisesTheStartupWarning(): void
    {
        $this->assertTrue(UploadFileCountGuard::isExceededError(self::STARTUP_WARNING));
    }

    public function testIgnoresOtherErrorsAndNone(): void
    {
        $this->assertFalse(UploadFileCountGuard::isExceededError(null));
        $this->assertFalse(UploadFileCountGuard::isExceededError([
            'type' => E_WARNING,
            'message' => 'Undefined variable $x',
            'file' => '/var/www/html/src/x.php',
            'line' => 3,
        ]));
    }

    public function testRecordKeepsTheStateForTheRequest(): void
    {
        UploadFileCountGuard::record(null);
        $this->assertFalse(UploadFileCountGuard::exceeded());

        UploadFileCountGuard::record(self::STARTUP_WARNING);
        $this->assertTrue(UploadFileCountGuard::exceeded());
    }

    public function testPassesThroughWhenNothingWasDropped(): void
    {
        $handler = $this->recordingHandler();
        $middleware = new UploadFileCountMiddleware(new Logger('test'), false);

        $result = $middleware->process($this->makeRequest('POST', '/song-library/1/attachments'), $handler);

        $this->assertSame(204, $result->getStatusCode());
        $this->assertTrue($handler->called);
    }

    public function testFormPostIsRejectedWithFlashAndRedirectBack(): void
    {
        $handler = $this->recordingHandler();
        $logs = new TestHandler();
        $middleware = new UploadFileCountMiddleware(new Logger('test', [$logs]), true);

        $request = $this->makeRequest('POST', 'https://chor.example/song-library/7/attachments', [], [], [
            'Referer' => 'https://chor.example/song-library/7?tab=files',
        ]);
        $result = $middleware->process($request, $handler);

        $this->assertFalse($handler->called, 'Der Controller darf die Rumpf-Auswahl nicht speichern.');
        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/song-library/7?tab=files', $result->getHeaderLine('Location'));
        $this->assertStringContainsString((string) UploadFileCountGuard::MAX_FILES, (string) $_SESSION['error']);
        $this->assertStringContainsString('nichts gespeichert', (string) $_SESSION['error']);

        $this->assertTrue($logs->hasWarningRecords());
        $record = $logs->getRecords()[0];
        $this->assertSame('upload.too_many_files', $record->context['event']);
        $this->assertSame('/song-library/7/attachments', $record->context['path']);
        $this->assertSame(UploadFileCountGuard::MAX_FILES, $record->context['max_files']);
    }

    public function testUnusableRefererFallsBackToStartPage(): void
    {
        $middleware = new UploadFileCountMiddleware(new Logger('test'), true);

        $referers = [
            '',
            'https://chor.example//evil.example/x',
            // Browser lesen den Rückwärtsschrägstrich wie einen Schrägstrich: Aus
            // `/\evil.example` wird im Location-Kopf `//evil.example`, also eine
            // fremde Adresse. Diese Middleware läuft außerhalb der CSRF-Prüfung,
            // ein fremdes Formular erreicht sie also.
            'https://chor.example/\\evil.example/x',
            'https://evil.example/tasks/3',
            'javascript:alert(1)',
        ];

        foreach ($referers as $referer) {
            $headers = $referer === '' ? [] : ['Referer' => $referer];
            $request = $this->makeRequest('POST', 'https://chor.example/tasks/3/attachments', [], [], $headers);
            $result = $middleware->process($request, $this->recordingHandler());

            $this->assertSame('/', $result->getHeaderLine('Location'), 'Referer: ' . $referer);
        }
    }

    public function testJsonCallerGetsA413WithMessage(): void
    {
        $middleware = new UploadFileCountMiddleware(new Logger('test'), true);
        $request = $this->makeRequest('POST', '/files/1/upload', [], [], ['Accept' => 'application/json']);

        $result = $middleware->process($request, $this->recordingHandler());

        $this->assertSame(413, $result->getStatusCode());
        $this->assertStringContainsString('application/json', $result->getHeaderLine('Content-Type'));
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString((string) UploadFileCountGuard::MAX_FILES, $payload['error']);
    }

    public function testMiddlewareIsRegisteredAndIndexRecordsTheStartupErrorFirst(): void
    {
        $root = dirname(__DIR__, 2);
        $middleware = (string) file_get_contents($root . '/src/Middleware.php');
        $index = (string) file_get_contents($root . '/public/index.php');

        $this->assertStringContainsString('$app->add(UploadFileCountMiddleware::class);', $middleware);

        // Vor jeder anderen Anweisung, die selbst eine Warnung erzeugen und
        // error_get_last() überschreiben könnte.
        $record = strpos($index, 'UploadFileCountGuard::record(error_get_last());');
        $this->assertNotFalse($record);
        $this->assertLessThan(strpos($index, 'Dotenv::createImmutable'), $record);
    }

    public function testProductionImageHidesErrorsAndAllowsTheGuardedFileCount(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');

        $this->assertStringContainsString('display_errors = Off', $dockerfile);
        $this->assertStringContainsString('display_startup_errors = Off', $dockerfile);
        $this->assertStringContainsString('log_errors = On', $dockerfile);
        $this->assertStringContainsString('max_file_uploads = ' . UploadFileCountGuard::MAX_FILES, $dockerfile);
    }

    public function testDevelopmentAllowsTheSameFileCount(): void
    {
        $ini = (string) @file_get_contents(dirname(__DIR__, 2) . '/.ddev/php/uploads.ini');

        $this->assertStringContainsString('max_file_uploads = ' . UploadFileCountGuard::MAX_FILES, $ini);
    }

    public function testClientLimitsPublishTheFileCount(): void
    {
        $this->assertSame(
            UploadFileCountGuard::MAX_FILES,
            \App\Util\UploadValidator::clientLimits()['maxFiles']
        );
    }

    private function recordingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public bool $called = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return (new Response())->withStatus(204);
            }
        };
    }
}
