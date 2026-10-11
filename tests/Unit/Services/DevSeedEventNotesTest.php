<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DevSeedService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Bemerkungen im Seed: Neben den ältesten Terminen tragen auch kommende welche, sonst
 * zeigen Liste und Plan das Bemerkungs-Abzeichen nie - die Liste reicht höchstens ein
 * Jahr zurück, die ältesten Termine liegen weiter zurück.
 */
final class DevSeedEventNotesTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-11 12:00:00');
    }

    public function testEverySecondOfTheNextEightUpcomingEventsGetsNotesStartingWithTheNextOne(): void
    {
        $starts = [];
        for ($day = 1; $day <= 10; $day++) {
            // IDs absichtlich gegen die Zeitfolge vergeben: entscheidend ist der Beginn.
            $starts[100 - $day] = sprintf('2026-10-%02d 19:00:00', 11 + $day);
        }

        $selection = DevSeedService::noteEventIds($starts, $this->now, 0);

        $this->assertSame([99, 97, 95, 93], $selection['upcoming']);
    }

    public function testPastEventsAndTheOneAlreadyRunningAreNotUpcoming(): void
    {
        $starts = [
            1 => '2026-10-10 19:00:00',
            2 => '2026-10-11 11:00:00',
            3 => '2026-10-11 19:00:00',
        ];

        $selection = DevSeedService::noteEventIds($starts, $this->now, 0);

        $this->assertSame([3], $selection['upcoming']);
    }

    public function testHistoryKeepsTheOldestIdsAndUpcomingSkipsThem(): void
    {
        $starts = [
            5 => '2026-10-12 19:00:00',
            1 => '2024-05-02 19:00:00',
            2 => '2024-05-09 19:00:00',
            3 => '2026-10-13 19:00:00',
            4 => '2026-10-14 19:00:00',
        ];

        $selection = DevSeedService::noteEventIds($starts, $this->now, 3);

        $this->assertSame([1, 2, 3], $selection['history']);
        // Termin 3 hat schon Bemerkungen aus der Historie; kommend sind 5 und 4, davon jeder zweite.
        $this->assertSame([5], $selection['upcoming']);
    }

    public function testWithoutUpcomingEventsNothingIsAdded(): void
    {
        $selection = DevSeedService::noteEventIds([1 => '2025-01-01 19:00:00'], $this->now, 36);

        $this->assertSame([1], $selection['history']);
        $this->assertSame([], $selection['upcoming']);
    }
}
