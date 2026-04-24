<?php

declare(strict_types=1);

namespace App\Controllers;

use Carbon\Carbon;
use DateTime;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\EventType;
use App\Models\Project;

class EventController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        $accessibleProjects = $this->getAccessibleProjects($userId, $canManageUsers);
        $accessibleProjectIds = $accessibleProjects->pluck('id')->map(static fn($id) => (int) $id)->all();

        $projectId = !empty($queryParams['project_id']) ? (int)$queryParams['project_id'] : null;
        $eventTypeId = !empty($queryParams['event_type_id']) ? (int)$queryParams['event_type_id'] : null;
        $sort = $queryParams['sort'] ?? 'starts_at';
        $direction = $queryParams['direction'] ?? 'asc';
        $showOldEvents = !empty($queryParams['show_old_events']) ? (int)$queryParams['show_old_events'] : 0;

        if ($projectId !== null && $projectId > 0 && !$canManageUsers && !in_array($projectId, $accessibleProjectIds, true)) {
            return $response->withStatus(403);
        }

        // Allowed sort columns
        $allowedSorts = ['starts_at', 'title', 'type', 'project_name', 'location'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'starts_at';
        }

        $query = Event::query();

        if (!$canManageUsers) {
            $query->where(function ($scopedQuery) use ($accessibleProjectIds) {
                $scopedQuery->whereNull('project_id');

                if (!empty($accessibleProjectIds)) {
                    $scopedQuery->orWhereIn('project_id', $accessibleProjectIds);
                }
            });
        }

        if ($projectId) {
            $query->where('project_id', $projectId);
        }
        if ($eventTypeId) {
            $query->where('event_type_id', $eventTypeId);
        }

        // Filter out old events (older than 14 days) unless show_old_events=1
        if (!$showOldEvents) {
            $query->whereDate('starts_at', '>=', Carbon::now()->subDays(14));
        }

        if ($sort === 'project_name') {
            $query->leftJoin('projects', 'events.project_id', '=', 'projects.id')
                ->orderBy('projects.name', $direction)
                ->select('events.*');
        } elseif ($sort === 'type') {
            $query->leftJoin('event_types', 'events.event_type_id', '=', 'event_types.id')
                ->orderBy('event_types.name', $direction)
                ->select('events.*');
        } else {
            $query->orderBy($sort, $direction);
        }

        $events = $query->get();

        // Manual eager loading to avoid PHP 8.4 deprecation in Eloquent
        $projectIds = $events->pluck('project_id')->filter()->unique()->toArray();
        $eventTypeIds = $events->pluck('event_type_id')->filter()->unique()->toArray();
        $seriesIds = $events->pluck('series_id')->filter()->unique()->toArray();

        $projectsMap = Project::whereIn('id', $projectIds)->get()->keyBy('id');
        $eventTypesMap = EventType::whereIn('id', $eventTypeIds)->get()->keyBy('id');
        $seriesMap = EventSeries::whereIn('id', $seriesIds)->get()->keyBy('id');

        $events->map(function ($event) use ($projectsMap, $eventTypesMap, $seriesMap) {
            $project = !is_null($event->project_id) ? $projectsMap->get($event->project_id) : null;
            $eventType = !is_null($event->event_type_id) ? $eventTypesMap->get($event->event_type_id) : null;
            $series = !is_null($event->series_id) ? $seriesMap->get($event->series_id) : null;

            $event->setRelation('project', $project);
            $event->setRelation('eventType', $eventType);
            $event->setRelation('series', $series);

            // For template compatibility
            $event->project_name = $project ? $project->name : null;
            $event->type_name = $eventType ? $eventType->name : $event->type;
            $event->type_color = $eventType ? $eventType->color : 'info';

            return $event;
        });

        $projects = $accessibleProjects;
        $eventTypes = EventType::orderBy('name')->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        $eventModalError = $_SESSION['event_modal_error'] ?? null;
        $createForm = $_SESSION['event_create_form'] ?? [];
        $openCreateModal = !empty($_SESSION['event_create_open_modal']);
        unset($_SESSION['success'], $_SESSION['error']);
        unset($_SESSION['event_create_form'], $_SESSION['event_create_open_modal'], $_SESSION['event_modal_error']);

        $createForm = array_merge([
            'title' => '',
            'starts_at' => '',
            'start_time' => '',
            'end_time' => '',
            'event_type_id' => '',
            'project_id' => '',
            'location' => '',
            'repeat' => false,
            'recurrence_interval' => '1',
            'frequency' => 'weekly',
            'weekdays' => [1],
            'series_end_date' => '',
            'open_modal' => false,
        ], is_array($createForm) ? $createForm : []);
        $createForm['open_modal'] = $openCreateModal;

        return $this->view->render($response, 'events/index.twig', [
            'events' => $events,
            'projects' => $projects,
            'event_types' => $eventTypes,
            'filters' => [
                'project_id' => $projectId,
                'event_type_id' => $eventTypeId,
                'show_old_events' => $showOldEvents,
                'sort' => $sort,
                'direction' => $direction
            ],
            'success' => $success,
            'error' => $error,
            'event_modal_error' => $eventModalError,
            'create_form' => $createForm,
        ]);
    }

    private function getAccessibleProjects(int $userId, bool $canManageUsers)
    {
        if ($canManageUsers) {
            return Project::orderBy('name')->get();
        }

        if ($userId <= 0) {
            return Project::query()->whereRaw('1 = 0')->get();
        }

        return Project::query()
            ->select('projects.*')
            ->join('project_users', 'project_users.project_id', '=', 'projects.id')
            ->where('project_users.user_id', $userId)
            ->distinct()
            ->orderBy('projects.name')
            ->get();
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $title = trim($data['title'] ?? '');
        $startsAtDate = $data['starts_at'] ?? '';
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        $eventTypeId = !empty($data['event_type_id']) ? (int)$data['event_type_id'] : null;
        $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;
        $repeat = !empty($data['repeat']);

        if (!$this->canAccessProjectId($projectId)) {
            $this->rememberCreateFormInput($data);
            $_SESSION['event_modal_error'] = 'create';
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/events')->withStatus(403);
        }

        if (!$startsAtDate || !$startTime || !$endTime) {
            $this->rememberCreateFormInput($data);
            $_SESSION['event_modal_error'] = 'create';
            $_SESSION['error'] = 'Datum, Startzeit und Endzeit sind Pflichtfelder.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        try {
            $parsedStart = Carbon::createFromFormat('Y-m-d H:i', $startsAtDate . ' ' . $startTime);
            $parsedEnd   = Carbon::createFromFormat('Y-m-d H:i', $startsAtDate . ' ' . $endTime);
        } catch (Exception $e) {
            $parsedStart = false;
            $parsedEnd   = false;
        }
        if (!$parsedStart || !$parsedEnd) {
            $this->rememberCreateFormInput($data);
            $_SESSION['event_modal_error'] = 'create';
            $_SESSION['error'] = 'Ungültiges Datum oder Zeitformat.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }
        if (!$parsedEnd->greaterThan($parsedStart)) {
            $this->rememberCreateFormInput($data);
            $_SESSION['event_modal_error'] = 'create';
            $_SESSION['error'] = 'Endzeit muss nach der Startzeit liegen.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }
        $startsAt = $parsedStart->format('Y-m-d H:i:s');
        $endsAt   = $parsedEnd->format('Y-m-d H:i:s');

        try {
            $eventType = null;
            if ($eventTypeId) {
                $eventType = EventType::find($eventTypeId);
            }
            $typeName = $eventType ? $eventType->name : 'Probe';

            if (empty($title)) {
                $title = $typeName;
            }

            if (!$repeat) {
                // Single event
                Event::create([
                    'title' => $title,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'event_type_id' => $eventTypeId,
                    'project_id' => $projectId,
                    'type' => $typeName,
                    'location' => trim($data['location'] ?? '')
                ]);
                $_SESSION['success'] = 'Event erfolgreich angelegt.';
                unset($_SESSION['event_create_form'], $_SESSION['event_create_open_modal'], $_SESSION['event_modal_error']);
            } else {
                // Series
                $frequency = $data['frequency'] ?? 'weekly';
                $interval = (int)($data['recurrence_interval'] ?? 1);
                $endDateStr = $data['series_end_date'] ?? null;
                $weekdays = $data['weekdays'] ?? []; // 1 (Mo) - 7 (So)

                if (!$endDateStr) {
                    throw new Exception('Enddatum für die Serie ist erforderlich.');
                }

                $series = EventSeries::create([
                    'frequency' => $frequency,
                    'recurrence_interval' => $interval,
                    'weekdays' => !empty($weekdays) ? implode(',', $weekdays) : null,
                    'end_date' => $endDateStr
                ]);

                $startDate = new DateTime($startsAtDate);
                $endDate = new DateTime($endDateStr);
                $endDate->setTime(23, 59, 59);

                $currentDate = clone $startDate;
                $count = 0;

                while ($currentDate <= $endDate) {
                    $shouldCreate = false;

                    if ($frequency === 'daily') {
                        $shouldCreate = true;
                    } elseif ($frequency === 'weekly') {
                        $dayOfWeek = (int)$currentDate->format('N'); // 1 (mon) to 7 (sun)
                        if (empty($weekdays) || in_array($dayOfWeek, $weekdays)) {
                            $shouldCreate = true;
                        }
                    } elseif ($frequency === 'monthly') {
                        $shouldCreate = true;
                    } elseif ($frequency === 'yearly') {
                        $shouldCreate = true;
                    }

                    if ($shouldCreate) {
                        Event::create([
                            'title' => $title,
                            'starts_at' => $currentDate->format('Y-m-d') . ' ' . $startTime . ':00',
                            'ends_at' => $currentDate->format('Y-m-d') . ' ' . $endTime . ':00',
                            'event_type_id' => $eventTypeId,
                            'project_id' => $projectId,
                            'type' => $typeName,
                            'series_id' => $series->id,
                            'location' => trim($data['location'] ?? '')
                        ]);
                        $count++;
                    }

                    // Increment
                    if ($frequency === 'daily') {
                        $currentDate->modify('+' . $interval . ' day');
                    } elseif ($frequency === 'weekly') {
                        // If it's weekly, we check all weekdays in the current week,
                        // then jump by interval weeks if we've passed all selected weekdays.
                        // Simplification for now: jump 1 day at a time, and when we finish a week,
                        // skip (interval-1) weeks.
                        $prevDay = (int)$currentDate->format('N');
                        $currentDate->modify('+1 day');
                        $nextDay = (int)$currentDate->format('N');

                        if ($nextDay === 1) { // New week started
                            if ($interval > 1) {
                                $currentDate->modify('+' . ($interval - 1) . ' weeks');
                            }
                        }
                    } elseif ($frequency === 'monthly') {
                        $currentDate->modify('+' . $interval . ' month');
                    } elseif ($frequency === 'yearly') {
                        $currentDate->modify('+' . $interval . ' year');
                    }

                    if ($count > 500) {
                        break; // Safety break
                    }
                }

                $_SESSION['success'] = "Serie erfolgreich angelegt ($count Termine).";
                unset($_SESSION['event_create_form'], $_SESSION['event_create_open_modal'], $_SESSION['event_modal_error']);
            }
        } catch (Exception $e) {
            $this->rememberCreateFormInput($data);
            $_SESSION['event_modal_error'] = 'create';
            $_SESSION['error'] = 'Fehler beim Anlegen: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $event = Event::find($id);
        if (!$event) {
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            return $response->withStatus(403);
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        $projects = $this->getAccessibleProjects($userId, $canManageUsers);
        $eventTypes = EventType::orderBy('name')->get();
        $error = $_SESSION['error'] ?? null;
        $oldEditForms = $_SESSION['event_edit_forms'] ?? [];
        $editForm = [];
        if (is_array($oldEditForms) && isset($oldEditForms[$id]) && is_array($oldEditForms[$id])) {
            $editForm = $oldEditForms[$id];
            unset($_SESSION['event_edit_forms'][$id]);
            if (isset($_SESSION['event_edit_forms']) && is_array($_SESSION['event_edit_forms']) && $_SESSION['event_edit_forms'] === []) {
                unset($_SESSION['event_edit_forms']);
            }
        }
        unset($_SESSION['error']);

        $editForm = array_merge([
            'title' => (string) $event->title,
            'starts_at' => Carbon::parse($event->starts_at)->format('Y-m-d'),
            'start_time' => Carbon::parse($event->starts_at)->format('H:i'),
            'end_time' => Carbon::parse($event->ends_at)->format('H:i'),
            'event_type_id' => $event->event_type_id !== null ? (string) $event->event_type_id : '',
            'project_id' => $event->project_id !== null ? (string) $event->project_id : '',
            'location' => (string) ($event->location ?? ''),
            'update_series' => false,
        ], $editForm);

        return $this->view->render($response, 'events/edit.twig', [
            'event' => $event,
            'projects' => $projects,
            'event_types' => $eventTypes,
            'error' => $error,
            'edit_form' => $editForm,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $event = Event::find($id);
        if (!$event) {
            $_SESSION['error'] = 'Event nicht gefunden.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/events')->withStatus(403);
        }

        $data = (array)$request->getParsedBody();
        $title = trim($data['title'] ?? '');
        $startsAtDate = $data['starts_at'] ?? '';
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        $eventTypeId = !empty($data['event_type_id']) ? (int)$data['event_type_id'] : null;
        $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;
        $updateSeries = !empty($data['update_series']);

        if (!$this->canAccessProjectId($projectId)) {
            $this->rememberEditFormInput($id, $data);
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(403);
        }

        if (!$startsAtDate || !$startTime || !$endTime) {
            $this->rememberEditFormInput($id, $data);
            $_SESSION['error'] = 'Datum, Startzeit und Endzeit sind Pflichtfelder.';
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }

        try {
            $parsedStart = Carbon::createFromFormat('Y-m-d H:i', $startsAtDate . ' ' . $startTime);
            $parsedEnd   = Carbon::createFromFormat('Y-m-d H:i', $startsAtDate . ' ' . $endTime);
        } catch (Exception $e) {
            $parsedStart = false;
            $parsedEnd   = false;
        }
        if (!$parsedStart || !$parsedEnd) {
            $this->rememberEditFormInput($id, $data);
            $_SESSION['error'] = 'Ungültiges Datum oder Zeitformat.';
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }
        if (!$parsedEnd->greaterThan($parsedStart)) {
            $this->rememberEditFormInput($id, $data);
            $_SESSION['error'] = 'Endzeit muss nach der Startzeit liegen.';
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }
        $startsAt = $parsedStart->format('Y-m-d H:i:s');
        $endsAt   = $parsedEnd->format('Y-m-d H:i:s');

        try {
            $eventType = null;
            if ($eventTypeId) {
                $eventType = EventType::find($eventTypeId);
            }
            $typeName = $eventType ? $eventType->name : $event->type;

            if (empty($title)) {
                $title = $typeName;
            }

            $updateData = [
                'title' => $title,
                'event_type_id' => $eventTypeId,
                'project_id' => $projectId,
                'type' => $typeName,
                'location' => trim($data['location'] ?? '')
            ];

            if ($updateSeries && $event->series_id) {
                $eventsToUpdate = Event::where('series_id', $event->series_id)
                    ->where('starts_at', '>=', $event->starts_at)
                    ->get();

                $hasUnauthorizedSeriesEvent = $eventsToUpdate->contains(function ($seriesEvent) {
                    return !$this->canAccessEvent($seriesEvent);
                });

                if ($hasUnauthorizedSeriesEvent) {
                    $this->rememberEditFormInput($id, $data);
                    $_SESSION['error'] = 'Zugriff verweigert.';
                    return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(403);
                }

                $newStartTime = Carbon::parse($startsAt)->format('H:i');
                $newEndTime = Carbon::parse($endsAt)->format('H:i');

                foreach ($eventsToUpdate as $eventInSeries) {
                    $eventInSeries->update(array_merge($updateData, [
                        'starts_at' => Carbon::parse($eventInSeries->starts_at)->setTimeFromTimeString($newStartTime),
                        'ends_at' => Carbon::parse($eventInSeries->ends_at)->setTimeFromTimeString($newEndTime),
                    ]));
                }

                $_SESSION['success'] = 'Event-Serie (' . count($eventsToUpdate) . ' Termine) erfolgreich aktualisiert.';
                unset($_SESSION['event_edit_forms'][$id]);
            } else {
                $updateData['starts_at'] = $startsAt;
                $updateData['ends_at'] = $endsAt;
                $event->update($updateData);
                $_SESSION['success'] = 'Event erfolgreich aktualisiert.';
                unset($_SESSION['event_edit_forms'][$id]);
            }
        } catch (Exception $e) {
            $this->rememberEditFormInput($id, $data);
            $_SESSION['error'] = 'Fehler beim Aktualisieren: ' . $e->getMessage();
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }

        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $event = Event::find($id);
        if ($event && $this->canAccessEvent($event)) {
            $event->delete();
            $_SESSION['success'] = 'Termin gelöscht.';
        } elseif ($event) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/events')->withStatus(403);
        }
        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    public function deleteSeries(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $event = Event::find($id);
        if ($event && !$this->canAccessEvent($event)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/events')->withStatus(403);
        }

        if ($event && $event->series_id) {
            $seriesId = $event->series_id;
            $eventsToDelete = Event::where('series_id', $seriesId)
                ->where('starts_at', '>=', $event->starts_at)
                ->get();

            $hasUnauthorizedSeriesEvent = $eventsToDelete->contains(function ($seriesEvent) {
                return !$this->canAccessEvent($seriesEvent);
            });

            if ($hasUnauthorizedSeriesEvent) {
                $_SESSION['error'] = 'Zugriff verweigert.';
                return $response->withHeader('Location', '/events')->withStatus(403);
            }

            // Delete all future events of this series (including current)
            Event::whereIn('id', $eventsToDelete->pluck('id')->all())->delete();

            $_SESSION['success'] = 'Alle zukünftigen Termine der Serie wurden gelöscht.';
        }
        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    private function canAccessEvent(Event $event): bool
    {
        return $this->canAccessProjectId($event->project_id !== null ? (int) $event->project_id : null);
    }

    private function canAccessProjectId(?int $projectId): bool
    {
        if ($projectId === null) {
            return true;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        if ($canManageUsers) {
            return true;
        }

        if ($userId <= 0) {
            return false;
        }

        return Project::query()
            ->join('project_users', 'project_users.project_id', '=', 'projects.id')
            ->where('project_users.user_id', $userId)
            ->where('projects.id', $projectId)
            ->exists();
    }

    private function rememberCreateFormInput(array $data): void
    {
        $_SESSION['event_create_form'] = [
            'title' => trim((string) ($data['title'] ?? '')),
            'starts_at' => trim((string) ($data['starts_at'] ?? '')),
            'start_time' => trim((string) ($data['start_time'] ?? '')),
            'end_time' => trim((string) ($data['end_time'] ?? '')),
            'event_type_id' => trim((string) ($data['event_type_id'] ?? '')),
            'project_id' => trim((string) ($data['project_id'] ?? '')),
            'location' => trim((string) ($data['location'] ?? '')),
            'repeat' => !empty($data['repeat']),
            'recurrence_interval' => trim((string) ($data['recurrence_interval'] ?? '1')),
            'frequency' => trim((string) ($data['frequency'] ?? 'weekly')),
            'weekdays' => array_values(array_map('intval', (array) ($data['weekdays'] ?? [1]))),
            'series_end_date' => trim((string) ($data['series_end_date'] ?? '')),
        ];
        $_SESSION['event_create_open_modal'] = true;
    }

    private function rememberEditFormInput(int $eventId, array $data): void
    {
        if (!isset($_SESSION['event_edit_forms']) || !is_array($_SESSION['event_edit_forms'])) {
            $_SESSION['event_edit_forms'] = [];
        }

        $_SESSION['event_edit_forms'][$eventId] = [
            'title' => trim((string) ($data['title'] ?? '')),
            'starts_at' => trim((string) ($data['starts_at'] ?? '')),
            'start_time' => trim((string) ($data['start_time'] ?? '')),
            'end_time' => trim((string) ($data['end_time'] ?? '')),
            'event_type_id' => trim((string) ($data['event_type_id'] ?? '')),
            'project_id' => trim((string) ($data['project_id'] ?? '')),
            'location' => trim((string) ($data['location'] ?? '')),
            'update_series' => !empty($data['update_series']),
        ];
    }
}
