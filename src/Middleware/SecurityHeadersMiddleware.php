<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\EnvHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        $response = $response
            ->withHeader('Content-Security-Policy', $this->buildCsp())
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader(
                'Permissions-Policy',
                'camera=(), microphone=(), geolocation=(), accelerometer=(), gyroscope=(), magnetometer=()'
            );

        if ($this->isHttpsRequest($request) && EnvHelper::read('APP_ENV', 'development') === 'production') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function buildCsp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "worker-src 'self' blob:",
            "media-src 'self' blob:",
        ]);
    }

    private function isHttpsRequest(Request $request): bool
    {
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }

        $forwardedProto = strtolower(trim($request->getHeaderLine('X-Forwarded-Proto')));
        if ($forwardedProto === 'https') {
            return true;
        }

        $httpsServerValue = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        return $httpsServerValue !== '' && $httpsServerValue !== 'off';
    }
}
