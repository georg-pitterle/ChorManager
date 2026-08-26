<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Bereinigt Dateinamen für den Content-Disposition-Kopf eines Downloads.
 *
 * Der Name stammt aus einem Upload und damit vom Nutzer. Zeilenumbrüche würden
 * den Kopf aufbrechen und weitere Kopfzeilen anhängen lassen; Anführungszeichen
 * beenden den quoted-string vorzeitig, und Pfadtrenner laden dazu ein, den Namen
 * beim Speichern als Pfad zu deuten. Alle fünf werden deshalb ersetzt, statt sie
 * zu entfernen - ein Name soll erkennbar bleiben, auch wenn er entschärft wurde.
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
        $safe = str_replace(["\r", "\n", '"', '\\', '/'], '_', $name);
        $trimmed = trim($safe);

        return $trimmed !== '' ? $trimmed : self::FALLBACK;
    }
}
