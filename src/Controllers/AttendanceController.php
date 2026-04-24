<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Event;
use App\Models\User;
use App\Models\Attendance;
use Illuminate\Database\Capsule\Manager as Capsule;

class AttendanceController
{
    private const SELECTED_EVENT_SESSION_KEY = 'attendance_selected_event_id';

    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $routeEventId = isset($args['event_id']) ? (int) $args['event_id'] : null;
        $queryParams = $request->getQueryParams();
        $queryEventId = isset($queryParams['event_id']) && is_numeric((string) $queryParams['event_id'])
            ? (int) $queryParams['event_id']
            : null;

        $events = Event::orderBy('starts_at', 'asc')->get();

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

        if ($eventId) {
            $event = $events->firstWhere('id', $eventId);

            if ($event) {
                [$previousEventId, $nextEventId] = $this->getPreviousAndNextEventIds($events, (int) $event->id);

                $canManageUsers = $_SESSION['can_manage_users'] ?? false;
                $userVoiceGroupIds = $_SESSION['voice_group_ids'] ?? [];
                $roleLevel = $_SESSION['role_level'] ?? 0;

                // Same logic as before: If not admin/board, restrict to own voice groups
                if (!$canManageUsers && $roleLevel < 80) {
                    if (!empty($userVoiceGroupIds)) {
                        $users = User::whereHas('voiceGroups', function ($q) use ($userVoiceGroupIds) {
                            $q->whereIn('voice_group_id', $userVoiceGroupIds);
                        });
                    } else {
                        // Edge case: no voice group assigned but is a stimmsprecher
                        $users = User::whereRaw('1 = 0'); // show nothing
                    }
                } else {
                    $users = User::query();
                }

                $users = $users->where('is_active', 1)
                    ->with(['voiceGroups', 'subVoices.voiceGroup', 'attendances' => function ($q) use ($eventId) {
                        $q->where('event_id', $eventId);
                    }])
                    ->get()
                    ->sortBy(['last_name', 'first_name']);

                foreach ($users as $u) {
                    $vgName = 'Ohne Stimmgruppe';

                    $voiceGroup = $u->voiceGroups->first();
                    if ($voiceGroup) {
                        $vgName = $voiceGroup->name;
                    }

                    if (!isset($voiceGroups[$vgName])) {
                        $voiceGroups[$vgName] = [];
                    }

                    $attendance = $u->attendances->first();
                    $status = $attendance ? $attendance->status : 'unbekannt';
                    $note = $attendance ? $attendance->note : null;

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
                        'note' => $note
                    ];
                }

                ksort($voiceGroups);
                if (isset($voiceGroups['Ohne Stimmgruppe'])) {
                    $ungrouped = $voiceGroups['Ohne Stimmgruppe'];
                    unset($voiceGroups['Ohne Stimmgruppe']);
                    $voiceGroups['Ohne Stimmgruppe'] = $ungrouped;
                }
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
            'success' => $success,
            'error' => $error
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $eventId = (int) $args['event_id'];
        $data = (array) $request->getParsedBody();
        $attendances = $data['attendance'] ?? [];
        $notes = $data['note'] ?? [];

        $event = Event::find($eventId);
        if (!$event) {
            $_SESSION['error'] = 'Event nicht gefunden.';
            return $response->withHeader('Location', '/attendance')->withStatus(302);
        }

        if (!$this->canAccessAttendanceEvent($event)) {
            $_SESSION['error'] = 'Zugriff verweigert: Keine Berechtigung für dieses Event.';
            return $response->withHeader('Location', '/attendance/' . $eventId)->withStatus(403);
        }

        $allowedUserIds = $this->getManageableUserIds();
        $submittedUserIds = array_values(array_unique(array_map('intval', array_keys((array) $attendances))));
        $unauthorizedUserIds = array_diff($submittedUserIds, $allowedUserIds);

        if (!empty($unauthorizedUserIds)) {
            $_SESSION['error'] = 'Zugriff verweigert: Unzulässige Personen in der Anwesenheitsliste.';
            return $response->withHeader('Location', '/attendance/' . $eventId)->withStatus(403);
        }

        Capsule::beginTransaction();

        try {
            foreach ($attendances as $userId => $status) {
                $userId = (int) $userId;

                if (!in_array($status, ['present', 'excused', 'unexcused'])) {
                    continue;
                }

                $note = trim($notes[$userId] ?? '');

                Attendance::updateOrCreate(
                    ['event_id' => $eventId, 'user_id' => $userId],
                    ['status' => $status, 'note' => $note]
                );
            }

            Capsule::commit();
            $_SESSION[self::SELECTED_EVENT_SESSION_KEY] = $eventId;
            $_SESSION['success'] = 'Anwesenheiten erfolgreich gespeichert.';
        } catch (\Exception $e) {
            Capsule::rollBack();
            $_SESSION['error'] = 'Fehler beim Speichern aufgetreten: ';
        }

        return $response->withHeader('Location', '/attendance/' . $eventId)->withStatus(302);
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

    /**
     * @return array<int>
     */
    private function getManageableUserIds(): array
    {
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        $userVoiceGroupIds = $_SESSION['voice_group_ids'] ?? [];
        $roleLevel = (int) ($_SESSION['role_level'] ?? 0);

        if (!$canManageUsers && $roleLevel < 80) {
            if (empty($userVoiceGroupIds)) {
                return [];
            }

            return User::whereHas('voiceGroups', function ($query) use ($userVoiceGroupIds) {
                $query->whereIn('voice_group_id', $userVoiceGroupIds);
            })
                ->where('is_active', 1)
                ->pluck('id')
                ->map(static fn($id) => (int) $id)
                ->all();
        }

        return User::where('is_active', 1)
            ->pluck('id')
            ->map(static fn($id) => (int) $id)
            ->all();
    }

    private function canAccessAttendanceEvent(Event $event): bool
    {
        if ((bool) ($_SESSION['can_manage_users'] ?? false)) {
            return true;
        }

        if ($event->project_id === null) {
            return true;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        return User::query()
            ->where('users.id', $userId)
            ->whereHas('projects', function ($projectQuery) use ($event) {
                $projectQuery->where('projects.id', (int) $event->project_id);
            })
            ->exists();
    }
}
