<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EventScheduleFormatter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Die Beschriftung, mit der die Terminseite Zeit greifbar macht: Wochentag,
 * relative Angabe für die nächsten Tage und Monatsgruppen. Gerechnet wird in
 * Kalendertagen, nicht in Stunden - eine Probe morgen um 9 Uhr ist "morgen",
 * auch wenn es jetzt 22 Uhr ist.
 */
final class EventScheduleFormatterTest extends TestCase
{
    private EventScheduleFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new EventScheduleFormatter();
    }

    public function testWeekdayIsTheGermanShortName(): void
    {
        $this->assertSame('Mo', $this->formatter->weekday(new CarbonImmutable('2026-10-12 10:00')));
        $this->assertSame('Do', $this->formatter->weekday(new CarbonImmutable('2026-10-15 10:00')));
        $this->assertSame('So', $this->formatter->weekday(new CarbonImmutable('2026-10-18 10:00')));
    }

    public function testMonthLabelIsGermanMonthAndYear(): void
    {
        $this->assertSame('Oktober 2026', $this->formatter->monthLabel(new CarbonImmutable('2026-10-01')));
        $this->assertSame('März 2027', $this->formatter->monthLabel(new CarbonImmutable('2027-03-31')));
    }

    public function testRelativeLabelCountsCalendarDays(): void
    {
        $now = new CarbonImmutable('2026-10-12 22:00');

        $this->assertSame('heute', $this->formatter->relativeLabel(new CarbonImmutable('2026-10-12 23:00'), $now));
        $this->assertSame('morgen', $this->formatter->relativeLabel(new CarbonImmutable('2026-10-13 09:00'), $now));
        $this->assertSame('in 2 Tagen', $this->formatter->relativeLabel(new CarbonImmutable('2026-10-14 09:00'), $now));
        $this->assertSame('in 6 Tagen', $this->formatter->relativeLabel(new CarbonImmutable('2026-10-18 09:00'), $now));
    }

    public function testRelativeLabelStaysSilentBeyondAWeekAndForThePast(): void
    {
        $now = new CarbonImmutable('2026-10-12 10:00');

        $this->assertNull($this->formatter->relativeLabel(new CarbonImmutable('2026-10-19 09:00'), $now));
        $this->assertNull($this->formatter->relativeLabel(new CarbonImmutable('2026-10-11 09:00'), $now));
    }

    public function testGroupByMonthKeepsOrderAndLabelsEachMonth(): void
    {
        $events = [
            (object) ['title' => 'A', 'starts_at' => new CarbonImmutable('2026-10-13 19:00')],
            (object) ['title' => 'B', 'starts_at' => new CarbonImmutable('2026-10-20 19:00')],
            (object) ['title' => 'C', 'starts_at' => new CarbonImmutable('2026-11-03 19:00')],
        ];

        $groups = $this->formatter->groupByMonth($events);

        $this->assertCount(2, $groups);
        $this->assertSame('Oktober 2026', $groups[0]['label']);
        $this->assertSame(['A', 'B'], array_map(static fn ($e) => $e->title, $groups[0]['events']));
        $this->assertSame('November 2026', $groups[1]['label']);
        $this->assertSame(['C'], array_map(static fn ($e) => $e->title, $groups[1]['events']));
    }
}
