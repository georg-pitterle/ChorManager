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
        $modalError = $_SESSION['event_type_modal_error'] ?? null;
        $createForm = $_SESSION['event_type_create_form'] ?? [];
        $editForms = $_SESSION['event_type_edit_forms'] ?? [];
        $openCreateModal = !empty($_SESSION['event_type_open_create_modal']);
        $openEditModalId = (int) ($_SESSION['event_type_open_edit_modal_id'] ?? 0);
        unset($_SESSION['success'], $_SESSION['error']);
        unset(
            $_SESSION['event_type_create_form'],
            $_SESSION['event_type_edit_forms'],
            $_SESSION['event_type_open_create_modal'],
            $_SESSION['event_type_open_edit_modal_id'],
            $_SESSION['event_type_modal_error']
        );

        $createForm = array_merge([
            'name' => '',
            'color' => 'info',
        ], is_array($createForm) ? $createForm : []);

        return $this->view->render($response, 'settings/event_types.twig', [
            'event_types' => $eventTypes,
            'success' => $success,
            'error' => $error,
            'create_form' => $createForm,
            'edit_forms' => is_array($editForms) ? $editForms : [],
            'open_create_modal' => $openCreateModal,
            'open_edit_modal_id' => $openEditModalId,
            'modal_error' => is_array($modalError) ? $modalError : null,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $color = $data['color'] ?? 'info';

        if (!$name) {
            $_SESSION['event_type_create_form'] = [
                'name' => $name,
                'color' => $color,
            ];
            $_SESSION['event_type_open_create_modal'] = true;
            $_SESSION['event_type_modal_error'] = ['scope' => 'create'];
            $_SESSION['error'] = 'Name ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/event-types')->withStatus(302);
        }

        try {
            EventType::create([
                'name' => $name,
                'color' => $color
            ]);
            unset($_SESSION['event_type_create_form'], $_SESSION['event_type_open_create_modal']);
            unset($_SESSION['event_type_modal_error']);
            $_SESSION['success'] = 'Event-Typ erfolgreich angelegt.';
        } catch (\Exception $e) {
            $_SESSION['event_type_create_form'] = [
                'name' => $name,
                'color' => $color,
            ];
            $_SESSION['event_type_open_create_modal'] = true;
            $_SESSION['event_type_modal_error'] = ['scope' => 'create'];
            $_SESSION['error'] = 'Fehler beim Anlegen: ';
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
            if (!isset($_SESSION['event_type_edit_forms']) || !is_array($_SESSION['event_type_edit_forms'])) {
                $_SESSION['event_type_edit_forms'] = [];
            }
            $_SESSION['event_type_edit_forms'][$id] = [
                'name' => $name,
                'color' => $color,
            ];
            $_SESSION['event_type_open_edit_modal_id'] = $id;
            $_SESSION['event_type_modal_error'] = ['scope' => 'edit', 'id' => $id];
            $_SESSION['error'] = 'Name ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/event-types')->withStatus(302);
        }

        try {
            $eventType = EventType::findOrFail($id);
            $eventType->update([
                'name' => $name,
                'color' => $color
            ]);
            if (isset($_SESSION['event_type_edit_forms']) && is_array($_SESSION['event_type_edit_forms'])) {
                unset($_SESSION['event_type_edit_forms'][$id]);
            }
            unset($_SESSION['event_type_modal_error']);
            $_SESSION['success'] = 'Event-Typ erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            if (!isset($_SESSION['event_type_edit_forms']) || !is_array($_SESSION['event_type_edit_forms'])) {
                $_SESSION['event_type_edit_forms'] = [];
            }
            $_SESSION['event_type_edit_forms'][$id] = [
                'name' => $name,
                'color' => $color,
            ];
            $_SESSION['event_type_open_edit_modal_id'] = $id;
            $_SESSION['event_type_modal_error'] = ['scope' => 'edit', 'id' => $id];
            $_SESSION['error'] = 'Fehler beim Aktualisieren: ';
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
