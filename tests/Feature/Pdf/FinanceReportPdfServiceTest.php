<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\FinanceReportPdfService;
use App\Services\Pdf\PdfCanvas;
use PHPUnit\Framework\TestCase;

final class FinanceReportPdfServiceTest extends TestCase
{
    private function fakeCanvas(): PdfCanvas
    {
        return new class implements PdfCanvas {
            public int $pages = 0;
            public array $texts = [];
            public function addPage(): void
            {
                $this->pages++;
            }
            public function contentTop(): float
            {
                return 0.0;
            }
            public function contentBottom(): float
            {
                return 100.0;
            }
            public function contentLeft(): float
            {
                return 0.0;
            }
            public function contentWidth(): float
            {
                return 500.0;
            }
            public function stringWidth(string $text, float $fontSize): float
            {
                return strlen($text) * ($fontSize / 10.0);
            }
            public function text(float $x, float $y, string $text, float $fontSize, string $style = ''): void
            {
                $this->texts[] = $text;
            }
            public function line(float $x1, float $y1, float $x2, float $y2): void
            {
            }
            public array $images = [];
            public function image(string $imageData, float $x, float $y, float $width, float $height): void
            {
                $this->images[] = [$x, $y, $width, $height];
            }
            public function pageCount(): int
            {
                return $this->pages;
            }
            public function output(): string
            {
                return '%PDF-fake';
            }
        };
    }

    private function reportData(int $rowCount): array
    {
        $finances = [];
        for ($i = 1; $i <= $rowCount; $i++) {
            $finances[] = (object) [
                'invoice_date' => \Carbon\Carbon::parse('2025-10-01'),
                'running_number' => $i,
                'description' => "Buchung Nummer $i mit einer etwas laengeren Beschreibung",
                'payment_method' => $i % 2 === 0 ? 'bank_transfer' : 'cash',
                'type' => $i % 3 === 0 ? 'expense' : 'income',
                'amount' => 10.0 + $i,
            ];
        }

        return [
            'finances' => collect($finances),
            'total_income' => 1000.0,
            'total_expense' => 200.0,
            'balance' => 800.0,
            'fiscal_start' => '01.09.2025',
            'fiscal_end' => '31.08.2026',
            'selected_year' => 2025,
        ];
    }

    public function testRendersMultiplePagesForManyRows(): void
    {
        $canvas = $this->fakeCanvas();
        $service = new FinanceReportPdfService($canvas);

        $bytes = $service->render($this->reportData(60));

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(1, $canvas->pageCount(), 'Viele Zeilen müssen mehrere Seiten erzeugen.');
    }

    public function testWritesUebertragLabelOnPageBreak(): void
    {
        $canvas = $this->fakeCanvas();
        $service = new FinanceReportPdfService($canvas);

        $service->render($this->reportData(60));

        $joined = implode('|', $canvas->texts);
        $this->assertStringContainsString('Übertrag', $joined);
    }

    public function testFilenameUsesFiscalYears(): void
    {
        $service = new FinanceReportPdfService($this->fakeCanvas());
        $name = $service->filename($this->reportData(1));
        $this->assertSame('Kassabuch_Geschäftsjahr_2025-2026.pdf', $name);
    }

    public function testDrawsLogoOnlyOnFirstPage(): void
    {
        $canvas = $this->fakeCanvas();
        $service = new FinanceReportPdfService($canvas);

        $service->render($this->reportData(60));

        $this->assertGreaterThan(1, $canvas->pageCount(), 'Fixture muss mehrere Seiten erzeugen.');
        $this->assertCount(
            1,
            $canvas->images,
            'Das Logo darf nur einmal (auf Seite 1) gezeichnet werden, nicht auf Folgeseiten.'
        );
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image, 'GD konnte kein Testbild anlegen.');

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * Der Kopf zeichnete das Logo in feste 36x36 Punkte. Ein Schriftzug im Querformat wurde
     * damit zusammengestaucht, ein Wappen im Hochformat in die Breite gezogen.
     */
    public function testWideLogoKeepsItsAspectRatioInTheHeader(): void
    {
        [$width, $height, $textOffset] = FinanceReportPdfService::logoLayout($this->pngBytes(360, 90));

        $this->assertEqualsWithDelta(36.0, $width, 0.001);
        $this->assertEqualsWithDelta(9.0, $height, 0.001);
        $this->assertEqualsWithDelta(4.0, $width / $height, 0.001, 'Seitenverhältnis verzerrt.');
        $this->assertEqualsWithDelta(46.0, $textOffset, 0.001, 'Der Abstand folgt der Logobreite.');
    }

