<?php

declare(strict_types=1);

/**
 * Erzeugt die Bilder, die der Dev-Seed als Anhänge einspielt.
 *
 * Aufruf: ddev php bin/generate_seed_images.php
 *
 * Das Skript läuft **nicht** beim Seed. Die Bilder liegen fertig unter
 * assets/seed/ im Repository, der Seed liest sie nur noch. Zwei Gründe:
 * ein Seed-Lauf soll keine Bildbibliothek brauchen, und die Bilder sollen sich
 * zwischen zwei Läufen nicht unbemerkt ändern.
 *
 * Auszuführen ist es nur, wenn die Motive geändert werden sollen. Danach die
 * erzeugten Dateien mit committen.
 */

$targetDirectory = dirname(__DIR__) . '/assets/seed';

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD fehlt - ohne die Bildbibliothek lassen sich die Motive nicht zeichnen.\n");
    exit(1);
}

if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0o775, true) && !is_dir($targetDirectory)) {
    fwrite(STDERR, "Verzeichnis $targetDirectory war nicht anzulegen.\n");
    exit(1);
}

const IMAGE_WIDTH = 640;
const IMAGE_HEIGHT = 400;

/**
 * Sucht eine TrueType-Schrift. Findet sich keine, wird mit der eingebauten
 * Bitmap-Schrift beschriftet - kleiner, aber immer vorhanden.
 */
