<?php

declare(strict_types=1);

namespace App\Controllers;

use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Event;
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
        $projectId = !empty($queryParams['project_id']) ? (int)$queryParams['project_id'] : null;
        $eventTypeId = !empty($queryParams['event_type_id']) ? (int)$queryParams['event_type_id'] : null;
        $sort = $queryParams['sort'] ?? 'event_date';
        $direction = $queryParams['direction'] ?? 'asc';
        $showOldEvents = !empty($queryParams['show_old_events']) ? (int)$queryParams['show_old_events'] : 0;

        // Allowed sort columns
        $allowedSorts = ['event_date', 'title', 'type', 'project_name', 'location'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'event_date';
        }

        $query = Event::query();

        if ($projectId) {
            $query->where('project_id', $projectId);
        }
        if ($eventTypeId) {
            $query->where('event_type_id', $eventTypeId);
        }

        // Filter out old events (older than 14 days) unless show_old_events=1
        if (!$showOldEvents) {
            $query->whereDate('event_date', '>=', Carbon::now()->subDays(14));
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
        $eventTypesMap = \App\Models\EventType::whereIn('id', $eventTypeIds)->get()->keyBy('id');
        $seriesMap = \App\Models\EventSeries::whereIn('id', $seriesIds)->get()->keyBy('id');

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

        $projects = Project::orderBy('name')->get();
        $eventTypes = \App\Models\EventType::orderBy('name')->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

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
            'error' => $error
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $title = trim($data['title'] ?? '');
        $eventDateStr = $data['event_date'] ?? '';
        $eventTypeId = !empty($data['event_type_id']) ? (int)$data['event_type_id'] : null;
        $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;
        $repeat = !empty($data['repeat']);

        if (!$eventDateStr) {
            $_SESSION['error'] = 'Datum ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        try {
            $eventType = null;
            if ($eventTypeId) {
                $eventType = \App\Models\EventType::find($eventTypeId);
            }
            $typeName = $eventType ? $eventType->name : 'Probe';

            if (empty($title)) {
                $title = $typeName;
            }

            if (!$repeat) {
                // Single event
                Event::create([
                    'title' => $title,
                    'event_date' => $eventDateStr,
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
                    throw new \Exception('Enddatum für die Serie ist erforderlich.');
                }

                $series = \App\Models\EventSeries::create([
                    'frequency' => $frequency,
                    'recurrence_interval' => $interval,
                    'weekdays' => !empty($weekdays) ? implode(',', $weekdays) : null,
                    'end_date' => $endDateStr
                ]);

                $startDate = new \DateTime($eventDateStr);
                $endDate = new \DateTime($endDateStr);
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
                            'event_date' => $currentDate->format('Y-m-d H:i:s'),
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
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler: ' . $e->getMessage();
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

        $projects = Project::orderBy('name')->get();
        $eventTypes = \App\Models\EventType::orderBy('name')->get();

        return $this->view->render($response, 'events/edit.twig', [
            'event' => $event,
            'projects' => $projects,
            'event_types' => $eventTypes
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

        $data = (array)$request->getParsedBody();
        $title = trim($data['title'] ?? '');
        $eventDateStr = $data['event_date'] ?? '';
        $eventTypeId = !empty($data['event_type_id']) ? (int)$data['event_type_id'] : null;
        $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;
        $updateSeries = !empty($data['update_series']);

        if (!$eventDateStr) {
            $_SESSION['error'] = 'Datum ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }

        try {
            $eventType = null;
            if ($eventTypeId) {
                $eventType = \App\Models\EventType::find($eventTypeId);
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
                                        ->where('event_date', '>=', $event->event_date)
                                        ->get();

                foreach ($eventsToUpdate as $eventInSeries) {
                    $eventInSeries->update($updateData);
                }

                $event->update([
                    'event_date' => $eventDateStr,
                ]);

                $_SESSION['success'] = 'Event-Serie (' . count($eventsToUpdate) . ' Termine) erfolgreich aktualisiert.';
            } else {
                 $updateData['event_date'] = $eventDateStr;
                 $event->update($updateData);
                $_SESSION['success'] = 'Event erfolgreich aktualisiert.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler: ' . $e->getMessage();
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }

        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $event = Event::find($id);
        if ($event) {
            $event->delete();
            $_SESSION['success'] = 'Termin gelöscht.';
        }
        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    public function deleteSeries(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $event = Event::find($id);
        if ($event && $event->series_id) {
            $seriesId = $event->series_id;
            // Delete all future events of this series (including current)
            Event::where('series_id', $seriesId)
                ->where('event_date', '>=', $event->event_date)
                ->delete();

            $_SESSION['success'] = 'Alle zukünftigen Termine der Serie wurden gelöscht.';
        }
        return $response->withHeader('Location', '/events')->withStatus(302);
    }
}
