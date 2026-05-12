<?php

declare(strict_types=1);

namespace App\Controllers;

use Carbon\Carbon;
use DateTime;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Comment;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\EventType;
use App\Models\Project;
use App\Services\ModalFormService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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
        $viewMode = in_array($queryParams['view'] ?? '', ['list', 'calendar'], true)
            ? $queryParams['view']
            : 'list';

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

        $this->hydrateVisibleComments($events, $userId);

        $bootstrapColorMap = [
            'primary'   => '#0d6efd',
            'secondary' => '#6c757d',
            'success'   => '#198754',
            'danger'    => '#dc3545',
            'warning'   => '#ffc107',
            'info'      => '#0dcaf0',
            'light'     => '#f8f9fa',
            'dark'      => '#212529',
        ];
        $calendarEvents = $events->map(static function ($event) use ($bootstrapColorMap): array {
            $colorName = (string) ($event->type_color ?? 'secondary');
            return [
                'id'    => $event->id,
                'title' => htmlspecialchars((string) $event->title, ENT_QUOTES, 'UTF-8'),
                'start' => $event->starts_at instanceof \DateTimeInterface
                    ? $event->starts_at->format('Y-m-d\TH:i:s')
                    : (string) $event->starts_at,
                'end'   => $event->ends_at instanceof \DateTimeInterface
                    ? $event->ends_at->format('Y-m-d\TH:i:s')
                    : (string) $event->ends_at,
                'color' => $bootstrapColorMap[$colorName] ?? '#6c757d',
                'url'   => '/events/' . $event->id,
            ];
        })->values()->all();
        $calendarEventsJson = json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        $projects = $accessibleProjects;
        $eventTypes = EventType::orderBy('name')->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);
        $createService = new ModalFormService('event_create');
        $createState = $createService->getState();
        $createService->clear();

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
            'create_form' => $createState,
            'view_mode' => $viewMode,
            'calendar_events' => $calendarEventsJson,
        ]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $event = Event::find((int) $args['id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            return $response->withStatus(403);
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $event->setRelation('comments', $this->getVisibleEventComments($event->id, $userId));

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'events/detail.twig', [
            'event' => $event,
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function addNote(Request $request, Response $response, array $args): Response
    {
        $event = Event::find((int) $args['id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(403);
        }

        $data = (array) $request->getParsedBody();
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            $_SESSION['error'] = 'Bemerkung darf nicht leer sein.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
        }

        Comment::create([
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'comment' => $content,
            'is_private' => !empty($data['is_private']),
        ]);

        $_SESSION['success'] = 'Bemerkung hinzugefügt.';
        return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
    }

    public function updateNote(Request $request, Response $response, array $args): Response
    {
        $event = Event::find((int) $args['id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(403);
        }

        $note = $this->findEventNote($event->id, (int) $args['note_id']);
        if (!$note) {
            $_SESSION['error'] = 'Bemerkung nicht gefunden.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
        }

        if (!$this->canManageEventNote($note, $event)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(403);
        }

        $data = (array) $request->getParsedBody();
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            $_SESSION['error'] = 'Bemerkung darf nicht leer sein.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
        }

        $note->update(['comment' => $content]);

        $_SESSION['success'] = 'Private Bemerkung aktualisiert.';
        return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
    }

    public function deleteNote(Request $request, Response $response, array $args): Response
    {
        $event = Event::find((int) $args['id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(403);
        }

        $note = $this->findEventNote($event->id, (int) $args['note_id']);
        if (!$note) {
            $_SESSION['error'] = 'Bemerkung nicht gefunden.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
        }

        if (!$this->canManageEventNote($note, $event)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(403);
        }

        $note->delete();

        $_SESSION['success'] = 'Private Bemerkung gelöscht.';
        return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
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

        $formData = [
            'title' => $title,
            'starts_at' => $startsAtDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'event_type_id' => $eventTypeId ?? '',
            'project_id' => $projectId ?? '',
            'location' => trim($data['location'] ?? ''),
            'repeat' => $repeat,
            'recurrence_interval' => trim((string) ($data['recurrence_interval'] ?? '1')),
            'frequency' => trim((string) ($data['frequency'] ?? 'weekly')),
            'weekdays' => array_values(array_map('intval', (array) ($data['weekdays'] ?? [1]))),
            'series_end_date' => trim((string) ($data['series_end_date'] ?? '')),
        ];

        if (!$this->canAccessProjectId($projectId)) {
            $createService = new ModalFormService('event_create');
            $createService->setError('Zugriff verweigert.', $formData);
            return $response->withHeader('Location', '/events')->withStatus(403);
        }

        if (!$startsAtDate || !$startTime || !$endTime) {
            $createService = new ModalFormService('event_create');
            $createService->setError('Datum, Startzeit und Endzeit sind Pflichtfelder.', $formData);
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
            $createService = new ModalFormService('event_create');
            $createService->setError('Ungültiges Datum oder Zeitformat.', $formData);
            return $response->withHeader('Location', '/events')->withStatus(302);
        }
        if (!$parsedEnd->greaterThan($parsedStart)) {
            $createService = new ModalFormService('event_create');
            $createService->setError('Endzeit muss nach der Startzeit liegen.', $formData);
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
            }
        } catch (Exception $e) {
            $createService = new ModalFormService('event_create');
            $createService->setError('Fehler beim Anlegen: ' . $e->getMessage(), $formData);
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

        // Get error and form data from ModalFormService
        $editService = new ModalFormService('event_edit');
        $state = $editService->getState();
        $error = $state['open_modal'] ? ($_SESSION['error'] ?? null) : null;
        unset($_SESSION['error']);
        $editForm = $state['form'] ?? [];
        $editService->clear();

        // If no form data from service, build from event
        if (empty($editForm)) {
            $editForm = [
                'title' => (string) $event->title,
                'starts_at' => Carbon::parse($event->starts_at)->format('Y-m-d'),
                'start_time' => Carbon::parse($event->starts_at)->format('H:i'),
                'end_time' => Carbon::parse($event->ends_at)->format('H:i'),
                'event_type_id' => $event->event_type_id !== null ? (string) $event->event_type_id : '',
                'project_id' => $event->project_id !== null ? (string) $event->project_id : '',
                'location' => (string) ($event->location ?? ''),
                'update_series' => false,
            ];
        }

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

        $formData = [
            'title' => $title,
            'starts_at' => $startsAtDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'event_type_id' => $eventTypeId ?? '',
            'project_id' => $projectId ?? '',
            'location' => trim($data['location'] ?? ''),
            'update_series' => $updateSeries,
        ];

        if (!$this->canAccessProjectId($projectId)) {
            $editService = new ModalFormService('event_edit');
            $editService->setError('Zugriff verweigert.', $formData);
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(403);
        }

        if (!$startsAtDate || !$startTime || !$endTime) {
            $editService = new ModalFormService('event_edit');
            $editService->setError('Datum, Startzeit und Endzeit sind Pflichtfelder.', $formData);
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
            $editService = new ModalFormService('event_edit');
            $editService->setError('Ungültiges Datum oder Zeitformat.', $formData);
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }
        if (!$parsedEnd->greaterThan($parsedStart)) {
            $editService = new ModalFormService('event_edit');
            $editService->setError('Endzeit muss nach der Startzeit liegen.', $formData);
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
                    $editService = new ModalFormService('event_edit');
                    $editService->setError('Zugriff verweigert.', $formData);
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
            } else {
                $updateData['starts_at'] = $startsAt;
                $updateData['ends_at'] = $endsAt;
                $event->update($updateData);
                $_SESSION['success'] = 'Event erfolgreich aktualisiert.';
            }
        } catch (Exception $e) {
            $editService = new ModalFormService('event_edit');
            $editService->setError('Fehler beim Aktualisieren: ' . $e->getMessage(), $formData);
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

    private function hydrateVisibleComments(EloquentCollection $events, int $userId): void
    {
        $eventIds = $events->pluck('id')->map(static fn($id) => (int) $id)->all();
        if ($eventIds === []) {
            return;
        }

        $comments = Comment::with('user')
            ->where('entity_type', 'event')
            ->whereIn('entity_id', $eventIds)
            ->where(function ($query) use ($userId) {
                $query->where('is_private', false)
                    ->orWhere(function ($subQuery) use ($userId) {
                        $subQuery->where('is_private', true)->where('user_id', $userId);
                    });
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('entity_id');

        foreach ($events as $event) {
            $event->setRelation('comments', $comments->get($event->id) ?? collect());
        }
    }

    private function getVisibleEventComments(int $eventId, int $userId)
    {
        return Comment::with('user')
            ->where('entity_type', 'event')
            ->where('entity_id', $eventId)
            ->where(function ($query) use ($userId) {
                $query->where('is_private', false)
                    ->orWhere(function ($subQuery) use ($userId) {
                        $subQuery->where('is_private', true)->where('user_id', $userId);
                    });
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->values();
    }

    private function findEventNote(int $eventId, int $noteId): ?Comment
    {
        return Comment::query()
            ->where('id', $noteId)
            ->where('entity_type', 'event')
            ->where('entity_id', $eventId)
            ->first();
    }

    private function canManageEventNote(Comment $note, Event $event): bool
    {
        if ($note->entity_type !== 'event') {
            return false;
        }

        if ((bool) $note->is_private) {
            return (int) $note->user_id === (int) ($_SESSION['user_id'] ?? 0);
        }

        return $this->canEditEvent($event);
    }

    private function canEditEvent(Event $event): bool
    {
        return (bool) ($_SESSION['can_manage_users'] ?? false)
            && $this->canAccessEvent($event);
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
}
