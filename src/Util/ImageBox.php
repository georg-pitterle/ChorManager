<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Rechnet aus, wie groß ein Bild gezeichnet werden darf, damit es in einen vorgegebenen
 * Rahmen passt, ohne sein Seitenverhältnis zu verlieren.
 *
 * Sowohl der Mailkopf als auch der Kassabuch-Bericht zeichneten das Vereinslogo vorher in
 * ein festes Quadrat. Das mitgelieferte Logo ist quadratisch und sah damit richtig aus -
 * ein Schriftzug im Querformat wurde gestaucht, ein Wappen im Hochformat in die Breite
 * gezogen. Beide Stellen rechnen jetzt hier, damit sie nicht wieder auseinanderlaufen.
 */
final class ImageBox
{
    /**
     * Skaliert das Bild proportional in den Rahmen. Vergrößert wird dabei auch: ein kleines
     * Logo füllt den Kopfbereich weiterhin aus, statt plötzlich als Briefmarke zu erscheinen.
     *
     * Lässt sich das Bild nicht vermessen, bleibt es beim größten Quadrat, das noch in den
     * Rahmen passt - die bisherige Darstellung. Die Datei ist dann ohnehin kaputt und wird
     * nirgends angezeigt; ein geratenes Seitenverhältnis wäre keine Verbesserung.
     *
     * @return array{0: float, 1: float} Breite und Höhe in der Einheit des Rahmens
     */
    public static function fit(string $binaryContent, float $maxWidth, float $maxHeight): array
    {
        $fallback = min($maxWidth, $maxHeight);

        if ($binaryContent === '') {
            return [$fallback, $fallback];
        }

        $dimensions = @getimagesizefromstring($binaryContent);
        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1) {
            return [$fallback, $fallback];
        }

        $scale = min($maxWidth / $dimensions[0], $maxHeight / $dimensions[1]);

        return [$dimensions[0] * $scale, $dimensions[1] * $scale];
    }
}
