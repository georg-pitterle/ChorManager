<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserMailAccount;
use Psr\Log\LoggerInterface;

/**
 * Supplies the topbar mail badge to the view layer.
 *
 * Resolved on access instead of when Twig is constructed: global middleware can
 * build Twig before the route-level AuthMiddleware restored a remember-me login,
 * and a value computed that early describes an anonymous request - the badge then
 * disappeared for the rest of that request.
 */
class MailBadgeViewService
{
    /** @var array{unseen_count: int|null, external_webmail_url: string|null}|null */
    private ?array $resolved = null;

    private ?int $resolvedForUserId = null;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Badge state of the currently authenticated user.
     *
     * Memoized per user id so repeated template access costs one query, while a
     * login that arrives later in the same request still gets its own lookup.
     *
     * @return array{unseen_count: int|null, external_webmail_url: string|null}
     */
    public function forCurrentUser(): array
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        if ($this->resolved !== null && $this->resolvedForUserId === $userId) {
            return $this->resolved;
        }

        $badge = ['unseen_count' => null, 'external_webmail_url' => null];

        if ($userId !== null) {
            try {
                $account = UserMailAccount::where('user_id', $userId)->first();
                if ($account !== null && $account->imap_enabled && $account->mail_badge_enabled) {
                    $badge['unseen_count'] = (int) $account->mail_last_unseen_count;
                    $badge['external_webmail_url'] = $account->external_webmail_url ?: null;
                }
            } catch (\Throwable $exception) {
                // A mail-subsystem or schema problem must degrade the badge only,
                // never break rendering of every authenticated page.
                $this->logger->error('Mail badge view lookup failed.', [
                    'event' => 'mail_badge.view.failed',
                    'exception' => $exception,
                ]);
            }
        }

        $this->resolved = $badge;
        $this->resolvedForUserId = $userId;

        return $badge;
    }
}
