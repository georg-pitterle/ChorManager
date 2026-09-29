<?php

declare(strict_types=1);

namespace App\Services\Files;

/**
 * Bereinigt Datei- und Ordnernamen, die Nutzer eingeben oder hochladen.
 *
 * Der Name ist reine Anzeige - auf der Platte liegt jede Datei unter einem
 * Zufallspfad. Trotzdem gelangt er in Download-Köpfe, ZIP-Archive und
 * Brotkrumen; Pfadtrenner und Steuerzeichen haben dort nichts verloren.
 * Pfadtrenner werden ersetzt statt entfernt, damit der Name erkennbar bleibt.
 */
final class FileNameCleaner
{
    public const MAX_BYTES = 255;

    public static function clean(string $name): ?string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = str_replace(['/', '\\'], '_', $name);
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        if (strlen($name) > self::MAX_BYTES) {
            $name = self::shorten($name);
        }

        return $name;
    }

    /**
     * Kürzt auf 255 Byte und behält dabei die Endung, damit die Datei nach dem
     * Herunterladen noch mit dem richtigen Programm aufgeht.
     */
    private static function shorten(string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension !== '' && strlen($extension) <= 16 ? '.' . $extension : '';
        $stem = $suffix === '' ? $name : substr($name, 0, -strlen($suffix));

        return rtrim(mb_strcut($stem, 0, self::MAX_BYTES - strlen($suffix), 'UTF-8')) . $suffix;
    }
}