function findFont(): ?string
{
    $candidates = [
        '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    ];

    foreach ($candidates as $candidate) {
        if (is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function writeText(\GdImage $image, int $colour, int $x, int $y, int $size, string $text): void
{
    $font = findFont();

    if ($font !== null) {
        imagettftext($image, $size, 0, $x, $y + $size, $colour, $font, $text);
        return;
    }

    // Ohne TrueType bleibt die eingebaute Schrift. Sie kennt kein UTF-8, deshalb
    // hier der Notweg über transliterierte Umlaute - der echte Text steht oben.
    $fallback = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']); // naming:ascii
    imagestring($image, 5, $x, $y, $fallback, $colour);
}

/**
 * Ein Notenblatt: Papier, zwei Systeme mit je fünf Linien, Noten darauf.
 */
function drawSheetMusic(): \GdImage
{
    $image = imagecreatetruecolor(IMAGE_WIDTH, IMAGE_HEIGHT);

    $paper = imagecolorallocate($image, 252, 251, 246);
    $ink = imagecolorallocate($image, 32, 38, 52);
    $line = imagecolorallocate($image, 120, 128, 142);
    $accent = imagecolorallocate($image, 176, 58, 46);

    imagefilledrectangle($image, 0, 0, IMAGE_WIDTH, IMAGE_HEIGHT, $paper);
    imagerectangle($image, 6, 6, IMAGE_WIDTH - 7, IMAGE_HEIGHT - 7, $line);

    writeText($image, $ink, 40, 34, 20, 'Ave Verum Corpus');
    writeText($image, $line, 40, 66, 12, 'Chor-Manager - Testnoten');

    foreach ([130, 250] as $systemTop) {
        for ($i = 0; $i < 5; $i++) {
            $y = $systemTop + ($i * 12);
            imageline($image, 40, $y, IMAGE_WIDTH - 40, $y, $ink);
        }

        foreach ([40, 200, 360, IMAGE_WIDTH - 40] as $x) {
            imageline($image, $x, $systemTop, $x, $systemTop + 48, $ink);
        }

        $step = 0;
        for ($x = 70; $x < IMAGE_WIDTH - 70; $x += 46) {
            $y = $systemTop + 6 + (($step % 5) * 12);
            imagefilledellipse($image, $x, $y, 14, 10, $ink);
            imageline($image, $x + 6, $y, $x + 6, $y - 34, $ink);
            $step++;
        }
    }

    imagefilledrectangle($image, 40, IMAGE_HEIGHT - 46, 160, IMAGE_HEIGHT - 42, $accent);
    writeText($image, $line, 40, IMAGE_HEIGHT - 34, 11, 'Seite 1 von 1');

    return $image;
}

/**
 * Ein Sponsorenlogo: farbige Fläche, Initialen, Schriftzug.
 */
function drawSponsorLogo(): \GdImage
{
    $image = imagecreatetruecolor(IMAGE_WIDTH, IMAGE_HEIGHT);

    $background = imagecolorallocate($image, 244, 246, 250);
    $brand = imagecolorallocate($image, 26, 86, 148);
    $brandLight = imagecolorallocate($image, 62, 138, 208);
    $white = imagecolorallocate($image, 255, 255, 255);
    $grey = imagecolorallocate($image, 108, 117, 128);

    imagefilledrectangle($image, 0, 0, IMAGE_WIDTH, IMAGE_HEIGHT, $background);

    imagefilledellipse($image, 190, 180, 190, 190, $brand);
    imagefilledellipse($image, 190, 180, 130, 130, $brandLight);
    writeText($image, $white, 152, 158, 42, 'MW');

    writeText($image, $brand, 320, 150, 26, 'Musikhaus');
    writeText($image, $brand, 320, 186, 26, 'Weber');
    writeText($image, $grey, 320, 232, 13, 'Instrumente und Noten');

    imagefilledrectangle($image, 320, 262, 560, 266, $brandLight);
    writeText($image, $grey, 40, IMAGE_HEIGHT - 44, 11, 'Chor-Manager - Testlogo');

    return $image;
}

/**
 * Ein Auftrittsfoto: Bühne mit Lichtkegel und zwei Chorreihen als Silhouette.
 */
function drawConcertPhoto(): \GdImage
{
    $image = imagecreatetruecolor(IMAGE_WIDTH, IMAGE_HEIGHT);
    imagealphablending($image, true);

    $hall = imagecolorallocate($image, 16, 20, 34);
    $floor = imagecolorallocate($image, 34, 30, 44);
    $figure = imagecolorallocate($image, 8, 10, 18);
    $figureBack = imagecolorallocate($image, 20, 24, 40);
    $caption = imagecolorallocate($image, 236, 238, 244);
    $bar = imagecolorallocate($image, 250, 214, 128);

    imagefilledrectangle($image, 0, 0, IMAGE_WIDTH, IMAGE_HEIGHT, $hall);

    // Der Lichtkegel aus mehreren durchscheinenden Lagen: eine einzelne Fläche
    // sah aus wie ein aufgemalter Keil, gestapelt wird daraus ein weicher Rand.
    $centre = (int) (IMAGE_WIDTH / 2);
    for ($layer = 0; $layer < 6; $layer++) {
        $spread = 90 + ($layer * 34);
        $glow = imagecolorallocatealpha($image, 252, 220, 140, 96 + ($layer * 6));
        imagefilledpolygon($image, [
            $centre - 26, 0,
            $centre + 26, 0,
            $centre + $spread, 330,
            $centre - $spread, 330,
        ], $glow);
    }

    // Bühnenboden, damit die Reihen auf etwas stehen.
    imagefilledrectangle($image, 0, 318, IMAGE_WIDTH, IMAGE_HEIGHT, $floor);
    imageline($image, 0, 318, IMAGE_WIDTH, 318, $bar);

    /**
     * Eine Reihe Sängerinnen und Sänger: Kopf, Schultern, Rumpf. Die hintere
     * Reihe steht höher, ist kleiner und heller - dadurch wirken zwei Reihen
     * statt eines Klumpens.
     */
    $drawRow = static function (int $baseline, int $radius, int $step, int $offset, int $colour) use ($image): void {
        for ($x = $offset; $x < IMAGE_WIDTH - 20; $x += $step) {
            $shoulder = $baseline + $radius;
            imagefilledellipse($image, $x, $baseline, $radius * 2, $radius * 2, $colour);
            imagefilledellipse($image, $x, $shoulder + 16, (int) ($radius * 3.1), 44, $colour);
            imagefilledrectangle(
                $image,
                (int) ($x - $radius * 1.5),
                $shoulder + 16,
                (int) ($x + $radius * 1.5),
                340,
                $colour
            );
        }
    };

    $drawRow(214, 15, 84, 66, $figureBack);
    $drawRow(250, 18, 96, 108, $figure);

    imagefilledrectangle($image, 0, IMAGE_HEIGHT - 58, IMAGE_WIDTH, IMAGE_HEIGHT, $hall);
    imagefilledrectangle($image, 40, IMAGE_HEIGHT - 46, 100, IMAGE_HEIGHT - 42, $bar);
    writeText($image, $caption, 40, IMAGE_HEIGHT - 36, 15, 'Sommerkonzert - Chor-Manager');

    return $image;
}

$motifs = [
    'notenblatt' => 'drawSheetMusic',
    'sponsorenlogo' => 'drawSponsorLogo',
    'auftrittsfoto' => 'drawConcertPhoto',
];

foreach ($motifs as $name => $painter) {
    $image = $painter();
    $path = $targetDirectory . '/' . $name . '.png';

    // Höchste Kompressionsstufe: die Motive sind flächig, das drückt sie auf
    // wenige Kilobyte - sie liegen immerhin im Repository.
    imagepng($image, $path, 9);

    printf('%s (%d Bytes)%s', $path, (int) filesize($path), PHP_EOL);
}

echo 'Fertig. Die Dateien gehören mit in den Commit.', PHP_EOL;
