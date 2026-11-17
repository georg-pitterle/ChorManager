<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\EventSeries;
use App\Util\WallClockOffset;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Schreibt Termine und Terminserien samt Zielgruppe - ganz oder gar nicht.
 *
 * Der EventController schrieb Serie, Termine und je Termin die Zielgruppe
 * einzeln. Scheiterte ein Schritt, blieb ein Teil der Serie in der Datenbank
 * stehen, während der Aufrufer "konnte nicht angelegt werden" meldete. Jede
 * Methode hier läuft deshalb in einer Transaktion.
 *
 * Benachrichtigungen gehören nicht hierher: Sie gehen erst nach dem
 * Festschreiben raus, sonst stünde die Mail in der Warteschlange, während der
 * Termin bei einem Rücksetzer nie entsteht.
 */
class EventWriteService
{
    private EventAudienceService $audience;
    private EventRecurrenceService $recurrence;

    public function __construct(
        ?EventAudienceService $audience = null,
        ?EventRecurrenceService $recurrence = null
    ) {
        $this->audience = $audience ?? new EventAudienceService();
        $this->recurrence = $recurrence ?? new EventRecurrenceService();
    }

    /**
     * Legt einen einzelnen Termin an und setzt seine Zielgruppe.
     *
     * @param array<string, mixed> $attributes Spalten für Event::create()
     * @param array<int, mixed> $audienceSets Ergebnis von EventAudienceService::readRows()
     * @return Event frisch geladen
     */
    public function createSingle(array $attributes, array $audienceSets): Event
    {
        return Capsule::connection()->transaction(function () use ($attributes, $audienceSets): Event {
            $event = Event::create($attributes);
            $this->audience->setAudience($event, $audienceSets);

            return $event->fresh();
        });
    }

    /**
     * Löscht die Termine einer Serie ab dem angegebenen Termin.
     *
     * Gelöscht wird ab dem angeklickten Termin - liegt der in der Vergangenheit,
     * trifft es auch vergangene Termine samt ihrer Anwesenheits- und Anmeldedaten
     * (ON DELETE CASCADE). Die Notizen hängen über entity_type/entity_id und
     * tragen keinen Fremdschlüssel; die Kaskade nimmt sie nicht mit, deshalb
     * räumt EntityCleanupService sie in derselben Transaktion ab.
     *
     * Was gelöscht wird, steht vorher fest: Der Aufrufer braucht die Termine für
     * die Absage, und nach dem Löschen wäre ihre Zielgruppe nicht mehr zu
     * ermitteln.
     *
     * @param list<Event> $events Termine der Serie, vom Aufrufer geprüft
     */
    public function deleteSeriesEvents(array $events, EntityCleanupService $cleanup): int
    {
        if ($events === []) {
            return 0;
        }

        $ids = array_map(static fn (Event $event): int => (int) $event->id, $events);

        return Capsule::connection()->transaction(function () use ($ids, $cleanup): int {
            $cleanup->purgeForEntities('event', $ids);

            return (int) Event::whereIn('id', $ids)->delete();
        });
    }

    /**
     * Legt eine Serie samt allen Terminen an. Takt, Intervall, Wochentage
     * (1 = Mo bis 7 = So) und Serienende sind vom Aufrufer bereits geprüft.
     *
     * @param array{title: string, event_type_id: ?int, location: string,
     *     registration_enabled: bool, attendance_required: bool} $shared
     * @param array{frequency: string, interval: int, weekdays: list<int>,
     *     end: CarbonImmutable} $rule
     * @param ?string $registrationDeadline Y-m-d H:i:s zum ersten Termin oder null
     * @param array<int, mixed> $audienceSets
     * @return list<Event> frisch geladen, in Reihenfolge der Termine
     */
    public function createSeries(
        array $shared,
        string $firstDay,
        string $startTime,
        string $endTime,
        ?string $registrationDeadline,
        array $rule,
        array $audienceSets
    ): array {
        return Capsule::connection()->transaction(
            function () use ($shared, $firstDay, $startTime, $endTime, $registrationDeadline, $rule, $audienceSets): array {
                $series = EventSeries::create([
                    'frequency' => $rule['frequency'],
                    'recurrence_interval' => $rule['interval'],
                    'weekdays' => !empty($rule['weekdays']) ? implode(',', $rule['weekdays']) : null,
                    'end_date' => $rule['end']->format('Y-m-d'),
                ]);

                $occurrences = $this->recurrence->occurrences(
                    CarbonImmutable::parse($firstDay),
                    $rule['frequency'],
                    $rule['interval'],
                    $rule['weekdays'],
                    $rule['end']
                );

                // Anmeldeschluss wie bei der Serienänderung als Vorlauf zum jeweiligen
                // Terminbeginn. Ein absoluter Zeitpunkt schlösse alle Termine der Serie
                // gleichzeitig; ihn stattdessen zu verwerfen, ließe die Eingabe aus dem
                // Formular kommentarlos verschwinden.
                $deadlineLeadSeconds = null;
                if ($shared['registration_enabled'] && $registrationDeadline !== null) {
                    $deadlineLeadSeconds = WallClockOffset::seconds(
                        $firstDay . ' ' . $startTime . ':00',
                        $registrationDeadline
                    );
                }

                $createdEvents = [];
                foreach ($occurrences as $occurrence) {
                    $day = $occurrence->format('Y-m-d');
                    $occurrenceStart = Carbon::parse($day . ' ' . $startTime . ':00');

                    $seriesEvent = Event::create([
                        'title' => $shared['title'],
                        'starts_at' => $occurrenceStart->format('Y-m-d H:i:s'),
                        'ends_at' => $day . ' ' . $endTime . ':00',
                        'event_type_id' => $shared['event_type_id'],
                        'series_id' => $series->id,
                        'location' => $shared['location'],
                        'registration_enabled' => $shared['registration_enabled'],
                        'registration_deadline' => $deadlineLeadSeconds === null
                            ? null
                            : WallClockOffset::shift($occurrenceStart, $deadlineLeadSeconds)->format('Y-m-d H:i:s'),
                        'attendance_required' => $shared['attendance_required'],
                    ]);
                    $this->audience->setAudience($seriesEvent, $audienceSets);
                    $createdEvents[] = $seriesEvent->fresh();
                }

                return $createdEvents;
            }
        );
    }

