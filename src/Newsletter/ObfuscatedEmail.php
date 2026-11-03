<?php

declare(strict_types=1);

namespace App\Newsletter;

/**
 * Erkennt Mail-Adressen, die Cloudflares E-Mail-Verschleierung (Scrape Shield) unkenntlich
 * gemacht hat.
 *
 * Cloudflare ersetzt jede Adresse im ausgelieferten HTML durch "[email protected]" und
 * entschlüsselt sie erst per Skript im Browser wieder. Kommt ein Newsletter so im Editor an
 * und wird gespeichert, steht der Platzhalter dauerhaft im Inhalt - und geht mit der Mail an
 * alle Empfänger. Abschalten lässt sich das nur bei Cloudflare selbst; diese Prüfung sorgt
 * dafür, dass bis dahin nichts Verfälschtes gespeichert wird.
 */
final class ObfuscatedEmail
{
    public const MESSAGE = 'Der Inhalt enthält eine unkenntlich gemachte Mail-Adresse („[email protected]“). '
        . 'Bitte die Adresse im Text neu eintippen und noch einmal speichern.';

    /**
     * Spuren der Verschleierung: die Klasse und der Verweis des Entschlüsslers sowie der
     * sichtbare Platzhalter, mit geschütztem Leerzeichen als Zeichen oder als Entität.
     */
    private const PATTERN = '/__cf_email__|\/cdn-cgi\/l\/email-protection|'
        . '\[email(?:\s|\x{00A0}|&nbsp;|&#160;|&#x0*a0;)+protected\]/iu';

    public static function isPresentIn(?string $html): bool
    {
        return $html !== null && $html !== '' && preg_match(self::PATTERN, $html) === 1;
    }
}
