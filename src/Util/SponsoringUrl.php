<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Die Rückwege des Sponsorings an einer Stelle.
 *
 * Die drei Controller des Moduls setzten ihr Ziel als
 * `'/sponsoring/sponsors/' . $sponsorId` zusammen - dreißig Mal. Fehlte die
 * Kennung in der Anfrage, hieß das Ziel `/sponsoring/sponsors/0`, und dort
 * wirft `SponsorController::detail()` eine ModelNotFoundException: Die Person
 * sah die 404-Seite, und weil die keine Meldungen ausgibt, verschwand die
 * gerade gesetzte Begründung mit ihr. `SponsorshipController::uploadAttachment()`
 * wich schon auf die Liste aus; hier gilt das für jeden Rückweg.
 */
final class SponsoringUrl
{
    /** Die Sponsorenliste - Rückfallziel, solange kein Sponsor feststeht. */
    public const SPONSOR_LIST = '/sponsoring/sponsors';

    /**
     * Die Seite eines Sponsors, oder die Liste ohne brauchbare Kennung.
     *
     * Auch eine Zeichenkette ist erlaubt: `Sponsorship::$sponsor_id` trägt
     * keine Umwandlung, und was der Treiber liefert, war an den Aufrufstellen
     * bisher gleichgültig - sie hängten den Wert an eine Zeichenkette.
     */
    public static function sponsorDetail(int|string|null $sponsorId): string
    {
        $id = (int) $sponsorId;

        return $id > 0 ? self::SPONSOR_LIST . '/' . $id : self::SPONSOR_LIST;
    }
}
