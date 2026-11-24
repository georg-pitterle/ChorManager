<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DevSeedService;
use PHPUnit\Framework\TestCase;

/**
 * Buchungsabschluss im Seed: Die Stichtage fallen auf Geschäftsjahresenden, und das
 * zuletzt beendete Jahr bleibt offen (es gilt als noch in Prüfung). Vorher lagen sie
 * fest auf dem 31.12., wodurch ein Geschäftsjahr ab 01.09. mitten im Jahr gesperrt war.
 */
final class DevSeedFinanceClosingDatesTest extends TestCase
{
    public function testClosingDatesAreTheEndsOfTheTwoFiscalYearsBeforeTheLastFinishedOne(): void
    {
        // Laufendes Geschäftsjahr 2026/27; 2025/26 ist beendet, aber noch offen.
        $this->assertSame(
            ['2024-08-31', '2025-08-31'],
            DevSeedService::financeClosingDates(2026, 1, 9)
        );
    }

    public function testCalendarFiscalYearClosesOnDecemberThirtyFirst(): void
    {
        $this->assertSame(
            ['2023-12-31', '2024-12-31'],
            DevSeedService::financeClosingDates(2026, 1, 1)
        );
    }

    public function testFiscalStartMidMonthEndsTheDayBefore(): void
    {
        $this->assertSame(
            ['2024-07-14', '2025-07-14'],
            DevSeedService::financeClosingDates(2026, 15, 7)
        );
    }
}
