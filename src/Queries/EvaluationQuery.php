<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Attendance;
use App\Models\EventRegistration;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Die Abfragen der Anmeldeauswertung, je eine für alle Termine bzw. Mitglieder.
 *
 * Sie standen im EvaluationController. Jede Methode fragt einmal für die ganze Liste
 * statt je Zeile einmal - bei einer Probenserie sonst dutzendfach dieselbe Arbeit.
 * Eine leere Eingabe ergibt `[]` ohne Abfrage.
 */
class EvaluationQuery
{
    /**
     * Stimmgruppe je Mitglied, in einer Abfrage für alle beteiligten Mitglieder.
     *
     * Maßgeblich ist wie bisher die erste Stimmgruppe eines Mitglieds; die
     * Reihenfolge über die Kennung entspricht der bisherigen Beziehungsabfrage.
     *
     * @param list<int> $userIds
     * @return array<int, string> Mitglied => Name der Stimmgruppe mit der kleinsten Kennung
     */
    public function firstVoiceGroupNameByUser(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $names = [];
        $rows = Capsule::table('user_voice_groups')
            ->join('voice_groups', 'voice_groups.id', '=', 'user_voice_groups.voice_group_id')
            ->whereIn('user_voice_groups.user_id', $userIds)
            ->orderBy('voice_groups.id')
            ->get(['user_voice_groups.user_id', 'voice_groups.name']);

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            if (!isset($names[$userId])) {
                $names[$userId] = (string) $row->name;
            }
        }

        return $names;
    }

    /**
     * Anmeldestatus je Termin und Mitglied, in einer Abfrage für alle Termine.
     *
     * @param list<int> $eventIds
     * @return array<int, array<int, string>> Termin => Mitglied => Status
     */
    public function registrationStatusByEvent(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $byEvent = [];
        $registrations = EventRegistration::whereIn('event_id', $eventIds)
            ->get(['event_id', 'user_id', 'status']);

        foreach ($registrations as $registration) {
            $byEvent[(int) $registration->event_id][(int) $registration->user_id] = (string) $registration->status;
        }

        return $byEvent;
    }

    /**
     * Zahl der Anwesenden je Termin, in einer Abfrage für alle übergebenen Termine.
     *
     * @param list<int> $eventIds
     * @return array<int, int> jeder übergebene Termin, auch mit 0
     */
    public function presentCountsByEvent(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $counts = [];
        $rows = Attendance::whereIn('event_id', $eventIds)
            ->where('status', Attendance::STATUS_PRESENT)
            ->groupBy('event_id')
            ->get([Capsule::raw('event_id'), Capsule::raw('COUNT(*) as present_count')]);

        foreach ($rows as $row) {
            $counts[(int) $row->event_id] = (int) $row->present_count;
        }

        // Termine ohne Anwesende bekommen ausdrücklich 0 - ein fehlender Eintrag
        // würde in der Spalte sonst als "keine Liste geführt" erscheinen.
        foreach ($eventIds as $eventId) {
            $counts[$eventId] ??= 0;
        }

        return $counts;
    }
}
