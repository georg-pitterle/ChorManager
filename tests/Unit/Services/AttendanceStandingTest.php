<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AttendanceStanding;
use PHPUnit\Framework\TestCase;

/**
 * Rechenregeln der Mitgliedersicht: Serie, Abstand zu "Immer dabei" und der
 * Stimmgruppen-Vergleich.
 */
final class AttendanceStandingTest extends TestCase
{
    public function testStreakCountsFromTheNewestEventUntilTheFirstNonPresence(): void
    {
        $this->assertSame(2, AttendanceStanding::streak(['present', 'present', 'excused', 'present']));
        $this->assertSame(0, AttendanceStanding::streak(['excused', 'present']));
        $this->assertSame(0, AttendanceStanding::streak([null, 'present']), 'Ohne Erfassung keine Anwesenheit.');
        $this->assertSame(0, AttendanceStanding::streak([]));
    }

    public function testEventsToThresholdIsTheSmallestSufficientNumber(): void
    {
        // (4 + 14) / (6 + 14) = 90 %, (4 + 13) / 19 = 89,5 %.
        $this->assertSame(14, AttendanceStanding::eventsToThreshold(4, 6, 90, 3));
        $this->assertSame(0, AttendanceStanding::eventsToThreshold(9, 10, 90, 3), 'Genau 90 % reicht.');
        $this->assertSame(1, AttendanceStanding::eventsToThreshold(8, 9, 90, 3));
    }

    public function testEventsToThresholdHonoursTheMinimumNumberOfEvents(): void
    {
        $this->assertSame(2, AttendanceStanding::eventsToThreshold(1, 1, 90, 3), 'Erst ab drei Terminen.');
        $this->assertSame(3, AttendanceStanding::eventsToThreshold(0, 0, 90, 3));
    }

    public function testFullThresholdCannotBeReachedAfterAMiss(): void
    {
        $this->assertSame(PHP_INT_MAX, AttendanceStanding::eventsToThreshold(3, 4, 100, 3));
        $this->assertSame(0, AttendanceStanding::eventsToThreshold(4, 4, 100, 3));
    }

    public function testRaceKeepsChoirOrderAndSkipsGroupsWithoutAverage(): void
    {
        $race = AttendanceStanding::voiceGroupRace([
            ['name' => 'Sopran', 'member_count' => 3, 'average' => 70.0],
            ['name' => 'Alt', 'member_count' => 2, 'average' => 82.5],
            ['name' => 'Tenor', 'member_count' => 1, 'average' => null],
            ['name' => 'Ohne Stimmgruppe', 'member_count' => 4, 'average' => 99.0],
        ], 'Sopran', 'Ohne Stimmgruppe');

        $this->assertSame(['Sopran', 'Alt'], array_column($race, 'name'));
        $this->assertSame([12.5, 0.0], array_column($race, 'gap'));
        $this->assertSame([false, true], array_column($race, 'is_leader'));
        $this->assertSame([true, false], array_column($race, 'is_own'));
    }

    public function testTiedLeadersAreBothMarked(): void
    {
        $race = AttendanceStanding::voiceGroupRace([
            ['name' => 'Sopran', 'member_count' => 3, 'average' => 80.0],
            ['name' => 'Bass', 'member_count' => 2, 'average' => 80.0],
        ], null, 'Ohne Stimmgruppe');

        $this->assertSame([true, true], array_column($race, 'is_leader'));
    }
}
