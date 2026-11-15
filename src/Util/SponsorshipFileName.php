<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Der Name, unter dem ein Vertrag oder Beleg an einer Sponsoring-Anfrage liegt.
 *
 * Hochgeladen wird, was der Scanner ausgibt: "SCAN0126232026103008.pdf". In der
 * Liste aller Anhänge ließ sich so ein Vertrag nur durch Öffnen zuordnen. Beim
 * Ablegen an der Anfrage heißt die Datei deshalb nach Firma, Projekt und Paket;
 * wer es genauer braucht ("Angebot", "unterschrieben"), benennt sie danach um.
 */
final class SponsorshipFileName
{
    /** Zeichen, die in Dateinamen unter Windows oder als Pfad Ärger machen. */
    private const FORBIDDEN = '/[\\\\\/:*?"<>|\x00-\x1f]+/u';

    /** Platz für Zähler und Endung bleibt bis zur Spaltenbreite von 255. */
    private const MAX_BASE_LENGTH = 200;

    private const FALLBACK = 'Sponsor';

    /**
     * @param list<string> $taken Namen, die an dieser Anfrage schon vergeben sind
     */
    public static function build(
        string $sponsor,
        ?string $project,
        ?string $package,
        string $uploadedName,
        array $taken
    ): string {
        $parts = array_filter(
            [self::part($sponsor) ?: self::FALLBACK, self::part((string) $project), self::part((string) $package)],
            static fn (string $part): bool => $part !== ''
        );
        $base = mb_substr(implode('_', $parts), 0, self::MAX_BASE_LENGTH);
        $extension = self::extension($uploadedName);

        $takenLower = array_map('mb_strtolower', $taken);
        $candidate = $base . $extension;
        for ($number = 2; in_array(mb_strtolower($candidate), $takenLower, true); $number++) {
            $candidate = $base . '_' . $number . $extension;
        }

        return $candidate;
    }

    /**
     * Der neue Name beim Umbenennen, oder null, wenn nichts Brauchbares
     * eingegeben wurde. Die Endung der Datei bleibt: Ohne sie ließe sich die
     * Datei nach dem Herunterladen nicht mehr öffnen, und eine andere
     * vorzutäuschen soll nicht gehen.
     */
    public static function rename(string $wanted, string $currentName): ?string
    {
        $name = trim((string) preg_replace(self::FORBIDDEN, '-', trim($wanted)), " \t-");
        if ($name === '') {
            return null;
        }

        $extension = self::extension($currentName);
        if ($extension !== '' && str_ends_with(mb_strtolower($name), $extension)) {
            $name = mb_substr($name, 0, mb_strlen($name) - mb_strlen($extension));
        }

        $name = trim(mb_substr($name, 0, self::MAX_BASE_LENGTH));

        return $name === '' ? null : $name . $extension;
    }

    private static function part(string $text): string
    {
        $clean = (string) preg_replace(self::FORBIDDEN, ' ', $text);

        // Leerraum und Bindestriche zusammen werden ein Bindestrich - aus
        // "HS 2026 - Lametta" wurde sonst "HS-2026---Lametta".
        return trim((string) preg_replace('/[\s-]+/u', '-', trim($clean)), '-');
    }

    /** Die Endung samt Punkt in Kleinbuchstaben, oder leer. */
    private static function extension(string $fileName): string
    {
        return preg_match('/\.([A-Za-z0-9]{1,10})$/', $fileName, $match) === 1
            ? '.' . strtolower($match[1])
            : '';
    }
}
