<?php

declare(strict_types=1);

use Slim\App;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Util\EnvHelper;
use App\Middleware\CsrfMiddleware;

return function (App $app): void {
    // Example health endpoint middleware stack can stay empty for now.
    $displayErrorDetails = EnvHelper::read('APP_ENV', 'development') !== 'production';
    $app->addErrorMiddleware($displayErrorDetails, true, true);
    $app->add(CsrfMiddleware::class);
};
