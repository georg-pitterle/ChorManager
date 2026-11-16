<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendance;
use App\Models\EventRegistration;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Schreibt Anwesenheiten und Vertretungsanmeldungen eines Termins - ganz oder gar
 * nicht.
 *
 * AttendanceController und RegistrationController öffneten die Transaktion selbst
 * (`beginTransaction`, `commit`, `rollBack` von Hand). Hier läuft jede Methode in
 * `Capsule::connection()->transaction()`, das bei einer Ausnahme zurückrollt und sie
 * weiterreicht.
 */
class ParticipationWriter
{
    /**
     * @param list<array{event_id: int, user_id: int, status: string, note: string}> $rowsToWrite
     * @param list<int> $userIdsToClear
     */
    public function saveAttendances(int $eventId, array $rowsToWrite, array $userIdsToClear): void
    {
        Capsule::connection()->transaction(function () use ($eventId, $rowsToWrite, $userIdsToClear): void {
            if ($rowsToWrite !== []) {
                // upsert statt updateOrCreate: ein gleichzeitiger Ersteintrag
                // derselben Zeile liefe sonst in den Unique-Key und würde das
                // gesamte Formular zurückrollen.
                Attendance::upsert($rowsToWrite, ['event_id', 'user_id'], ['status', 'note']);
            }

            if ($userIdsToClear !== []) {
                Attendance::where('event_id', $eventId)
                    ->whereIn('user_id', $userIdsToClear)
                    ->delete();
            }
        });
    }

    /**
     * @param list<array{user_id: int, status: string, note: ?string}> $entries
     * @param list<int> $userIdsToClear
     */
    public function saveProxyRegistrations(
        int $eventId,
        array $entries,
        array $userIdsToClear,
        int $actorId
    ): void {
        Capsule::connection()->transaction(function () use ($eventId, $entries, $userIdsToClear, $actorId): void {
            foreach ($entries as $entry) {
                EventRegistration::updateOrCreate(
                    ['event_id' => $eventId, 'user_id' => $entry['user_id']],
                    ['status' => $entry['status'], 'note' => $entry['note'], 'updated_by' => $actorId]
                );
            }

            if ($userIdsToClear !== []) {
                EventRegistration::where('event_id', $eventId)
                    ->whereIn('user_id', $userIdsToClear)
                    ->delete();
            }
        });
    }
}
