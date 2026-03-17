<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\EventType;

class EventTypeController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $eventTypes = EventType::orderBy('name')->get();
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'settings/event_types.twig', [
            'event_types' => $eventTypes,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $color = $data['color'] ?? 'info';

        if (!$name) {
            $_SESSION['error'] = 'Name ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/event-types')->withStatus(302);
        }

        try {
            EventType::create([
                'name' => $name,
                'color' => $color
            ]);
            $_SESSION['success'] = 'Event-Typ erfolgreich angelegt.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Anlegen: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/event-types')->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $color = $data['color'] ?? 'info';

        if (!$name) {
            $_SESSION['error'] = 'Name ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/event-types')->withStatus(302);
        }

        try {
            $eventType = EventType::findOrFail($id);
            $eventType->update([
                'name' => $name,
                'color' => $color
            ]);
            $_SESSION['success'] = 'Event-Typ erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Aktualisieren: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/event-types')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        try {
            $eventType = EventType::findOrFail($id);
            $eventType->delete();
            $_SESSION['success'] = 'Event-Typ erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Löschen: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/event-types')->withStatus(302);
    }
}
