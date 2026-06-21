<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\BudgetService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BudgetServiceBehaviorTest extends TestCase
{
    public function testDatesForYearReturnsCarbonRange(): void
    {
        $service = new BudgetService();

        [$start, $end] = $service->datesForYear(2025, 1, 9);

        $this->assertInstanceOf(Carbon::class, $start);
        $this->assertInstanceOf(Carbon::class, $end);
        $this->assertSame('2025-09-01', $start->format('Y-m-d'));
        $this->assertSame('2026-08-31', $end->format('Y-m-d'));
    }

    public function testDatesForYearJanuaryStart(): void
    {
        $service = new BudgetService();

        [$start, $end] = $service->datesForYear(2024, 1, 1);

        $this->assertSame('2024-01-01', $start->format('Y-m-d'));
        $this->assertSame('2024-12-31', $end->format('Y-m-d'));
    }

    public function testDatesForYearClampsInvalidDayInsteadOfOverflowing(): void
    {
        $service = new BudgetService();

        // 31 April does not exist; must clamp to 30.04 instead of overflowing to 01.05.
        [$start, $end] = $service->datesForYear(2025, 31, 4);

        $this->assertSame('2025-04-30', $start->format('Y-m-d'));
        $this->assertSame('2026-04-29', $end->format('Y-m-d'));
    }

    public function testDatesForYearClampsFebruaryAcrossLeapBoundary(): void
    {
        $service = new BudgetService();

        // Fiscal start 29.02: 2025 is not a leap year -> clamp to 28.02.
        [$start] = $service->datesForYear(2025, 29, 2);

        $this->assertSame('2025-02-28', $start->format('Y-m-d'));
    }

    public function testGetFiscalConfigMethodReturnsThreeElements(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Services/BudgetService.php');

        $this->assertIsString($content);
        $this->assertStringContainsString('getFiscalConfig', $content);
        $this->assertStringContainsString("@return array{0: int, 1: int, 2: string}", $content);
        $this->assertStringContainsString("'01.09.'", $content);
    }

    public function testFiscalYearEndIsOneDayBeforeStartOfNextYear(): void
    {
        $service = new BudgetService();

        [$start, $end] = $service->datesForYear(2025, 1, 9);
        $nextYearStart = Carbon::create(2026, 9, 1);

        $this->assertSame('2025-09-01', $start->format('Y-m-d'));
        $this->assertTrue($end->addDay()->eq($nextYearStart));
    }

    public function testComputeActualReturnsDecimalString(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Services/BudgetService.php');

        $this->assertIsString($content);
        $this->assertStringContainsString("number_format((float) \$sum, 2, '.', '')", $content);
    }

    public function testGetOverviewReturnsExpectedKeys(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Services/BudgetService.php');

        $this->assertIsString($content);
        $this->assertStringContainsString("'income'", $content);
        $this->assertStringContainsString("'expense'", $content);
        $this->assertStringContainsString("'totals'", $content);
        $this->assertStringContainsString("'planned'", $content);
        $this->assertStringContainsString("'actual'", $content);
        $this->assertStringContainsString("'diff'", $content);
    }
}
