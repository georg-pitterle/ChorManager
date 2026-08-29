<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\EntityAttachmentService;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

trait TestHttpHelpers
{
    protected function makeRequest(
        string $method,
        string $uri,
        array $parsedBody = [],
        array $queryParams = [],
        array $headers = []
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);

        if ($parsedBody !== []) {
            $request = $request->withParsedBody($parsedBody);
        }

        if ($queryParams !== []) {
            $request = $request->withQueryParams($queryParams);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, (string) $value);
        }

        return $request;
    }

    protected function makeResponse(): ResponseInterface
    {
        return new Response();
    }

    protected function assertRedirect(ResponseInterface $response, string $location): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($location, $response->getHeaderLine('Location'));
    }

    /**
     * Anhang-Dienst für Controller, deren Prüfung nichts mit Uploads zu tun
     * hat. Wer die Protokollzeile einer abgelehnten Datei braucht, baut den
     * Dienst mit dem eigenen Logger aus logger().
     */
    protected function attachmentService(): EntityAttachmentService
    {
        return new EntityAttachmentService(new NullLogger());
    }

    /**
     * @return array{0: Logger, 1: TestHandler}
     */
    protected function logger(): array
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        return [$logger, $handler];
    }

    protected function recordFor(TestHandler $handler, string $event): ?\Monolog\LogRecord
    {
        foreach ($handler->getRecords() as $record) {
            if (($record->context['event'] ?? null) === $event) {
                return $record;
            }
        }

        return null;
    }

    protected function hasEvent(TestHandler $handler, string $event): bool
    {
        return $this->recordFor($handler, $event) !== null;
    }
}
