<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Bereinigt Dateinamen für den Content-Disposition-Kopf eines Downloads.
 *
 * Der Name stammt aus einem Upload und damit vom Nutzer. Zeilenumbrüche würden
 * den Kopf aufbrechen und weitere Kopfzeilen anhängen lassen; Anführungszeichen
 * beenden den quoted-string vorzeitig, und Pfadtrenner laden dazu ein, den Namen
 * beim Speichern als Pfad zu deuten. Alle werden deshalb ersetzt, statt sie
 * zu entfernen - ein Name soll erkennbar bleiben, auch wenn er entschärft wurde.
 *
 * Mit Wagenrücklauf und Zeilenvorschub fallen auch die übrigen Steuerzeichen. Ein Kopf
 * darf keine tragen: `Content-Disposition: attachment; filename="Satz\x011.pdf"` ist
 * kein gültiger Kopf mehr, und der Browser liest den Dateinamen dann nach eigenem
 * Ermessen. Dieselbe Grenze zieht WebdavTreeService::uniqueName() für die XML-Antwort
 * des Noten-Ordners; hier gilt sie für jeden Download.
 *
 * Der Rückgabewert ist nie leer: Ein Name, von dem nach dem Ersetzen und Trimmen
 * nichts übrig bleibt, würde sonst einen Kopf ohne Dateinamen erzeugen.
 *
 * Neben diesem bereinigten Namen gehört in den Kopf immer auch die
 * RFC-5987-Fassung (filename*=UTF-8''...), damit Umlaute erhalten bleiben.
 */
final class DownloadFileName
{
    private const FALLBACK = 'download';

    public static function sanitize(string $name): string
    {
        // Ohne `u`-Modifizierer, also byteweise: Steuerzeichen sind in UTF-8 immer ein
        // einzelnes Byte und treten nie innerhalb einer Mehrbyte-Folge auf. Mit `u` gäbe
        // preg_replace() bei ungültigem UTF-8 null zurück und der ganze Name wäre weg.
        $withoutControlChars = (string) preg_replace('/[\x00-\x1F\x7F]/', '_', $name);
        $safe = str_replace(['"', '\\', '/'], '_', $withoutControlChars);
        $trimmed = trim($safe);

        return $trimmed !== '' ? $trimmed : self::FALLBACK;
    }
}
