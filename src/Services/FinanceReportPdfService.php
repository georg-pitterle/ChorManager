<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Services\Pdf\CarryTotals;
use App\Services\Pdf\FinanceReportPage;
use App\Services\Pdf\FinanceReportPaginator;
use App\Services\Pdf\FinanceReportRow;
use App\Services\Pdf\PdfCanvas;
use App\Services\Pdf\TextWrapper;

final class FinanceReportPdfService
{
    private const FONT = 10.0;
    private const LINE_HEIGHT = 14.0;
    private const HEADER_HEIGHT = 18.0;
    private const CARRY_HEIGHT = 18.0;
    private const KENNZAHLEN_HEIGHT = 86.0;
    private const TITLE_HEIGHT = 44.0;
    private const LOGO_SIZE = 36.0;
    private const LOGO_TEXT_OFFSET = 46.0;

    // Spaltenanteile (Summe der Nicht-Beschreibung-Spalten wird von der Breite abgezogen).
    private const COL_DATE = 60.0;
    private const COL_NUMBER = 40.0;
    private const COL_METHOD = 45.0;
    private const COL_AMOUNT = 70.0; // je Einnahme/Ausgabe

    public function __construct(private readonly PdfCanvas $canvas)
    {
    }

    public function render(array $reportData): string
    {
        $rows = $this->buildRows($reportData);
        $descWidth = $this->descriptionWidth();

        $rowsWithHeights = [];
        foreach ($rows as $row) {
            $rowsWithHeights[] = [$row, $this->rowHeight($row, $descWidth)];
        }

        $firstPageTop = $this->canvas->contentTop()
            + self::TITLE_HEIGHT + self::KENNZAHLEN_HEIGHT + self::HEADER_HEIGHT;
        $otherPageTop = $this->canvas->contentTop() + self::CARRY_HEIGHT + self::HEADER_HEIGHT;

        $pages = FinanceReportPaginator::paginate(
            $rowsWithHeights,
            $firstPageTop,
            $otherPageTop,
            $this->canvas->contentBottom(),
            self::CARRY_HEIGHT
        );

        $pageNumber = 0;
        $total = count($pages);
        foreach ($pages as $page) {
            $pageNumber++;
            $this->canvas->addPage();
            $this->drawPage($reportData, $page, $descWidth, $pageNumber, $total);
        }

        return $this->canvas->output();
    }

    public function filename(array $reportData): string
    {
        $startYear = substr((string) $reportData['fiscal_start'], -4);
        $endYear = substr((string) $reportData['fiscal_end'], -4);

        return "Kassabuch_Geschäftsjahr_{$startYear}-{$endYear}.pdf";
    }

    /** @return FinanceReportRow[] */
    private function buildRows(array $reportData): array
    {
        $rows = [];
        foreach ($reportData['finances'] as $f) {
            $isIncome = $f->type === 'income';
            $rows[] = new FinanceReportRow(
                // Kassabuch nach Zufluss-Abfluss: das Zahldatum ist maßgeblich.
                ($f->payment_date ?? $f->invoice_date)->format('d.m.Y'),
                (int) $f->running_number,
                (string) $f->description,
                $f->payment_method === 'cash' ? 'Bar' : 'Bank',
                $isIncome ? (float) $f->amount : 0.0,
                $isIncome ? 0.0 : (float) $f->amount,
            );
        }

        return $rows;
    }

    private function descriptionWidth(): float
    {
        $fixed = self::COL_DATE + self::COL_NUMBER + self::COL_METHOD + (2 * self::COL_AMOUNT);

        return max(60.0, $this->canvas->contentWidth() - $fixed);
    }

    private function rowHeight(FinanceReportRow $row, float $descWidth): float
    {
        $lines = TextWrapper::wrap(
            $row->description,
            $descWidth,
            self::FONT,
            fn (string $t, float $s): float => $this->canvas->stringWidth($t, $s)
        );

        return max(1, count($lines)) * self::LINE_HEIGHT;
    }