    public function testTallLogoStaysInsideTheHeaderHeight(): void
    {
        [$width, $height, $textOffset] = FinanceReportPdfService::logoLayout($this->pngBytes(90, 360));

        $this->assertEqualsWithDelta(9.0, $width, 0.001);
        $this->assertEqualsWithDelta(36.0, $height, 0.001);
        $this->assertEqualsWithDelta(19.0, $textOffset, 0.001, 'Schmales Logo rückt den Titel nach links.');
    }

    /**
     * Das mitgelieferte Logo ist quadratisch. Der Kopf muss deshalb aussehen wie bisher,
     * sonst verschiebt der Fix jeden bestehenden Bericht.
     */
    public function testSquareLogoKeepsTheKnownHeaderGeometry(): void
    {
        $this->assertSame([36.0, 36.0, 46.0], FinanceReportPdfService::logoLayout($this->pngBytes(512, 512)));
    }

    public function testWithoutLogoTheTitleStartsAtTheLeftEdge(): void
    {
        $this->assertSame([0.0, 0.0, 0.0], FinanceReportPdfService::logoLayout(null));
    }

    /**
     * Nicht nur die Rechnung, auch der Weg in die Zeichenfläche muss stimmen: ein breites
     * Logo darf nicht als Quadrat auf dem Blatt landen.
     */
    public function testHeaderDrawsTheLogoWithTheFittedSize(): void
    {
        $canvas = $this->fakeCanvas();
        $service = new FinanceReportPdfService($canvas);

        $service->render($this->reportData(1));

        $this->assertCount(1, $canvas->images);
        [, , $width, $height] = $canvas->images[0];
        [$expectedWidth, $expectedHeight] = FinanceReportPdfService::logoLayout($this->defaultLogoBytes());

        $this->assertEqualsWithDelta($expectedWidth, $width, 0.001);
        $this->assertEqualsWithDelta($expectedHeight, $height, 0.001);
    }

    private function defaultLogoBytes(): string
    {
        $bytes = file_get_contents(dirname(__DIR__, 3) . '/public/icons/icon-512.png');
        self::assertIsString($bytes);

        return $bytes;
    }

    public function testCreationDateOnlyOnFirstPage(): void
    {
        $canvas = $this->fakeCanvas();
        $service = new FinanceReportPdfService($canvas);

        $service->render($this->reportData(60));

        $this->assertGreaterThan(1, $canvas->pageCount(), 'Fixture muss mehrere Seiten erzeugen.');
        $creationDateTexts = array_filter(
            $canvas->texts,
            static fn (string $text): bool => str_contains($text, 'Erstellt am')
        );
        $this->assertCount(
            1,
            $creationDateTexts,
            '"Erstellt am" darf nur einmal (im Kopf von Seite 1) auftauchen, nicht im Footer jeder Seite.'
        );
    }

    public function testCarrySummaryStillShowsSaldo(): void
    {
        // Es gibt keine Saldo-Spalte mehr in der Bewegungstabelle, aber die
        // Übertrag-/Gesamtsaldo-Zeilen müssen weiterhin einen Saldo zeigen.
        // drawKennzahlen() schreibt auf Seite 1 ebenfalls "Saldo: <Betrag> €",
        // daher würde eine reine Existenz-Prüfung von "Saldo:" bereits durch
        // die Kennzahlen-Zeile erfüllt (tautologisch bzgl. der Carry-Zeile).
        // Um die Carry-Zeile gezielt zu belegen, wird mit einer Fixture
        // erzwungen, dass es mindestens zwei Seiten gibt (also mindestens
        // eine Übertrag-Zeile zusätzlich zur Gesamtsaldo-Zeile auf der
        // letzten Seite) und geprüft, dass "Saldo:" mindestens zweimal
        // vorkommt: einmal aus den Kennzahlen (Seite 1) und mindestens
        // einmal aus einer Carry-Zeile (Übertrag und/oder Gesamtsaldo).
        $canvas = $this->fakeCanvas();
        $service = new FinanceReportPdfService($canvas);

        $service->render($this->reportData(60));

        $this->assertGreaterThan(1, $canvas->pageCount(), 'Fixture muss mehrere Seiten erzeugen.');
        $saldoOccurrences = array_filter(
            $canvas->texts,
            static fn (string $text): bool => str_contains($text, 'Saldo:')
        );
        $this->assertGreaterThanOrEqual(
            2,
            count($saldoOccurrences),
            'Neben den Kennzahlen auf Seite 1 muss mindestens eine Carry-Zeile (Übertrag/Gesamtsaldo) '
                . 'ebenfalls einen Saldo anzeigen.'
        );
    }
}
