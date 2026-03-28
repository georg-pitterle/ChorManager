<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