    private function drawPage(
        array $reportData,
        FinanceReportPage $page,
        float $descWidth,
        int $pageNumber,
        int $totalPages
    ): void {
        $left = $this->canvas->contentLeft();
        $y = $this->canvas->contentTop();

        if ($page->isFirst) {
            $logoBytes = $this->logoBytes();
            $textLeft = $left;
            if ($logoBytes !== null) {
                $this->canvas->image($logoBytes, $left, $y, self::LOGO_SIZE, self::LOGO_SIZE);
                $textLeft = $left + self::LOGO_TEXT_OFFSET;
            }
            $chorName = $this->appName();
            $this->canvas->text($textLeft, $y, $chorName, 14.0, 'B');
            $this->canvas->text(
                $textLeft,
                $y + 18.0,
                "Kassabuch Geschäftsjahr {$reportData['fiscal_start']} – {$reportData['fiscal_end']}",
                11.0,
                'B'
            );
            $this->canvas->text(
                $left + $this->canvas->contentWidth() - 110.0,
                $y,
                'Erstellt am ' . date('d.m.Y'),
                8.0
            );
            $y += self::TITLE_HEIGHT;
            $y = $this->drawKennzahlen($left, $y, $reportData);
        } else {
            $y = $this->drawCarryRow($left, $y, 'Übertrag', $page->openingCarry, $descWidth);
        }

        $y = $this->drawTableHeader($left, $y, $descWidth);

        foreach ($page->rows as $row) {
            $y = $this->drawRow($left, $y, $row, $descWidth);
        }

        if ($page->isLast) {
            $this->drawGesamtsaldo(
                $left,
                $this->canvas->contentBottom() - self::CARRY_HEIGHT,
                $page->closingCarry,
                $descWidth
            );
        } else {
            $this->drawCarryRow(
                $left,
                $this->canvas->contentBottom() - self::CARRY_HEIGHT,
                'Übertrag',
                $page->closingCarry,
                $descWidth
            );
        }

        $this->drawFooter($left, $pageNumber, $totalPages);
    }

    private function drawKennzahlen(float $left, float $y, array $reportData): float
    {
        $this->canvas->text(
            $left,
            $y,
            'Einnahmen: ' . $this->money($reportData['total_income']) . ' €',
            self::FONT,
            'B'
        );
        $this->canvas->text(
            $left,
            $y + 16.0,
            'Ausgaben: ' . $this->money($reportData['total_expense']) . ' €',
            self::FONT,
            'B'
        );
        $this->canvas->text(
            $left,
            $y + 32.0,
            'Saldo: ' . $this->money($reportData['balance']) . ' €',
            self::FONT,
            'B'
        );

        // Das Kassabuch weist brutto aus - eine Gegenbuchung ist selbst eine
        // Buchung und darf nicht verschwinden (§ 131 BAO). Die Budgetauswertung
        // rechnet stornierte Paare heraus. Ohne diesen Ausweis stünden zwei
        // unterschiedliche Summen ohne erkennbaren Grund nebeneinander.
        $reversedIncome = (float) ($reportData['reversed_income'] ?? 0.0);
        $reversedExpense = (float) ($reportData['reversed_expense'] ?? 0.0);
        if ($reversedIncome > 0.0 || $reversedExpense > 0.0) {
            $this->canvas->text(
                $left,
                $y + 48.0,
                sprintf(
                    'davon Storno: %s € Einnahmen, %s € Ausgaben (im Saldo aufgehoben)',
                    $this->money($reversedIncome),
                    $this->money($reversedExpense)
                ),
                self::FONT,
                ''
            );
        }

        return $y + self::KENNZAHLEN_HEIGHT;
    }

