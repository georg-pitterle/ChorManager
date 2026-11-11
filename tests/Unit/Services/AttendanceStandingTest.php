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
            ['name' => 'Sopran', 'member_count' => 3, 'average' => 70.0, 'quota_count' => 3],
            ['name' => 'Alt', 'member_count' => 3, 'average' => 82.5, 'quota_count' => 3],
            ['name' => 'Tenor', 'member_count' => 1, 'average' => null, 'quota_count' => 0],
            ['name' => 'Ohne Stimmgruppe', 'member_count' => 4, 'average' => 99.0, 'quota_count' => 4],
        ], 'Sopran', 'Ohne Stimmgruppe');

        $this->assertSame(['Sopran', 'Alt'], array_column($race, 'name'));
        $this->assertSame([12.5, 0.0], array_column($race, 'gap'));
        $this->assertSame([false, true], array_column($race, 'is_leader'));
        $this->assertSame([true, false], array_column($race, 'is_own'));
    }

    public function testTiedLeadersAreBothMarked(): void
    {
        $race = AttendanceStanding::voiceGroupRace([
            ['name' => 'Sopran', 'member_count' => 3, 'average' => 80.0, 'quota_count' => 3],
            ['name' => 'Bass', 'member_count' => 3, 'average' => 80.0, 'quota_count' => 3],
        ], null, 'Ohne Stimmgruppe');

        $this->assertSame([true, true], array_column($race, 'is_leader'));
    }

    /**
     * Eine einzige Quote hinter dem Durchschnitt heißt: Der Durchschnitt *ist*
     * diese Quote. Die Gruppe tritt deshalb nicht an - sonst stünde der Stand
     * einer namentlich bestimmbaren Person für alle sichtbar auf der Seite.
     */
    public function testGroupCarriedByASingleQuotaDoesNotCompete(): void
    {
        $race = AttendanceStanding::voiceGroupRace([
            ['name' => 'Sopran', 'member_count' => 4, 'average' => 70.0, 'quota_count' => 3],
            ['name' => 'Tenor', 'member_count' => 3, 'average' => 25.0, 'quota_count' => 1],
        ], 'Tenor', 'Ohne Stimmgruppe');

        $this->assertSame(['Sopran'], array_column($race, 'name'));
    }

    /**
     * Auch die eigene Gruppe nicht: Gerechnet wird für alle dasselbe, und was
     * für die Person selbst harmlos wäre, steht bei jeder anderen auf derselben
     * Seite. Der eigene Stand steht ohnehin im eigenen Abschnitt.
     */
    public function testEvenTheOwnGroupNeedsTheMinimumNumberOfQuotas(): void
    {
        $race = AttendanceStanding::voiceGroupRace([
            ['name' => 'Bass', 'member_count' => 2, 'average' => 100.0, 'quota_count' => 2],
        ], 'Bass', 'Ohne Stimmgruppe');

        $this->assertSame([], $race);
    }

    /**
     * Zwei Quoten reichen auch nicht.
     *
     * Wer selbst in der Gruppe ist, kennt seine eigene Quote aus dem eigenen
     * Abschnitt und rechnet die der anderen Person exakt aus: Durchschnitt mal
     * zwei minus eigene. In einem kleinen Chor ist eine Zweier-Stimmgruppe der
     * Regelfall, nicht die Ausnahme - die Lücke wäre also die übliche Lage.
     */
    public function testGroupCarriedByTwoQuotasDoesNotCompete(): void
    {
        $race = AttendanceStanding::voiceGroupRace([
            ['name' => 'Sopran', 'member_count' => 4, 'average' => 70.0, 'quota_count' => 3],
            ['name' => 'Alt', 'member_count' => 3, 'average' => 60.0, 'quota_count' => 2],
        ], 'Alt', 'Ohne Stimmgruppe');

        $this->assertSame(['Sopran'], array_column($race, 'name'));
    }

    /** Eine fehlende Angabe zählt als zu wenig - die sichere Richtung. */
    public function testGroupWithoutQuotaCountDoesNotCompete(): void
    {
        $race = AttendanceStanding::voiceGroupRace([
            ['name' => 'Alt', 'member_count' => 5, 'average' => 80.0],
        ], null, 'Ohne Stimmgruppe');

        $this->assertSame([], $race);
    }

    /**
     * Die Mindestzahl entscheidet über das Antreten, nicht über die Führung:
     * Eine ausgeschlossene Gruppe darf den Abstand der übrigen nicht verschieben.
     */
    public function testExcludedGroupDoesNotSetTheLead(): void
    {
        $race = AttendanceStanding::voiceGroupRace([
            ['name' => 'Sopran', 'member_count' => 3, 'average' => 60.0, 'quota_count' => 3],
            ['name' => 'Alt', 'member_count' => 3, 'average' => 70.0, 'quota_count' => 3],
            ['name' => 'Tenor', 'member_count' => 2, 'average' => 100.0, 'quota_count' => 2],
        ], null, 'Ohne Stimmgruppe');

        $this->assertSame(['Sopran', 'Alt'], array_column($race, 'name'));
        $this->assertSame([10.0, 0.0], array_column($race, 'gap'), 'Führend ist Alt mit 70 %, nicht Tenor.');
        $this->assertSame([false, true], array_column($race, 'is_leader'));
    }
}
