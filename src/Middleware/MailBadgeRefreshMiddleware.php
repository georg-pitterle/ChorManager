<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\UserMailAccount;
use App\Services\MailBadgeService;
use Carbon\Carbon;
use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class MailBadgeRefreshMiddleware implements MiddlewareInterface
{
    private const STALENESS_MINUTES = 5;

    /**
     * The badge service is resolved through a factory (rather than injected
     * directly) so that constructing it - which loads MAIL_CREDENTIAL_KEY and
     * fails closed on a missing/invalid key - happens lazily inside the guarded
     * refresh path. A mail-subsystem misconfiguration must degrade the badge
     * only, never 500 every page on this global middleware.
     *
     * @param Closure(): MailBadgeService $badgeServiceFactory
     */
    public function __construct(
        private readonly Closure $badgeServiceFactory,
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
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

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

            $badgeService = ($this->badgeServiceFactory)();
            $badgeService->refresh($account);
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