    private function drawTableHeader(float $left, float $y, float $descWidth): float
    {
        $x = $left;
        $columns = [
            ['Datum', self::COL_DATE],
            ['Nr.', self::COL_NUMBER],
            ['Beschreibung', $descWidth],
            ['Art', self::COL_METHOD],
            ['Einnahme', self::COL_AMOUNT],
            ['Ausgabe', self::COL_AMOUNT],
        ];
        foreach ($columns as [$label, $width]) {
            $this->canvas->text($x, $y, $label, self::FONT, 'B');
            $x += $width;
        }
        $this->canvas->line(
            $left,
            $y + self::HEADER_HEIGHT - 4,
            $left + $this->canvas->contentWidth(),
            $y + self::HEADER_HEIGHT - 4
        );

        return $y + self::HEADER_HEIGHT;
    }

    private function drawRow(
        float $left,
        float $y,
        FinanceReportRow $row,
        float $descWidth
    ): float {
        $lines = TextWrapper::wrap(
            $row->description,
            $descWidth,
            self::FONT,
            fn (string $t, float $s): float => $this->canvas->stringWidth($t, $s)
        );
        $x = $left;
        $this->canvas->text($x, $y, $row->date, self::FONT);
        $x += self::COL_DATE;
        $this->canvas->text($x, $y, '#' . $row->runningNumber, self::FONT);
        $x += self::COL_NUMBER;
        foreach ($lines as $i => $line) {
            $this->canvas->text($x, $y + ($i * self::LINE_HEIGHT), $line, self::FONT);
        }
        $x += $descWidth;
        $this->canvas->text($x, $y, $row->method, self::FONT);
        $x += self::COL_METHOD;
        $this->canvas->text($x, $y, $row->income > 0 ? $this->money($row->income) : '', self::FONT);
        $x += self::COL_AMOUNT;
        $this->canvas->text($x, $y, $row->expense > 0 ? $this->money($row->expense) : '', self::FONT);

        return $y + (max(1, count($lines)) * self::LINE_HEIGHT);
    }

    private function drawCarryRow(float $left, float $y, string $label, CarryTotals $carry, float $descWidth): float
    {
        $this->canvas->text($left, $y, $label . ':', self::FONT, 'B');
        $saldoX = $left + self::COL_DATE + self::COL_NUMBER;
        $this->canvas->text(
            $saldoX,
            $y,
            'Saldo: ' . $this->money($carry->balance()) . ' €',
            self::FONT,
            'B'
        );
        $incomeX = $left + self::COL_DATE + self::COL_NUMBER + $descWidth + self::COL_METHOD;
        $this->canvas->text($incomeX, $y, $this->money($carry->income), self::FONT, 'B');
        $this->canvas->text($incomeX + self::COL_AMOUNT, $y, $this->money($carry->expense), self::FONT, 'B');

        return $y + self::CARRY_HEIGHT;
    }

    private function drawGesamtsaldo(float $left, float $y, CarryTotals $carry, float $descWidth): float
    {
        return $this->drawCarryRow($left, $y, 'Gesamtsaldo', $carry, $descWidth);
    }

    private function drawFooter(float $left, int $pageNumber, int $totalPages): void
    {
        $y = $this->canvas->contentBottom() + 6.0;
        $this->canvas->text(
            $left + $this->canvas->contentWidth() - 80.0,
            $y,
            "Seite {$pageNumber} von {$totalPages}",
            8.0
        );
    }

    private function appName(): string
    {
        try {
            $name = AppSetting::query()->find('app_name')?->setting_value;
        } catch (\Throwable) {
            $name = null;
        }

        return $name !== null && $name !== '' ? (string) $name : 'Chor-Manager';
    }

    private function logoBytes(): ?string
    {
        try {
            $content = AppSetting::query()->find('app_logo')?->binary_content;
            if ($content !== null && $content !== '') {
                return (string) $content;
            }
        } catch (\Throwable) {
            // Fällt unten auf die Datei-Variante zurück.
        }

        $fallbackPath = dirname(__DIR__, 2) . '/public/icons/icon-512.png';
        $bytes = @file_get_contents($fallbackPath);

        return $bytes !== false && $bytes !== '' ? $bytes : null;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
