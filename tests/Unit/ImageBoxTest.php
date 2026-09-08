<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Util\ImageBox;
use PHPUnit\Framework\TestCase;

/**
 * Gemeinsame Maßrechnung für Logos in Mail und PDF. Beide Stellen zeichneten das Logo
 * vorher in ein festes Quadrat und quetschten damit jedes andere Seitenverhältnis.
 */
final class ImageBoxTest extends TestCase
{
    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image, 'GD konnte kein Testbild anlegen.');

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function testSquareImageFillsTheSmallerSideOfTheBox(): void
    {
        $this->assertSame([56.0, 56.0], ImageBox::fit($this->pngBytes(512, 512), 200.0, 56.0));
        $this->assertSame([36.0, 36.0], ImageBox::fit($this->pngBytes(512, 512), 36.0, 36.0));
    }

    public function testWideImageIsLimitedByTheBoxWidth(): void
    {
        [$width, $height] = ImageBox::fit($this->pngBytes(4000, 200), 200.0, 56.0);

        $this->assertSame(200.0, $width);
        $this->assertEqualsWithDelta(10.0, $height, 0.001);
        $this->assertEqualsWithDelta(4000 / 200, $width / $height, 0.001, 'Seitenverhältnis verzerrt.');
    }

    public function testTallImageIsLimitedByTheBoxHeight(): void
    {
        [$width, $height] = ImageBox::fit($this->pngBytes(300, 1200), 200.0, 56.0);

        $this->assertSame(56.0, $height);
        $this->assertEqualsWithDelta(14.0, $width, 0.001);
    }

    /**
     * Ein unlesbares Bild wird in keinem Programm angezeigt. Statt zu raten bleibt es beim
     * Quadrat - und zwar an der kürzeren Seite, damit es nie über den Rahmen hinausragt.
     */
    public function testUnreadableBytesFallBackToTheLargestFittingSquare(): void
    {
        $this->assertSame([56.0, 56.0], ImageBox::fit('kein Bild', 200.0, 56.0));
        $this->assertSame([36.0, 36.0], ImageBox::fit('', 36.0, 36.0));
    }

    public function testResultNeverExceedsTheBox(): void
    {
        foreach ([[4000, 200], [200, 4000], [1, 1], [999, 1000]] as [$sourceWidth, $sourceHeight]) {
            [$width, $height] = ImageBox::fit($this->pngBytes($sourceWidth, $sourceHeight), 200.0, 56.0);

            $this->assertLessThanOrEqual(200.0, $width);
            $this->assertLessThanOrEqual(56.0, $height);
            $this->assertGreaterThan(0.0, $width);
            $this->assertGreaterThan(0.0, $height);
        }
    }
}
