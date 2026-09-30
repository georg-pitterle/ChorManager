<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Die Erinnerung an fällige Aufgaben und Sponsoring-Wiedervorlagen.
 *
 * Der ganze Ablauf - Wartezeit, Betriebsart, träge aufgelöster Dienst,
 * Fehlerbehandlung - steht in `OpportunisticReminderMiddleware`. Welche Anlässe
 * tatsächlich versendet werden, entscheidet weiterhin
 * `NotificationService::isAvailable()` je Anlass anhand seines Moduls.
 */
class NotificationReminderMiddleware extends OpportunisticReminderMiddleware
{
    protected function markerKey(): string
    {
        return 'notification_reminder_last_check_at';
    }

    protected function failureEvent(): string
    {
        return 'notification_reminder.opportunistic.failed';
    }
}
