<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;
use App\Models\Event;
use App\Models\Attendance;
use App\Services\AttendanceScopeService;
use App\Services\NameFormatterService;
use App\Util\VoiceGroupOrder;
use Illuminate\Database\Capsule\Manager as Capsule;

class AttendanceController
{
    private const SELECTED_EVENT_SESSION_KEY = 'attendance_selected_event_id';

    private Twig $view;
    private AttendanceScopeService $scopeService;
    private LoggerInterface $logger;
    private NameFormatterService $nameFormatter;

    public function __construct(
        Twig $view,
        AttendanceScopeService $scopeService,
        LoggerInterface $logger,
        NameFormatterService $nameFormatter
    ) {
        $this->view = $view;
        $this->scopeService = $scopeService;
        $this->logger = $logger;
        $this->nameFormatter = $nameFormatter;
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $routeEventId = isset($args['event_id']) ? (int) $args['event_id'] : null;
        $queryParams = $request->getQueryParams();
        $queryEventId = isset($queryParams['event_id']) && is_numeric((string) $queryParams['event_id'])
            ? (int) $queryParams['event_id']
            : null;

        // Nur Termine, zu denen der Nutzer selbst gehoert oder in denen er mindestens ein
        // verwaltbares Mitglied betreut - "alle Mitglieder verwalten" sieht jeden Termin.
        $events = Event::where('attendance_required', true)
            ->with('audienceSources')
            ->orderBy('starts_at', 'asc')
            ->get()
            ->filter(fn(Event $event): bool => $this->scopeService->canAccessEvent($event))
            ->values();

        $eventId = $this->resolveSelectedEventId($routeEventId, $queryEventId, $events);
        if ($eventId !== null) {
            $_SESSION[self::SELECTED_EVENT_SESSION_KEY] = $eventId;
        } else {
            unset($_SESSION[self::SELECTED_EVENT_SESSION_KEY]);
        }

        $event = null;
        $previousEventId = null;
        $nextEventId = null;
        $voiceGroups = [];
        $renderedUserIds = [];

        if ($eventId) {
            $event = $events->firstWhere('id', $eventId);

            if ($event) {
                [$previousEventId, $nextEventId] = $this->getPreviousAndNextEventIds($events, (int) $event->id);

                $canManageAttendanceAll = (bool) ($_SESSION['can_manage_attendance_all'] ?? false);

                // Only members within the event's audience scope may appear.
                $users = $event->eligibleUsersQuery();

                // Ohne das Recht fuer alle Mitglieder bleibt nur der eigene Stimmgruppen-Scope.
                if (!$canManageAttendanceAll) {
                    $manageableUserIds = $this->scopeService->getManageableUserIds();
                    if ($manageableUserIds === []) {
                        $users->whereRaw('1 = 0');
                    } else {
                        $users->whereIn('users.id', $manageableUserIds);
                    }
                }

                $users = $users
                    ->with(['voiceGroups', 'subVoices.voiceGroup', 'attendances' => function ($q) use ($eventId) {
                        $q->where('event_id', $eventId);
                    }, 'eventRegistrations' => function ($q) use ($eventId) {
                        $q->where('event_id', $eventId);
                    }])
                    ->get()
                    ->sortBy($this->nameFormatter->orderColumns());

                foreach ($users as $u) {
                    $renderedUserIds[] = (int) $u->id;
                    $vgName = 'Ohne Stimmgruppe';

                    $voiceGroup = $u->voiceGroups->first();
                    if ($voiceGroup) {
                        $vgName = $voiceGroup->name;
                    }

                    if (!isset($voiceGroups[$vgName])) {
                        $voiceGroups[$vgName] = [];
                    }

                    $attendance = $u->attendances->first();
                    $status = $attendance ? $attendance->status : Attendance::STATUS_UNKNOWN;
                    $note = $attendance ? $attendance->note : null;

                    $registration = $u->eventRegistrations->first();

                    $svName = null;
                    if ($voiceGroup && $voiceGroup->pivot->sub_voice_id) {
                        $subVoice = $u->subVoices->firstWhere('id', $voiceGroup->pivot->sub_voice_id);
                        if ($subVoice) {
                            $svName = $subVoice->name;
                        }
                    }

                    $voiceGroups[$vgName][] = [
                        'user_id' => $u->id,
                        'first_name' => $u->first_name,
                        'last_name' => $u->last_name,
                        'voice_group_name' => $vgName !== 'Ohne Stimmgruppe' ? $vgName : null,
                        'sub_voice_name' => $svName,
                        'status' => $status,
                        'note' => $note,
                        'registration_status' => $registration ? $registration->status : null,
                        'registration_note' => $registration ? $registration->note : null
                    ];
                }

                $voiceGroups = VoiceGroupOrder::sortNameKeyedMap($voiceGroups, ['Ohne Stimmgruppe']);
            }
        }

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'attendance/show.twig', [
            'events' => $events,
            'current_event' => $event,
            'previous_event_id' => $previousEventId,
            'next_event_id' => $nextEventId,
            'voice_groups' => $voiceGroups,
            // Fingerabdruck des angezeigten Standes: erkennt beim Speichern, ob
            // jemand anderes dieselben Mitglieder zwischenzeitlich geändert hat.
            'state_hash' => $eventId ? $this->attendanceStateHash($eventId, $renderedUserIds) : '',
            'success' => $success,
            'error' => $error
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $eventId = (int) $args['event_id'];
        $data = (array) $request->getParsedBody();
        $attendances = (array) ($data['attendance'] ?? []);
        $notes = (array) ($data['note'] ?? []);
        $loadedStateHash = (string) ($data['state_hash'] ?? '');

        $event = Event::find($eventId);
        if (!$event) {
            $_SESSION['error'] = 'Event nicht gefunden.';
            return $response->withHeader('Location', '/attendance')->withStatus(302);
        }

        if (!(bool) $event->attendance_required) {
            return $this->denyAttendanceAccess(
                $response,
                'Für diesen Termin wird keine Anwesenheitsliste geführt.',
                'attendance.save.not_required',
                $eventId
            );
        }

        if (!$this->canAccessAttendanceEvent($event)) {
            return $this->denyAttendanceAccess(
                $response,
                'Sie haben keine Berechtigung für die Anwesenheitsliste dieses Termins.',
                'attendance.save.event_forbidden',
                $eventId
            );
        }

        $eligibleUserIds = $event->eligibleUsersQuery()
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->all();
        $allowedUserIds = array_intersect($this->scopeService->getManageableUserIds(), $eligibleUserIds);
        $submittedUserIds = array_values(array_unique(array_map('intval', array_keys($attendances))));
        $unauthorizedUserIds = array_diff($submittedUserIds, $allowedUserIds);

        if (!empty($unauthorizedUserIds)) {
            return $this->denyAttendanceAccess(
                $response,
                'Die Liste enthielt Personen, für die Sie keine Anwesenheit eintragen dürfen. '
                . 'Es wurde nichts gespeichert.',
                'attendance.save.user_forbidden',
                $eventId
            );
        }

        // Optimistisches Sperren: nur der Stand der übermittelten Mitglieder zählt,
        // damit zwei Stimmgruppenleiter mit getrennten Gruppen sich nicht blockieren.
        if (
            $loadedStateHash !== ''
            && !hash_equals($this->attendanceStateHash($eventId, $submittedUserIds), $loadedStateHash)
        ) {
            $this->logger->info('Attendance save rejected due to a concurrent change.', [
                'event' => 'attendance.save.conflict',
                'event_id' => $eventId,
                'user_count' => count($submittedUserIds),
            ]);

            $_SESSION[self::SELECTED_EVENT_SESSION_KEY] = $eventId;
            $_SESSION['error'] = 'Die Anwesenheiten wurden zwischenzeitlich von jemand anderem geändert. '
                . 'Es wurde nichts gespeichert - bitte die neu geladene Liste prüfen und erneut eintragen.';

            return $response->withHeader('Location', '/attendance/' . $eventId)->withStatus(302);
        }

        [$rowsToWrite, $userIdsToClear] = $this->buildAttendanceChanges($eventId, $attendances, $notes);

        Capsule::beginTransaction();

        try {
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

            Capsule::commit();
            $_SESSION[self::SELECTED_EVENT_SESSION_KEY] = $eventId;
            $_SESSION['success'] = 'Anwesenheiten erfolgreich gespeichert.';
        } catch (\Exception $e) {
            Capsule::rollBack();

            $this->logger->error('Saving attendances failed.', [
                'event' => 'attendance.save.failed',
                'event_id' => $eventId,
                'exception' => $e,
            ]);

            $_SESSION['error'] = 'Fehler beim Speichern der Anwesenheiten. Es wurde nichts gespeichert, '
                . 'bitte erneut versuchen.';
        }

        return $response->withHeader('Location', '/attendance/' . $eventId)->withStatus(302);
    }

    /**
     * Teilt die Formulareingaben in zu schreibende Zeilen und zu löschende
     * Mitglieder auf.
     *
     * "Offen" bedeutet: keine Bewertung. Der Datensatz wird dann gelöscht - es
     * sei denn, es steht eine Notiz darin, die sonst kommentarlos verschwinden
     * würde. Die bleibt als offener Eintrag erhalten und zählt in keiner
     * Anwesenheitsstatistik mit.
     *
     * @param array<array-key, mixed> $attendances
     * @param array<array-key, mixed> $notes
     * @return array{0: list<array<string, mixed>>, 1: list<int>}
     */
    private function buildAttendanceChanges(int $eventId, array $attendances, array $notes): array
    {
        $rowsToWrite = [];
        $userIdsToClear = [];

        foreach ($attendances as $rawUserId => $rawStatus) {
            $userId = (int) $rawUserId;
            $status = is_string($rawStatus) ? $rawStatus : '';
            $note = mb_substr(trim((string) ($notes[$userId] ?? '')), 0, 255);

            if ($status === Attendance::STATUS_UNKNOWN) {
                if ($note === '') {
                    $userIdsToClear[] = $userId;
                    continue;
                }

                $rowsToWrite[] = [
                    'event_id' => $eventId,
                    'user_id' => $userId,
                    'status' => Attendance::STATUS_UNKNOWN,
                    'note' => $note,
                ];
                continue;
            }

            if (!in_array($status, Attendance::RECORDED_STATUSES, true)) {
                continue;
            }

            $rowsToWrite[] = [
                'event_id' => $eventId,
                'user_id' => $userId,
                'status' => $status,
                'note' => $note,
            ];
        }

        return [$rowsToWrite, $userIdsToClear];
    }

    /**
     * Fingerabdruck des gespeicherten Standes für genau diese Mitglieder.
     *
     * @param list<int> $userIds
     */
    private function attendanceStateHash(int $eventId, array $userIds): string
    {
        if ($userIds === []) {
            return hash('sha256', '');
        }

        $parts = Attendance::where('event_id', $eventId)
            ->whereIn('user_id', $userIds)
            ->orderBy('user_id')
            ->get(['user_id', 'status', 'note'])
            ->map(static fn(Attendance $row): string => implode("\x1f", [
                (string) $row->user_id,
                (string) $row->status,
                (string) $row->note,
            ]))
            ->all();

        return hash('sha256', implode("\x1e", $parts));
    }

    /**
     * Abweisung mit sichtbarer Begründung: ein 403 mit Location-Header führt zu
     * einer leeren Seite, weil Browser nur 3xx-Weiterleitungen folgen.
     */
    private function denyAttendanceAccess(
        Response $response,
        string $message,
        string $reason,
        int $eventId
    ): Response {
        $this->logger->info('Access denied.', [
            'event' => 'authz.denied',
            'reason' => $reason,
            'event_id' => $eventId,
        ]);

        return $this->view->render($response->withStatus(403), 'errors/403.twig', [
            'error' => $message,
        ]);
    }

    private function resolveSelectedEventId(?int $routeEventId, ?int $queryEventId, $events): ?int
    {
        $sessionEventId = isset($_SESSION[self::SELECTED_EVENT_SESSION_KEY])
            ? (int) $_SESSION[self::SELECTED_EVENT_SESSION_KEY]
            : null;

        $candidates = [$routeEventId, $queryEventId, $sessionEventId];
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate > 0 && $this->eventExists($events, $candidate)) {
                return $candidate;
            }
        }

