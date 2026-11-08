<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\AppSetting;
use App\Services\MailDeliveryService;
use App\Util\AfterResponse;
use App\Util\OpportunisticRunGate;
use App\Util\OpportunisticWork;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;

class MailQueueProcessingMiddleware implements MiddlewareInterface
{
    private const MARKER_KEY = 'mailqueue_last_opportunistic_run_at';

    public function __construct(
        private readonly MailDeliveryService $deliveryService,
        private readonly LoggerInterface $logger,
        private readonly AfterResponse $afterResponse
    ) {
    }

    /**
     * Versendet wird erst, wenn die Antwort beim Browser und die Sitzung
     * freigegeben ist. Vorher lief der Versand vor dem Controller, mitten in der
     * Anfrage: Ein Mailserver, der nicht antwortete, hielt so Seite und
     * Sitzung fest, bis alle PHP-FPM-Prozesse auf die Sitzungssperre warteten
     * und die Anwendung stand.
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        $this->afterResponse->defer(fn () => $this->processQueueIfDue());

        return $response;
    }

    /**
     * Die Fehlerbehandlung - eine kurze Datenbank-Störung als `warning`, alles
     * andere als `error` - liegt in `OpportunisticWork`, gemeinsam mit den
     * Erinnerungen und dem Mail-Badge. Meldungen und `event`-Schlüssel sind
     * dieselben wie vorher.
     */
    private function processQueueIfDue(): void
    {
        OpportunisticWork::run(
            $this->logger,
            'Opportunistic mail queue processing',
            'mail_queue.opportunistic',
            function (): void {
                // Anders als bei den beiden Erinnerungen ist die Wartezeit hier nicht fest,
                // sondern folgt der eingestellten Obergrenze an Läufen pro Minute.
                $rateLimit = max(1, (int) $this->getSetting('mailqueue_opportunistic_rate_limit', '10'));
                $minimumIntervalSeconds = max(1, (int) ceil(60 / $rateLimit));

                if (!OpportunisticRunGate::tryClaim(self::MARKER_KEY, $minimumIntervalSeconds)) {
                    return;
                }

                $batchSize = max(1, (int) $this->getSetting('mailqueue_batch_size', '50'));

                $this->deliveryService->processDueEntries($batchSize);
            }
        );
    }

    private function getSetting(string $key, ?string $default = null): ?string
    {
        $value = AppSetting::query()
            ->where('setting_key', $key)
            ->value('setting_value');

        if ($value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}
