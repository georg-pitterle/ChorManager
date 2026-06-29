<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\UserMailAccount;
use App\Services\MailBadgeService;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class MailBadgeRefreshMiddleware implements MiddlewareInterface
{
    private const STALENESS_MINUTES = 5;

    public function __construct(
        private readonly MailBadgeService $badgeService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $this->refreshIfDue();

        return $handler->handle($request);
    }

    private function refreshIfDue(): void
    {
        try {
            if (!isset($_SESSION['user_id'])) {
                return;
            }

            $account = UserMailAccount::where('user_id', (int) $_SESSION['user_id'])->first();
            if ($account === null || !$account->imap_enabled || !$account->mail_badge_enabled) {
                return;
            }

            if ($account->mail_last_checked_at !== null) {
                $lastChecked = Carbon::parse($account->mail_last_checked_at);
                if ($lastChecked->addMinutes(self::STALENESS_MINUTES)->isFuture()) {
                    return;
                }
            }

            $this->badgeService->refresh($account);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Mail badge opportunistic refresh failed.',
                [
                    'event' => 'mail_badge.middleware.failed',
                    'exception' => $exception,
                ]
            );
        }
    }
}
