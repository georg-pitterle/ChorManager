<?php

declare(strict_types=1);

namespace App\Controllers;

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
        $events = Event::with('project')->orderBy('event_date', 'desc')->get();
        // Since the twig template expects "project_name" as an attribute based on the old raw query
        $events->map(function ($event) {
            $event->project_name = $event->project ? $event->project->name : null;
            return $event;
        });

        $projects = Project::orderBy('name')->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'events/index.twig', [
            'events' => $events,
            'projects' => $projects,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $title = trim($data['title'] ?? '');
        $eventDate = $data['event_date'] ?? '';
        $type = $data['type'] ?? 'Probe';
        $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;

        if (!$title || !$eventDate) {
            $_SESSION['error'] = 'Titel und Datum sind Pflichtfelder.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        try {
            Event::create([
                'title' => $title,
                'event_date' => $eventDate,
                'type' => $type,
                'project_id' => $projectId
            ]);
            $_SESSION['success'] = 'Event (' . $type . ') erfolgreich angelegt.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Anlegen des Events: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/events')->withStatus(302);
    }
}
