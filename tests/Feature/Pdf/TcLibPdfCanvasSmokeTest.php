<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\Pdf\PdfCanvas;
use App\Services\Pdf\TcLibPdfCanvas;
use PHPUnit\Framework\TestCase;

final class TcLibPdfCanvasSmokeTest extends TestCase
{
    public function testProducesMultiPagePdfBytes(): void
    {
        $canvas = new TcLibPdfCanvas();
        $this->assertInstanceOf(PdfCanvas::class, $canvas);

        $canvas->addPage();
        $canvas->text($canvas->contentLeft(), $canvas->contentTop(), 'Übertrag-Test äöüß', 10.0, 'B');
        $canvas->line($canvas->contentLeft(), $canvas->contentTop() + 4, $canvas->contentLeft() + 100, $canvas->contentTop() + 4);
        $canvas->addPage();
        $canvas->text($canvas->contentLeft(), $canvas->contentTop(), 'Seite 2', 10.0);

        $bytes = $canvas->output();

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(2, $canvas->pageCount());
        $this->assertGreaterThan(0.0, $canvas->stringWidth('abc', 10.0));
    }

    public function testAddPageProducesLandscapeA4(): void
    {
        $canvas = new TcLibPdfCanvas();
        $canvas->addPage();

        // A4 Querformat: Inhaltsbreite = 841.89 - 2*40 ≈ 761.89pt. Hochformat
        // läge bei 595.28 - 2*40 ≈ 515.28pt. 600.0 liegt sicher dazwischen und
        // schlägt nur an, wenn die Seite tatsächlich im Querformat gerendert wird.
        $this->assertGreaterThan(600.0, $canvas->contentWidth());
    }

    public function testImageDrawsRasterBytesWithoutThrowing(): void
    {
        $canvas = new TcLibPdfCanvas();
        $canvas->addPage();

        // Minimales gültiges 1x1-PNG (rote Pixel), rein zum Beleg, dass image()
        // rohe Bild-Bytes ohne Fehler in den Seiteninhalt schreibt.
        $pngBytes = \base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        );

        $canvas->image($pngBytes, $canvas->contentLeft(), $canvas->contentTop(), 20.0, 20.0);

        $bytes = $canvas->output();

        $this->assertStringStartsWith('%PDF', $bytes);
    }
}
