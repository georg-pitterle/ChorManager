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
     * Gezählt wird allein, wen die Zielgruppe des Termins umfasst. Die Spalte
     * "Anwesend (Ist)" steht in der Anmeldeauswertung neben "Zusagen gesamt" und
     * "Rücklauf", und die beiden rechnen über genau diese Menge - ohne die
     * Eingrenzung zählte sie eine andere und war mit ihren Nachbarinnen nicht
     * vergleichbar. Beim Eintragen fallen die Mengen zusammen
     * (AttendanceController::save() lässt nur Berechtigte durch); auseinander
     * laufen sie erst, wenn die Zielgruppe sich danach ändert - jemand verlässt
     * das Projekt, wechselt die Stimmgruppe oder wird archiviert. Der
     * Anwesenheitseintrag bleibt dabei stehen, und "Anwesend 18" konnte neben
     * "Zusagen 15 von 16" landen.
     *
     * Die Eingrenzung läuft über die Kennungen und nicht in der Bedingung der
     * Abfrage: Jeder Termin hat seine eigene Zielgruppe, in SQL wäre das je
     * Termin ein eigenes ODER-Paar aus Termin und Kennungsliste. Geholt werden
     * deshalb die Paare (Termin, Mitglied) der Anwesenden - eine Abfrage wie
     * zuvor - und gezählt wird danach gegen die Zuordnung.
     *
     * Ein Termin, der in `$eligibleUserIdsByEvent` fehlt, bekommt 0: Keine
     * Zielgruppe heißt "niemand gehört dazu" und nicht "also alle".
     *
     * @param list<int> $eventIds
     * @param array<int, list<int>> $eligibleUserIdsByEvent Termin => berechtigte Mitglieder
     * @return array<int, int> jeder übergebene Termin, auch mit 0
     */
    public function presentCountsByEvent(array $eventIds, array $eligibleUserIdsByEvent): array
    {
        if ($eventIds === []) {
            return [];
        }

        // Termine ohne Anwesende bekommen ausdrücklich 0 - ein fehlender Eintrag
        // würde in der Spalte sonst als "keine Liste geführt" erscheinen.
        $counts = array_fill_keys(array_map('intval', $eventIds), 0);

        $eligible = [];
        foreach ($eligibleUserIdsByEvent as $eventId => $userIds) {
            $eligible[(int) $eventId] = array_fill_keys(array_map('intval', $userIds), true);
        }

        $rows = Attendance::whereIn('event_id', $eventIds)
            ->where('status', Attendance::STATUS_PRESENT)
            ->get(['event_id', 'user_id']);

        foreach ($rows as $row) {
            $eventId = (int) $row->event_id;
            if (!isset($counts[$eventId])) {
                continue;
            }
            if (!isset($eligible[$eventId][(int) $row->user_id])) {
                continue;
            }
            $counts[$eventId]++;
        }

        return $counts;
    }
}
