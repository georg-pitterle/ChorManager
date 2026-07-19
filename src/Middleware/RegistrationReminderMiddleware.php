<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\AppSetting;
use App\Services\RegistrationReminderService;
use App\Util\AppUrlResolver;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;

class RegistrationReminderMiddleware implements MiddlewareInterface
{
    private const CHECK_INTERVAL_SECONDS = 3600;

    public function __construct(
        private readonly RegistrationReminderService $reminderService,
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
            $lastRunRaw = AppSetting::query()
                ->where('setting_key', 'registration_reminder_last_check_at')
                ->value('setting_value');

            if ($lastRunRaw !== null && $lastRunRaw !== '') {
                $lastRun = Carbon::parse((string) $lastRunRaw);
                if ($lastRun->addSeconds(self::CHECK_INTERVAL_SECONDS)->isFuture()) {
                    return;
                }
            }

            AppSetting::updateOrCreate(
                ['setting_key' => 'registration_reminder_last_check_at'],
                [
                    'setting_value' => Carbon::now()->format('Y-m-d H:i:s'),
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );

            $this->reminderService->processDue(AppUrlResolver::resolveBaseUrl($request));
        } catch (\Throwable $exception) {
            $this->logger->error('Opportunistic registration reminder processing failed.', [
                'event' => 'registration_reminder.opportunistic.failed',
                'exception' => $exception,
            ]);
        }
    }
}
