<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\AppSetting;
use App\Services\MailDeliveryService;
use App\Util\OpportunisticRunGate;
use Illuminate\Database\QueryException;
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
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $this->processQueueIfDue();

        return $handler->handle($request);
    }

    private function processQueueIfDue(): void
    {
        try {
            // Anders als bei den beiden Erinnerungen ist die Wartezeit hier nicht fest,
            // sondern folgt der eingestellten Obergrenze an Läufen pro Minute.
            $rateLimit = max(1, (int) $this->getSetting('mailqueue_opportunistic_rate_limit', '10'));
            $minimumIntervalSeconds = max(1, (int) ceil(60 / $rateLimit));

            if (!OpportunisticRunGate::tryClaim(self::MARKER_KEY, $minimumIntervalSeconds)) {
                return;
            }

            $batchSize = max(1, (int) $this->getSetting('mailqueue_batch_size', '50'));

            $this->deliveryService->processDueEntries($batchSize);
        } catch (QueryException $exception) {
            // Transient database outage (e.g. the db container restarting during a
            // deploy). Opportunistic processing is best-effort and the dedicated
            // worker will catch up, so this is expected noise, not a failure.
            $this->logger->warning(
                'Opportunistic mail queue processing skipped (database unavailable).',
                [
                    'event' => 'mail_queue.opportunistic.skipped',
                    'exception' => $exception,
                ]
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Opportunistic mail queue processing failed.',
                [
                    'event' => 'mail_queue.opportunistic.failed',
                    'exception' => $exception,
                ]
            );
        }
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
