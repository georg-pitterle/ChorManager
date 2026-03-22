<?php

declare(strict_types=1);

use Slim\App;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

return function (App $app): void {
    // Example health endpoint middleware stack can stay empty for now.
    $displayErrorDetails = getenv('APP_ENV') !== 'production';
    $app->addErrorMiddleware($displayErrorDetails, true, true);
};