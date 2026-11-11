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
     * So viele Quoten müssen den Durchschnitt einer Stimmgruppe tragen, damit
     * sie im Vergleich antritt.
     *
     * Der Vergleich ist der einzige Teil der Mitgliedersicht, der etwas über
     * andere Personen sagt, und er darf das nur als Gruppenwert tun. Ruht der
     * Durchschnitt auf einer einzigen Quote, dann *ist* er diese Quote: Die
     * Seite zeigte damit jedem Mitglied den Anwesenheitsstand einer Person, die
     * über die Besetzung (`/evaluations/project-members`, für alle
     * Projektmitglieder offen) namentlich zu bestimmen ist - und zwar auch nach
     * unten, während die Seite Fremdes ausdrücklich nur im Positiven zeigt.
     *
     * Zwei Quoten reichen deshalb nicht: Wer selbst in der Gruppe ist, kennt
     * seine eigene Quote aus dem eigenen Abschnitt derselben Seite und rechnet
     * die der anderen Person exakt aus - Durchschnitt mal zwei minus eigene.
     * Für die Gruppe daneben genügt eine einzige bekannte Quote aus ihr. Ab drei
     * Quoten lässt sich aus dem Durchschnitt und einer bekannten Quote keine
     * einzelne mehr bestimmen; es bleiben zwei Unbekannte in einer Gleichung.
     *
     * Eine Stimmgruppe mit nur einer oder zwei gezählten Personen ist in einem
     * kleinen Chor der Regelfall, nicht die Ausnahme: Gezählt wird nur, wer eine
     * Quote hat, also wen überhaupt ein Pflichttermin betraf. Die Mindestzahl
     * kostet dort also tatsächlich den Vergleich - der Vergleich ist aber
     * Beiwerk, eine ausgerechnete Fremdquote nicht.
     */
    public const MIN_RACE_QUOTAS = 3;

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
     * tritt nicht an; eine Gruppe ohne Durchschnitt (kein Termin galt) ebenso,
     * und ebenso eine, deren Durchschnitt auf weniger als MIN_RACE_QUOTAS
     * Quoten ruht.
     *
     * `quota_count` ist die Zahl der Quoten hinter dem Durchschnitt, nicht die
     * Zahl der Mitglieder: `member_count` zählt auch Personen ohne Quote mit und
     * taugt deshalb nicht als Maß. Eine fehlende Angabe zählt als zu wenig - die
     * sichere Richtung, denn ein zu kleiner Vergleich fällt auf der Seite sofort
     * auf, eine offengelegte Quote nicht.
     *
     * Die Mindestzahl entscheidet nur über das Antreten. Die Führung wird unter
     * den Antretenden bestimmt, damit eine ausgeschlossene Gruppe den Abstand
     * der übrigen nicht verschiebt - und über ihren Abstand nicht doch verrät,
     * wo sie stünde.
     *
     * @param list<array{name: string, member_count: int, average: ?float, quota_count?: int}> $summary
     *     in Chor-Reihenfolge
     * @return list<array{name: string, member_count: int, average: float, is_leader: bool, gap: float, is_own: bool}>
     */
    public static function voiceGroupRace(array $summary, ?string $ownGroup, string $noVoiceGroup): array
    {
        $contenders = array_values(array_filter(
            $summary,
            static fn (array $group): bool => $group['name'] !== $noVoiceGroup
                && $group['average'] !== null
                && (int) ($group['quota_count'] ?? 0) >= self::MIN_RACE_QUOTAS
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
