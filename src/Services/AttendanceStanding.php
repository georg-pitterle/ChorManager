<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendance;

/**
 * Rechenregeln für die Mitgliedersicht der Anwesenheitsquoten.
 *
 * Reine Funktionen ohne Datenbank: Serie, Abstand zur Schwelle und der
 * Stimmgruppen-Vergleich. Die Mitgliedersicht soll anspornen, nicht vorführen -
 * deshalb liefert der Vergleich bewusst keinen Zähler "unter X %".
 */
final class AttendanceStanding
{
    /**
     * Pflichttermine in Folge dabei, vom jüngsten rückwärts gezählt.
     *
     * Entschuldigt bricht die Serie wie unentschuldigt: Sie zählt Anwesenheit,
     * nicht Verlässlichkeit. Auch ein Termin ohne Erfassung bricht sie - gleiche
     * Regel wie bei der Quote, die ihn ebenfalls nicht als Anwesenheit wertet.
     *
     * @param list<?string> $statusesNewestFirst
     */
    public static function streak(array $statusesNewestFirst): int
    {
        $streak = 0;
        foreach ($statusesNewestFirst as $status) {
            if ($status !== Attendance::STATUS_PRESENT) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /**
     * Wie viele weitere Termine in Folge jemand dabei sein muss, um die Schwelle
     * zu erreichen - und mindestens so viele, dass die Mindestzahl an Terminen
     * erreicht ist. 0 heißt: schon erreicht.
     *
     * Gesucht ist das kleinste n mit (present + n) / (total + n) >= threshold %.
     * Ganzzahlig umgestellt: n * (100 - threshold) >= threshold * total - 100 * present.
     */
    public static function eventsToThreshold(int $present, int $total, int $thresholdPercent, int $minEvents): int
    {
        $byMinimum = max(0, $minEvents - $total);
        if ($thresholdPercent >= 100) {
            return $present === $total ? $byMinimum : PHP_INT_MAX;
        }

        $missing = $thresholdPercent * $total - 100 * $present;
        $byQuota = $missing > 0 ? intdiv($missing + (100 - $thresholdPercent) - 1, 100 - $thresholdPercent) : 0;

        return max($byQuota, $byMinimum);
    }

    /**
     * Stimmgruppen im Vergleich: Reihenfolge wie im Chor, die führende Gruppe
     * markiert, jede andere mit ihrem Abstand. Wer keiner Stimmgruppe angehört,
     * tritt nicht an; eine Gruppe ohne Durchschnitt (kein Termin galt) ebenso.
     *
     * @param list<array{name: string, member_count: int, average: ?float}> $summary in Chor-Reihenfolge
     * @return list<array{name: string, member_count: int, average: float, is_leader: bool, gap: float, is_own: bool}>
     */
    public static function voiceGroupRace(array $summary, ?string $ownGroup, string $noVoiceGroup): array
    {
        $contenders = array_values(array_filter(
            $summary,
            static fn (array $group): bool => $group['name'] !== $noVoiceGroup && $group['average'] !== null
        ));
        if ($contenders === []) {
            return [];
        }

        $best = max(array_map(static fn (array $group): float => (float) $group['average'], $contenders));

        return array_map(static fn (array $group): array => [
            'name' => $group['name'],
            'member_count' => $group['member_count'],
            'average' => (float) $group['average'],
            'is_leader' => (float) $group['average'] === $best,
            'gap' => round($best - (float) $group['average'], 1),
            'is_own' => $ownGroup !== null && $group['name'] === $ownGroup,
        ], $contenders);
    }
}
