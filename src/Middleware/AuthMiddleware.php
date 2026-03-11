<?php
declare(strict_types = 1)
;

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        session_start();

        // Exclude the login and setup routes
        $path = $request->getUri()->getPath();
        if ($path === '/login' || $path === '/setup' || $path === '/') {
            return $handler->handle($request);
        }

        if (!isset($_SESSION['user_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // Pass session data to view globally, can be done via middleware or Twig extension.
        // For simplicity, we can inject globals into Twig in Dependencies.php, but since we use Slim-Twig, 
        // we can also do it there.

        return $handler->handle($request);
    }
}
