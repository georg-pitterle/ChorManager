<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\AppSetting;
use App\Services\MailDeliveryService;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class MailQueueProcessingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly MailDeliveryService $deliveryService,
        private readonly LoggerInterface $logger
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $this->processQueueIfDue();

        return $handler->handle($request);
    }

    private function processQueueIfDue(): void
    {
        try {
            $triggerMode = $this->getSetting('mailqueue_trigger_mode', 'hybrid');
            if (!in_array($triggerMode, ['hybrid', 'opportunistic'], true)) {
                return;
            }

            $rateLimit = max(1, (int) $this->getSetting('mailqueue_opportunistic_rate_limit', '10'));
            $batchSize = max(1, (int) $this->getSetting('mailqueue_batch_size', '50'));
            $minimumIntervalSeconds = max(1, (int) ceil(60 / $rateLimit));

            $lastRunRaw = $this->getSetting('mailqueue_last_opportunistic_run_at');
            if ($lastRunRaw !== null && $lastRunRaw !== '') {
                $lastRun = Carbon::parse($lastRunRaw);
                if ($lastRun->addSeconds($minimumIntervalSeconds)->isFuture()) {
                    return;
                }
            }

            AppSetting::updateOrCreate(
                ['setting_key' => 'mailqueue_last_opportunistic_run_at'],
                [
                    'setting_value' => Carbon::now()->format('Y-m-d H:i:s'),
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );

            $this->deliveryService->processDueEntries($batchSize);
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
