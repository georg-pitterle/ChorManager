<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\EventType;
use App\Services\ModalFormService;

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

        // Get create form state
        $createService = new ModalFormService('event_type_create');
        $createState = $createService->getState();
        $createService->clear();

        // Get all edit form states
        $editStates = [];
        foreach ($eventTypes as $type) {
            $editService = new ModalFormService('event_type_edit_' . $type->id);
            $editStates[$type->id] = $editService->getState();
            $editService->clear();
        }

        return $this->view->render($response, 'settings/event_types.twig', [
            'event_types' => $eventTypes,
            'success' => $success,
            'error' => $error,
            'modal_form_create' => $createState,
            'modal_form_edits' => $editStates,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $color = $data['color'] ?? 'info';

        $formData = [
            'name' => $name,
            'color' => $color,
        ];

        if (!$name) {
            $createService = new ModalFormService('event_type_create');
            $createService->setError('Name ist ein Pflichtfeld.', $formData);
            return $response->withHeader('Location', '/event-types')->withStatus(302);
        }

        try {
            EventType::create([
                'name' => $name,
                'color' => $color
            ]);
            $_SESSION['success'] = 'Event-Typ erfolgreich angelegt.';
        } catch (\Exception $e) {
            $createService = new ModalFormService('event_type_create');
            $createService->setError('Fehler beim Anlegen: ' . $e->getMessage(), $formData);
        }

        return $response->withHeader('Location', '/event-types')->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $color = $data['color'] ?? 'info';

        $formData = [
            'name' => $name,
            'color' => $color,
        ];

        if (!$name) {
            $editService = new ModalFormService('event_type_edit_' . $id);
            $editService->setError('Name ist ein Pflichtfeld.', $formData);
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
            $editService = new ModalFormService('event_type_edit_' . $id);
            $editService->setError('Fehler beim Aktualisieren: ' . $e->getMessage(), $formData);
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
            $_SESSION['error'] = 'Fehler beim Löschen: ';
        }

        return $response->withHeader('Location', '/event-types')->withStatus(302);
    }
}
