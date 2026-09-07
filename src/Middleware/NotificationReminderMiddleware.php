<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\NotificationReminderService;
use App\Util\AppUrlResolver;
use App\Util\OpportunisticRunGate;
use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;

/**
 * Stößt die fälligen Erinnerungen nebenbei an, wenn kein Cron läuft.
 *
 * Wortgleich zur `RegistrationReminderMiddleware` aufgebaut, inklusive des
 * Stundentakts. Die Wartezeit und die Betriebsart-Prüfung - eine Installation,
 * die `mailqueue_trigger_mode` auf reinen Cron stellt, will hier keine Arbeit im
 * Anfrageweg - liegen beide im `OpportunisticRunGate`.
 */
class NotificationReminderMiddleware implements MiddlewareInterface
{
    private const MARKER_KEY = 'notification_reminder_last_check_at';
    private const CHECK_INTERVAL_SECONDS = 3600;

    /**
     * Der Dienst kommt über eine Fabrik, nicht als fertige Instanz. Grund ist
     * derselbe wie bei der Anmelde-Erinnerung: Diese Middleware läuft global
     * und damit vor der AuthMiddleware. Twig hier zu bauen fror den noch
     * unangemeldeten Sitzungszustand ein, und eine per Remember-Me
     * wiederhergestellte Anmeldung erreichte die Templates nicht mehr - die
     * Navigationsleiste verschwand für diese eine Anfrage.
     *
     * @param Closure(): NotificationReminderService $reminderServiceFactory
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
            $this->logger->error('Opportunistic notification reminder processing failed.', [
                'event' => 'notification_reminder.opportunistic.failed',
                'exception' => $exception,
            ]);
        }
    }
}