    /**
     * Ändert einen einzelnen Termin und setzt seine Zielgruppe.
     *
     * @param array<string, mixed> $updateData
     * @param array<int, mixed> $audienceSets
     */
    public function updateSingle(Event $event, array $updateData, array $audienceSets): void
    {
        Capsule::connection()->transaction(function () use ($event, $updateData, $audienceSets): void {
            $event->update($updateData);
            $this->audience->setAudience($event, $audienceSets);
        });
    }

    /**
     * Überträgt die gewählten Feldgruppen auf die Termine einer Serie.
     *
     * Nur die gewählten Feldgruppen wandern auf die Folgetermine. Wer einen
     * einzelnen Termin bewusst anders angesetzt hat - eigener Titel, eigener
     * Ort, engere Zielgruppe - verliert das sonst mit jeder Serienänderung,
     * ohne es zu merken.
     *
     * @param iterable<Event> $events die zu ändernden Termine der Serie
     * @param list<string> $seriesFields gewählte Feldgruppen
     * @param array<string, mixed> $updateData title, event_type_id, location,
     *     registration_enabled, attendance_required
     * @param array<int, mixed> $audienceSets
     */
    public function updateSeries(
        iterable $events,
        array $seriesFields,
        array $updateData,
        string $startsAt,
        string $endsAt,
        ?string $registrationDeadline,
        array $audienceSets
    ): void {
        Capsule::connection()->transaction(
            function () use (
                $events,
                $seriesFields,
                $updateData,
                $startsAt,
                $endsAt,
                $registrationDeadline,
                $audienceSets
            ): void {
                $newStartTime = Carbon::parse($startsAt)->format('H:i');
                $newEndTime = Carbon::parse($endsAt)->format('H:i');

                // Ein absoluter Anmeldeschluss ergibt für eine ganze Serie keinen
                // Sinn - er würde alle Termine zum selben Zeitpunkt schließen.
                // Übernommen wird daher der Vorlauf zum jeweiligen Terminbeginn.
                $deadlineLeadSeconds = null;
                if ($updateData['registration_enabled'] && $registrationDeadline !== null) {
                    $deadlineLeadSeconds = WallClockOffset::seconds($startsAt, $registrationDeadline);
                }

                foreach ($events as $eventInSeries) {
                    $seriesUpdate = [];

                    if (in_array('title', $seriesFields, true)) {
                        $seriesUpdate['title'] = $updateData['title'];
                        $seriesUpdate['event_type_id'] = $updateData['event_type_id'];
                    }

                    if (in_array('location', $seriesFields, true)) {
                        $seriesUpdate['location'] = $updateData['location'];
                    }

                    if (in_array('attendance', $seriesFields, true)) {
                        $seriesUpdate['attendance_required'] = $updateData['attendance_required'];
                    }

                    $seriesStart = Carbon::parse($eventInSeries->starts_at)->setTimeFromTimeString($newStartTime);

                    if (in_array('time', $seriesFields, true)) {
                        $seriesUpdate['starts_at'] = $seriesStart;
                        $seriesUpdate['ends_at'] = Carbon::parse($eventInSeries->ends_at)
                            ->setTimeFromTimeString($newEndTime);
                    }

                    if (in_array('registration', $seriesFields, true)) {
                        $seriesUpdate['registration_enabled'] = $updateData['registration_enabled'];
                        // Der Vorlauf zählt ab dem Beginn, den dieser Termin nachher hat.
                        $deadlineBase = in_array('time', $seriesFields, true)
                            ? $seriesStart
                            : Carbon::parse($eventInSeries->starts_at);
                        $seriesUpdate['registration_deadline'] = $deadlineLeadSeconds === null
                            ? null
                            : WallClockOffset::shift($deadlineBase, $deadlineLeadSeconds);
                    }

                    if ($seriesUpdate !== []) {
                        $eventInSeries->update($seriesUpdate);
                    }

                    if (in_array('audience', $seriesFields, true)) {
                        $this->audience->setAudience($eventInSeries, $audienceSets);
                    }
                }
            }
        );
    }
}