        return $this->findNearestEventId($events);
    }

    private function eventExists($events, int $eventId): bool
    {
        return $events->contains(function ($event) use ($eventId) {
            return (int) $event->id === $eventId;
        });
    }

    private function findNearestEventId($events): ?int
    {
        if ($events->isEmpty()) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $bestEventId = null;
        $bestDiff = null;
        $bestIsFuture = false;

        foreach ($events as $event) {
            $eventDate = $event->starts_at;
            if (!$eventDate instanceof \DateTimeInterface) {
                $eventDate = new \DateTimeImmutable((string) $eventDate);
            }

            $eventTs = $eventDate->getTimestamp();
            $nowTs = $now->getTimestamp();
            $diff = abs($eventTs - $nowTs);
            $isFuture = $eventTs >= $nowTs;

            if (
                $bestDiff === null
                || $diff < $bestDiff
                || ($diff === $bestDiff && $isFuture && !$bestIsFuture)
            ) {
                $bestEventId = (int) $event->id;
                $bestDiff = $diff;
                $bestIsFuture = $isFuture;
            }
        }

        return $bestEventId;
    }

    private function getPreviousAndNextEventIds($events, int $currentEventId): array
    {
        $previousEventId = null;
        $nextEventId = null;

        $currentIndex = $events->search(function ($event) use ($currentEventId) {
            return (int) $event->id === $currentEventId;
        });

        if ($currentIndex === false) {
            return [$previousEventId, $nextEventId];
        }

        $previousEvent = $events->get($currentIndex - 1);
        $nextEvent = $events->get($currentIndex + 1);

        if ($previousEvent) {
            $previousEventId = (int) $previousEvent->id;
        }

        if ($nextEvent) {
            $nextEventId = (int) $nextEvent->id;
        }

        return [$previousEventId, $nextEventId];
    }

    private function canAccessAttendanceEvent(Event $event): bool
    {
        return $this->scopeService->canAccessEvent($event);
    }
}
