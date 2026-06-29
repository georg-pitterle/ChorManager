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
use App\Middleware\MailBadgeRefreshMiddleware;
use App\Middleware\MailQueueProcessingMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

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

    $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true, $logger);
    $defaultErrorHandler = $errorMiddleware->getDefaultErrorHandler();
    $errorMiddleware->setErrorHandler(
        HttpNotFoundException::class,
        static function (
            Request $request,
            \Throwable $exception,
            bool $displayErrorDetails,
            bool $logErrors,
            bool $logErrorDetails
        ) use (
            $app,
            $container,
            $defaultErrorHandler
        ): Response {
            if (!$displayErrorDetails && $container instanceof ContainerInterface) {
                try {
                    $view = $container->get(Twig::class);
                    if ($view instanceof Twig) {
                        $response = $app->getResponseFactory()->createResponse(404);

                        return $view->render(
                            $response,
                            'errors/404.twig',
                            ['requested_path' => $request->getUri()->getPath()]
                        );
                    }
                } catch (\Throwable) {
                    // Fall through to Slim default error handler when Twig rendering fails.
                }
            }

            return $defaultErrorHandler($request, $exception, $displayErrorDetails, false, false);
        }
    );

    $app->add(HtmlFormCsrfInjectorMiddleware::class);
    $app->add(CsrfMiddleware::class);
    $app->add(MailQueueProcessingMiddleware::class);
    $app->add(MailBadgeRefreshMiddleware::class);
    $app->add(SecurityHeadersMiddleware::class);
};
