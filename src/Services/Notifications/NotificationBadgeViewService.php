<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Psr\Log\LoggerInterface;

/**
 * Zähler der Glocke für die Kopfzeile, erst beim Rendern ermittelt - aus
 * demselben Grund wie beim Mail-Badge (`MailBadgeViewService`): Twig kann
 * entstehen, bevor eine Anmeldung per Erinnerungs-Cookie wiederhergestellt ist.
 */
class NotificationBadgeViewService
{
    public function __construct(
        private readonly InAppNotificationStore $store,
        private readonly LoggerInterface $logger
    ) {
    }

    public function forCurrentUser(): ?int
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($userId <= 0) {
            return null;
        }

        try {
            return $this->store->unreadCount($userId);
        } catch (\Throwable $exception) {
            // Ein Problem mit der Glocke darf nie jede Seite mitreißen.
            $this->logger->error('Notification badge lookup failed.', [
                'event' => 'notification.badge.failed',
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
