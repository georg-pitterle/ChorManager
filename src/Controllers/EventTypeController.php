<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use App\Models\EventType;
use App\Services\ModalFormService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Util\InputValidator;

class EventTypeController
{
    /**
     * Die Farben, die das Formular zur Auswahl stellt - und damit die einzigen,
     * für die Bootstrap eine `bg-*`-Klasse kennt. Ein abweichender Wert kommt
     * nicht aus der Oberfläche; gespeichert ergäbe er `bg-neongruen` und das
     * Abzeichen der Terminart bliebe für immer ungefärbt.
     *
     * @var list<string>
     */
    private const ALLOWED_COLORS = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark'];

    private const DEFAULT_COLOR = 'info';

    private Twig $view;
    private LoggerInterface $logger;

    public function __construct(Twig $view, ?LoggerInterface $logger = null)
    {
        $this->view = $view;
        $this->logger = $logger ?? new NullLogger();
    }

    private function normalizeColor(mixed $value): string
    {
        $color = InputValidator::asString($value);

        return in_array($color, self::ALLOWED_COLORS, true) ? $color : self::DEFAULT_COLOR;
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
        $name = trim(InputValidator::asString($data['name'] ?? null));
        $color = $this->normalizeColor($data['color'] ?? null);

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
            $this->logger->error('Creating an event type failed.', [
                'event' => 'event_type.create.failed',
                'exception' => $e,
            ]);
            $createService = new ModalFormService('event_type_create');
            $createService->setError('Fehler beim Anlegen der Terminart.', $formData);
        }

        return $response->withHeader('Location', '/event-types')->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = (array)$request->getParsedBody();
        $name = trim(InputValidator::asString($data['name'] ?? null));
        $color = $this->normalizeColor($data['color'] ?? null);

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
            $this->logger->error('Updating an event type failed.', [
                'event' => 'event_type.update.failed',
                'event_type_id' => $id,
                'exception' => $e,
            ]);
            $editService = new ModalFormService('event_type_edit_' . $id);
            $editService->setError('Fehler beim Aktualisieren der Terminart.', $formData);
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
        } catch (ModelNotFoundException $e) {
            // Der häufigste Weg hierher: Die Seite lag offen, während jemand anderes
            // dieselbe Terminart entfernt hat. Das ist kein Fehler, den der Betrieb
            // sehen muss, und der Grund gehört in die Meldung statt in ein Rätsel.
            $_SESSION['error'] = 'Die Terminart wurde nicht gefunden. '
                . 'Möglicherweise wurde sie bereits gelöscht.';
        } catch (\Exception $e) {
            $this->logger->error('Deleting an event type failed.', [
                'event' => 'event_type.delete.failed',
                'event_type_id' => $id,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Die Terminart konnte nicht gelöscht werden. '
                . 'Bitte die Seite neu laden und es erneut versuchen.';
        }

        return $response->withHeader('Location', '/event-types')->withStatus(302);
    }
}
