<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\Pdf\FinanceReportPaginator;
use App\Services\Pdf\FinanceReportRow;
use PHPUnit\Framework\TestCase;

final class FinanceReportPaginatorTest extends TestCase
{
    /** @return array{0: FinanceReportRow, 1: float} */
    private function row(int $n, float $income, float $expense, float $height = 10.0): array
    {
        return [new FinanceReportRow('01.01.2025', $n, "Zeile $n", 'Bar', $income, $expense), $height];
    }

    public function testSinglePageWhenEverythingFits(): void
    {
        $rows = [$this->row(1, 100, 0), $this->row(2, 0, 40)];
        // firstPageTop=0, otherPageTop=0, bottom=100, carryHeight=10 -> Kapazität 90pt, 20pt Inhalt
        $pages = FinanceReportPaginator::paginate($rows, 0.0, 0.0, 100.0, 10.0);

        $this->assertCount(1, $pages);
        $this->assertTrue($pages[0]->isFirst);
        $this->assertTrue($pages[0]->isLast);
        $this->assertSame(0.0, $pages[0]->openingCarry->income);
        $this->assertEqualsWithDelta(100.0, $pages[0]->closingCarry->income, 0.001);
        $this->assertEqualsWithDelta(40.0, $pages[0]->closingCarry->expense, 0.001);
    }

    public function testBreaksToSecondPageAndCarriesForward(): void
    {
        // Kapazität pro Seite bis bottom-carry = 20pt -> je 2 Zeilen à 10pt.
        $rows = [
            $this->row(1, 100, 0),
            $this->row(2, 50, 0),
            $this->row(3, 0, 30),
        ];
        $pages = FinanceReportPaginator::paginate($rows, 0.0, 0.0, 30.0, 10.0);

        $this->assertCount(2, $pages);

        // Seite 1: Zeilen 1+2, schließt mit Übertrag 150/0.
        $this->assertCount(2, $pages[0]->rows);
        $this->assertTrue($pages[0]->isFirst);
        $this->assertFalse($pages[0]->isLast);
        $this->assertEqualsWithDelta(150.0, $pages[0]->closingCarry->income, 0.001);

        // Seite 2: öffnet mit Übertrag 150/0, Zeile 3, schließt 150/30.
        $this->assertFalse($pages[1]->isFirst);
        $this->assertTrue($pages[1]->isLast);
        $this->assertEqualsWithDelta(150.0, $pages[1]->openingCarry->income, 0.001);
        $this->assertEqualsWithDelta(30.0, $pages[1]->closingCarry->expense, 0.001);
        $this->assertEqualsWithDelta(150.0, $pages[1]->closingCarry->income, 0.001);
    }

    public function testFinalClosingCarryEqualsGrandTotals(): void
    {
        $rows = [$this->row(1, 10, 0), $this->row(2, 0, 4), $this->row(3, 6, 0)];
        $pages = FinanceReportPaginator::paginate($rows, 0.0, 0.0, 15.0, 5.0);
        $last = $pages[array_key_last($pages)];

        $this->assertEqualsWithDelta(16.0, $last->closingCarry->income, 0.001);
        $this->assertEqualsWithDelta(4.0, $last->closingCarry->expense, 0.001);
        $this->assertEqualsWithDelta(12.0, $last->closingCarry->balance(), 0.001);
    }

    public function testEmptyRowsProduceSingleEmptyLastPage(): void
    {
        $pages = FinanceReportPaginator::paginate([], 0.0, 0.0, 100.0, 10.0);
        $this->assertCount(1, $pages);
        $this->assertTrue($pages[0]->isFirst);
        $this->assertTrue($pages[0]->isLast);
        $this->assertSame([], $pages[0]->rows);
    }
}
