<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Die Erinnerung an offene Terminanmeldungen.
 *
 * Der ganze Ablauf - Wartezeit, Betriebsart, träge aufgelöster Dienst,
 * Fehlerbehandlung - steht in `OpportunisticReminderMiddleware`.
 */
class RegistrationReminderMiddleware extends OpportunisticReminderMiddleware
{
    protected function markerKey(): string
    {
        return 'registration_reminder_last_check_at';
    }

    protected function failureEvent(): string
    {
        return 'registration_reminder.opportunistic.failed';
    }
}
