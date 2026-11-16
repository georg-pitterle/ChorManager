<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Pfad und Sitzungsvermerk für den erzwungenen Abgleich des Postfach-Zählers.
 *
 * Beides stand im MailBadgeController; die MailBadgeRefreshMiddleware (eine
 * Schicht darunter) und der WebmailController lasen es von dort.
 */
final class MailBadgeRefresh
{
    /**
     * Pfad des Endpunkts.
     *
     * Ein Aufruf hier ist per Definition die Bitte um einen aktuellen Zählerstand,
     * nicht um den zwischengespeicherten - die Middleware erzwingt dafür einen
     * IMAP-Abgleich.
     */
    public const PATH = '/profile/mail-badge';

    /**
     * Einmalvermerk in der Session: Der nächste Request gleicht ab, egal wie kurz
     * der letzte Abgleich her ist. Gesetzt beim Start des Webmails, weil sich das
     * Postfach in einem separaten Tab öffnet und dieser Tab davon nichts mitbekommt.
     */
    public const FORCE_SESSION_KEY = 'mail_badge_refresh_due';
}
