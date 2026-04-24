<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\VoiceGroup;
use App\Models\SubVoice;
use App\Services\ModalFormService;

class VoiceGroupController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $voiceGroups = VoiceGroup::with('subVoices')->orderBy('name')->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        // Get create group form state
        $createGroupService = new ModalFormService('voice_group_create');
        $createGroupState = $createGroupService->getState();
        $createGroupService->clear();

        // Get edit group form states
        $editGroupStates = [];
        foreach ($voiceGroups as $group) {
            $editGroupService = new ModalFormService('voice_group_edit_' . $group->id);
            $editGroupStates[$group->id] = $editGroupService->getState();
            $editGroupService->clear();
        }

        // Get create subvoice form states
        $createSubStates = [];
        foreach ($voiceGroups as $group) {
            $createSubService = new ModalFormService('voice_sub_create_' . $group->id);
            $createSubStates[$group->id] = $createSubService->getState();
            $createSubService->clear();
        }

        // Get edit subvoice form states
        $editSubStates = [];
        foreach ($voiceGroups as $group) {
            foreach ($group->subVoices as $subVoice) {
                $editSubService = new ModalFormService('voice_sub_edit_' . $subVoice->id);
                $editSubStates[$subVoice->id] = $editSubService->getState();
                $editSubService->clear();
            }
        }

        return $this->view->render($response, 'voice_groups/index.twig', [
            'voice_groups' => $voiceGroups,
            'success' => $success,
            'error' => $error,
            'modal_form_create_group' => $createGroupState,
            'modal_form_edit_groups' => $editGroupStates,
            'modal_form_create_subs' => $createSubStates,
            'modal_form_edit_subs' => $editSubStates,
            'has_modal_error' => $createGroupState['open_modal']
                || !empty(array_filter($editGroupStates, fn($s) => $s['open_modal']))
                || !empty(array_filter($createSubStates, fn($s) => $s['open_modal']))
                || !empty(array_filter($editSubStates, fn($s) => $s['open_modal'])),
        ]);
    }

    public function createGroup(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');

        $formData = ['name' => $name];

        if (!$name) {
            $createService = new ModalFormService('voice_group_create');
            $createService->setError('Der Name der Stimmgruppe darf nicht leer sein.', $formData);
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            VoiceGroup::create(['name' => $name]);
            $_SESSION['success'] = 'Stimmgruppe erfolgreich angelegt.';
        } catch (\Exception $e) {
            $createService = new ModalFormService('voice_group_create');
            $createService->setError('Fehler beim Anlegen: ' . $e->getMessage(), $formData);
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }

    public function updateGroup(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');

        $formData = ['name' => $name];

        if (!$name) {
            $editService = new ModalFormService('voice_group_edit_' . $id);
            $editService->setError('Der Name darf nicht leer sein.', $formData);
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            $group = VoiceGroup::findOrFail($id);
            $group->update(['name' => $name]);
            $_SESSION['success'] = 'Stimmgruppe erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $editService = new ModalFormService('voice_group_edit_' . $id);
            $editService->setError('Fehler beim Aktualisieren: ' . $e->getMessage(), $formData);
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }

    public function deleteGroup(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        try {
            $group = VoiceGroup::findOrFail($id);
            $group->delete();
            $_SESSION['success'] = 'Stimmgruppe erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Löschen: ';
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }

    public function createSubVoice(Request $request, Response $response, array $args): Response
    {
        $groupId = (int)$args['id'];
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');

        $formData = ['name' => $name];

        if (!$name) {
            $createService = new ModalFormService('voice_sub_create_' . $groupId);
            $createService->setError('Der Name der Unterstimme darf nicht leer sein.', $formData);
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            SubVoice::create([
                'name' => $name,
                'voice_group_id' => $groupId
            ]);
            $_SESSION['success'] = 'Unterstimme erfolgreich angelegt.';
        } catch (\Exception $e) {
            $createService = new ModalFormService('voice_sub_create_' . $groupId);
            $createService->setError('Fehler beim Anlegen: ' . $e->getMessage(), $formData);
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }

    public function updateSubVoice(Request $request, Response $response, array $args): Response
    {
        $subId = (int)$args['sub_id'];
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');

        $formData = ['name' => $name];

        if (!$name) {
            $editService = new ModalFormService('voice_sub_edit_' . $subId);
            $editService->setError('Der Name darf nicht leer sein.', $formData);
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            $subVoice = SubVoice::findOrFail($subId);
            $subVoice->update(['name' => $name]);
            $_SESSION['success'] = 'Unterstimme erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $editService = new ModalFormService('voice_sub_edit_' . $subId);
            $editService->setError('Fehler beim Aktualisieren: ' . $e->getMessage(), $formData);
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }

    public function deleteSubVoice(Request $request, Response $response, array $args): Response
    {
        $subId = (int)$args['sub_id'];

        try {
            $subVoice = SubVoice::findOrFail($subId);
            $subVoice->delete();
            $_SESSION['success'] = 'Unterstimme erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Löschen: ';
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }
}
