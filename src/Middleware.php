<?php

declare(strict_types=1);

use Slim\App;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Util\AppEnvironment;
use App\Middleware\CsrfMiddleware;
use App\Middleware\HtmlFormCsrfInjectorMiddleware;
use App\Middleware\MailQueueProcessingMiddleware;
use App\Middleware\SecurityHeadersMiddleware;

return function (App $app): void {
    // Example health endpoint middleware stack can stay empty for now.
    $displayErrorDetails = AppEnvironment::isDebugEnabled();
    $logger = null;
    $container = $app->getContainer();
    if ($container instanceof ContainerInterface) {
        try {
            $resolvedLogger = $container->get(LoggerInterface::class);
            if ($resolvedLogger instanceof LoggerInterface) {
                $logger = $resolvedLogger;
            }
        } catch (\Throwable) {
            $logger = null;
        }
    }

    $app->addErrorMiddleware($displayErrorDetails, true, true, $logger);
    $app->add(HtmlFormCsrfInjectorMiddleware::class);
    $app->add(CsrfMiddleware::class);
    $app->add(MailQueueProcessingMiddleware::class);
    $app->add(SecurityHeadersMiddleware::class);
};
