<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\RegistrationReminderService;
use App\Util\AppUrlResolver;
use App\Util\OpportunisticRunGate;
use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;

class RegistrationReminderMiddleware implements MiddlewareInterface
{
    private const MARKER_KEY = 'registration_reminder_last_check_at';
    private const CHECK_INTERVAL_SECONDS = 3600;

    /**
     * The reminder service is resolved through a factory (rather than injected
     * directly) because it depends on Twig. This middleware is global, so it runs
     * before the route-level AuthMiddleware: building Twig here captured the view
     * layer's session state while the request was still unauthenticated, and a
     * remember-me login restored afterwards no longer reached the templates - the
     * navbar silently disappeared for that request.
     *
     * @param Closure(): RegistrationReminderService $reminderServiceFactory
     */
    public function __construct(
        private readonly Closure $reminderServiceFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $this->processIfDue($request);

        return $handler->handle($request);
    }

    private function processIfDue(Request $request): void
    {
        try {
            if (!OpportunisticRunGate::tryClaim(self::MARKER_KEY, self::CHECK_INTERVAL_SECONDS)) {
                return;
            }

            $reminderService = ($this->reminderServiceFactory)();
            $reminderService->processDue(AppUrlResolver::resolveBaseUrl($request));
        } catch (\Throwable $exception) {
            $this->logger->error('Opportunistic registration reminder processing failed.', [
                'event' => 'registration_reminder.opportunistic.failed',
                'exception' => $exception,
            ]);
        }
    }
}
