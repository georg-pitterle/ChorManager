<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\Csrf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\StreamFactory;

class HtmlFormCsrfInjectorMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $response = $handler->handle($request);
        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();
        if ($body === '' || !str_contains(strtolower($body), '<form')) {
            return $response;
        }

        $token = Csrf::ensureToken();
        $updatedBody = preg_replace_callback(
            '/<form\b(?:(?!<\/form>).)*?<\/form>/is',
            static function (array $matches) use ($token): string {
                $formMarkup = $matches[0];

                if (!preg_match('/<form\b[^>]*\bmethod\s*=\s*(["\'])post\1/i', $formMarkup)) {
                    return $formMarkup;
                }

                if (preg_match('/name\s*=\s*(["\'])_csrf\1/i', $formMarkup)) {
                    return $formMarkup;
                }

                return preg_replace(
                    '/(<form\b[^>]*>)/i',
                    '$1<input type="hidden" name="_csrf" value="' . $token . '">',
                    $formMarkup,
                    1
                ) ?? $formMarkup;
            },
            $body
        );

        if ($updatedBody === null || $updatedBody === $body) {
            return $response;
        }

        $stream = (new StreamFactory())->createStream($updatedBody);
        return $response->withBody($stream);
    }
}
