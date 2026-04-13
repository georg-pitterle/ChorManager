<?php

declare(strict_types=1);

use Slim\App;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Util\AppEnvironment;
use App\Middleware\CsrfMiddleware;
use App\Middleware\HtmlFormCsrfInjectorMiddleware;
use App\Middleware\SecurityHeadersMiddleware;

return function (App $app): void {
    // Example health endpoint middleware stack can stay empty for now.
    $displayErrorDetails = AppEnvironment::isDebugEnabled();
    $app->addErrorMiddleware($displayErrorDetails, true, true);
    $app->add(HtmlFormCsrfInjectorMiddleware::class);
    $app->add(CsrfMiddleware::class);
    $app->add(SecurityHeadersMiddleware::class);
};
