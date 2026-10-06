<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Ein Warteschlangen-Eintrag ließ sich nicht beanspruchen.
 *
 * Zwei Versand-Durchläufe holen sich denselben Ausschnitt der Warteschlange; den Zuschlag
 * gibt dann ein bedingtes Update, und einer der beiden geht leer aus. Das ist der
 * vorgesehene Ablauf und kein Fehlschlag: Die Mail ist unterwegs, nur eben im anderen
 * Durchlauf. Dasselbe gilt für eine Zeile, die in genau diesem Augenblick verschwindet -
 * dann gibt es nichts mehr zu verschicken.
 *
 * Eigene Klasse und nicht die Meldung einer allgemeinen Exception, weil
 * MailDeliveryService::processDueEntries() daran entscheidet, ob der Eintrag in die
 * Fehlerstatistik gehört. Am Text einer Exception zu erkennen, was gemeint war, hält
 * genau so lange, bis jemand den Text umformuliert.
 */
class MailQueueEntryNotClaimableException extends RuntimeException
{
}
