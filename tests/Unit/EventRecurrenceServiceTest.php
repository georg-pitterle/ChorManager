<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EventRecurrenceService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Die Wiederholungsregel einer Serie. Zwei Fallen stecken darin:
 *
 *  - "+1 Monat" auf den 31. springt in PHP in den übernächsten Monat und
 *    verschiebt anschließend die ganze Reihe. Ein Monatstermin muss am
 *    Monatsletzten hängen bleiben statt zu wandern.
 *  - Wochentage sind eine Angabe zum wöchentlichen Takt. Bei täglich, monatlich
 *    und jährlich haben sie keine Bedeutung und dürfen die Reihe nicht
 *    stillschweigend verändern.
 */
final class EventRecurrenceServiceTest extends TestCase
{
    private EventRecurrenceService $service;

    protected function setUp(): void
    {
        $this->service = new EventRecurrenceService();
    }

    /**
     * @param list<int> $weekdays
     * @return list<string>
     */
    private function dates(
        string $start,
        string $frequency,
        int $interval,
        array $weekdays,
        string $end
    ): array {
        return array_map(
            static fn(CarbonImmutable $date): string => $date->format('Y-m-d'),
            $this->service->occurrences(
                CarbonImmutable::parse($start),
                $frequency,
                $interval,
                $weekdays,
                CarbonImmutable::parse($end)
            )
        );
    }

    public function testDailySeriesRespectsTheInterval(): void
    {
        $this->assertSame(
            ['2026-03-02', '2026-03-05', '2026-03-08'],
            $this->dates('2026-03-02', 'daily', 3, [], '2026-03-10')
        );
    }

    public function testDailySeriesIgnoresWeekdays(): void
    {
        $this->assertSame(
            ['2026-03-02', '2026-03-03', '2026-03-04'],
            $this->dates('2026-03-02', 'daily', 1, [6, 7], '2026-03-04')
        );
    }

    public function testWeeklySeriesCoversEverySelectedWeekday(): void
    {
        // 2026-03-02 ist ein Montag; Montag (1) und Donnerstag (4) sind gewählt.
        $this->assertSame(
            ['2026-03-02', '2026-03-05', '2026-03-09', '2026-03-12'],
            $this->dates('2026-03-02', 'weekly', 1, [1, 4], '2026-03-15')
        );
    }

    public function testWeeklySeriesSkipsWholeWeeksWithAnInterval(): void
    {
        $this->assertSame(
            ['2026-03-02', '2026-03-05', '2026-03-16', '2026-03-19'],
            $this->dates('2026-03-02', 'weekly', 2, [1, 4], '2026-03-22')
        );
    }

    public function testWeeklySeriesWithoutWeekdaysFollowsTheStartDay(): void
    {
        $this->assertSame(
            ['2026-03-04', '2026-03-11', '2026-03-18'],
            $this->dates('2026-03-04', 'weekly', 1, [], '2026-03-20')
        );
    }

    public function testMonthlySeriesStaysOnTheLastDayInsteadOfDrifting(): void
    {
        // Der 31. existiert nicht in jedem Monat; der Termin rutscht auf den
        // Monatsletzten und kehrt danach auf den 31. zurück.
        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
            $this->dates('2026-01-31', 'monthly', 1, [], '2026-05-01')
        );
    }

    public function testMonthlySeriesRespectsTheInterval(): void
    {
        $this->assertSame(
            ['2026-01-15', '2026-04-15', '2026-07-15'],
            $this->dates('2026-01-15', 'monthly', 3, [], '2026-08-01')
        );
    }

    public function testMonthlySeriesIgnoresWeekdays(): void
    {
        $this->assertSame(
            ['2026-01-15', '2026-02-15'],
            $this->dates('2026-01-15', 'monthly', 1, [1, 2, 3], '2026-03-01')
        );
    }

    public function testYearlySeriesKeepsTheAnniversary(): void
    {
        $this->assertSame(
            ['2026-06-01', '2027-06-01', '2028-06-01'],
            $this->dates('2026-06-01', 'yearly', 1, [], '2028-12-31')
        );
    }

    public function testYearlySeriesOnFebruary29FallsBackToFebruary28(): void
    {
        $this->assertSame(
            ['2028-02-29', '2029-02-28', '2030-02-28'],
            $this->dates('2028-02-29', 'yearly', 1, [], '2030-12-31')
        );
    }

    public function testSeriesEndingBeforeTheStartYieldsNothing(): void
    {
        $this->assertSame([], $this->dates('2026-03-10', 'weekly', 1, [1], '2026-03-01'));
    }

    public function testTheOccurrenceCountIsCapped(): void
    {
        $dates = $this->dates('2020-01-01', 'daily', 1, [], '2030-01-01');

        $this->assertCount(EventRecurrenceService::MAX_OCCURRENCES, $dates);
    }
}
