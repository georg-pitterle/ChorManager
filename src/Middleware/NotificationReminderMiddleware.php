<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\AppSetting;
use App\Services\NotificationReminderService;
use App\Util\AppUrlResolver;
use App\Util\MailQueueTriggerMode;
use Carbon\Carbon;
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
 * Stundentakts und derselben Betriebsart-Prüfung: Eine Installation, die
 * `mailqueue_trigger_mode` auf reinen Cron stellt, will hier keine Arbeit im
 * Anfrageweg.
 */
class NotificationReminderMiddleware implements MiddlewareInterface
{
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
            if (!MailQueueTriggerMode::allowsOpportunisticWork()) {
                return;
            }

            $lastRunRaw = AppSetting::query()
                ->where('setting_key', 'notification_reminder_last_check_at')
                ->value('setting_value');

            if ($lastRunRaw !== null && $lastRunRaw !== '') {
                $lastRun = Carbon::parse((string) $lastRunRaw);
                if ($lastRun->addSeconds(self::CHECK_INTERVAL_SECONDS)->isFuture()) {
                    return;
                }
            }

            // Der Merker wird vor dem Lauf gesetzt: Bricht der Lauf ab, wartet
            // die nächste Anfrage eine Stunde, statt es sofort wieder zu
            // versuchen und jede Anfrage mit demselben Fehler zu belasten.
            AppSetting::updateOrCreate(
                ['setting_key' => 'notification_reminder_last_check_at'],
                [
                    'setting_value' => Carbon::now()->format('Y-m-d H:i:s'),
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );

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
