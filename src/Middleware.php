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
use App\Middleware\RegistrationReminderMiddleware;
use App\Middleware\RequestContextMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    // ModelNotFoundException zaehlt hier wie eine unbekannte Route.
    //
    // findOrFail() wirft sie, wenn ein Datensatz nicht (mehr) existiert - ein
    // veraltetes Lesezeichen auf /tasks/26 oder ein Link auf einen inzwischen
    // geloeschten Eintrag reicht. Ohne eigene Behandlung landete das als
    // "Slim Application Error" mit vollem Stapelverlauf im Fehlerprotokoll und die
    // aufrufende Person sah eine 500-Seite, obwohl schlicht nichts zu finden war.
    $errorMiddleware->setErrorHandler(
        [HttpNotFoundException::class, ModelNotFoundException::class],
        function (
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

    // Zuletzt hinzugefuegt heisst zuerst ausgefuehrt: Der Request-Kontext steht
    // damit allen nachfolgenden Middlewares und Controllern zur Verfuegung.
    $app->add(HtmlFormCsrfInjectorMiddleware::class);
    $app->add(CsrfMiddleware::class);
    $app->add(MailQueueProcessingMiddleware::class);

    $settings = $container instanceof ContainerInterface ? $container->get('settings') : [];
    if ($settings['modules']['registration'] ?? false) {
        $app->add(RegistrationReminderMiddleware::class);
    }

    $app->add(MailBadgeRefreshMiddleware::class);
    $app->add(SecurityHeadersMiddleware::class);
    $app->add(RequestContextMiddleware::class);
};
