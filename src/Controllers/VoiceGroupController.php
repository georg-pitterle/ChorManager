<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\VoiceGroup;
use App\Models\SubVoice;

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
        $modalError = $_SESSION['voice_group_modal_error'] ?? null;
        $openModal = $_SESSION['voice_group_open_modal'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);
        unset($_SESSION['voice_group_modal_error'], $_SESSION['voice_group_open_modal']);

        return $this->view->render($response, 'voice_groups/index.twig', [
            'voice_groups' => $voiceGroups,
            'success' => $success,
            'error' => $error,
            'modal_error' => is_array($modalError) ? $modalError : null,
            'open_modal' => is_array($openModal) ? $openModal : null,
        ]);
    }

    public function createGroup(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');

        if (!$name) {
            $_SESSION['voice_group_modal_error'] = ['scope' => 'create_group'];
            $_SESSION['voice_group_open_modal'] = ['scope' => 'create_group'];
            $_SESSION['error'] = 'Der Name der Stimmgruppe darf nicht leer sein.';
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            VoiceGroup::create(['name' => $name]);
            unset($_SESSION['voice_group_modal_error'], $_SESSION['voice_group_open_modal']);
            $_SESSION['success'] = 'Stimmgruppe erfolgreich angelegt.';
        } catch (\Exception $e) {
            $_SESSION['voice_group_modal_error'] = ['scope' => 'create_group'];
            $_SESSION['voice_group_open_modal'] = ['scope' => 'create_group'];
            $_SESSION['error'] = 'Fehler beim Anlegen: ';
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }

    public function updateGroup(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');

        if (!$name) {
            $_SESSION['voice_group_modal_error'] = ['scope' => 'edit_group', 'group_id' => $id];
            $_SESSION['voice_group_open_modal'] = ['scope' => 'edit_group', 'group_id' => $id];
            $_SESSION['error'] = 'Der Name darf nicht leer sein.';
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            $group = VoiceGroup::findOrFail($id);
            $group->update(['name' => $name]);
            unset($_SESSION['voice_group_modal_error'], $_SESSION['voice_group_open_modal']);
            $_SESSION['success'] = 'Stimmgruppe erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $_SESSION['voice_group_modal_error'] = ['scope' => 'edit_group', 'group_id' => $id];
            $_SESSION['voice_group_open_modal'] = ['scope' => 'edit_group', 'group_id' => $id];
            $_SESSION['error'] = 'Fehler beim Aktualisieren: ';
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

        if (!$name) {
            $_SESSION['voice_group_modal_error'] = ['scope' => 'create_sub', 'group_id' => $groupId];
            $_SESSION['voice_group_open_modal'] = ['scope' => 'create_sub', 'group_id' => $groupId];
            $_SESSION['error'] = 'Der Name der Unterstimme darf nicht leer sein.';
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            SubVoice::create([
                'name' => $name,
                'voice_group_id' => $groupId
            ]);
            unset($_SESSION['voice_group_modal_error'], $_SESSION['voice_group_open_modal']);
            $_SESSION['success'] = 'Unterstimme erfolgreich angelegt.';
        } catch (\Exception $e) {
            $_SESSION['voice_group_modal_error'] = ['scope' => 'create_sub', 'group_id' => $groupId];
            $_SESSION['voice_group_open_modal'] = ['scope' => 'create_sub', 'group_id' => $groupId];
            $_SESSION['error'] = 'Fehler beim Anlegen: ';
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }

    public function updateSubVoice(Request $request, Response $response, array $args): Response
    {
        $subId = (int)$args['sub_id'];
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');

        if (!$name) {
            $_SESSION['voice_group_modal_error'] = ['scope' => 'edit_sub', 'sub_id' => $subId];
            $_SESSION['voice_group_open_modal'] = ['scope' => 'edit_sub', 'sub_id' => $subId];
            $_SESSION['error'] = 'Der Name darf nicht leer sein.';
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            $subVoice = SubVoice::findOrFail($subId);
            $subVoice->update(['name' => $name]);
            unset($_SESSION['voice_group_modal_error'], $_SESSION['voice_group_open_modal']);
            $_SESSION['success'] = 'Unterstimme erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $_SESSION['voice_group_modal_error'] = ['scope' => 'edit_sub', 'sub_id' => $subId];
            $_SESSION['voice_group_open_modal'] = ['scope' => 'edit_sub', 'sub_id' => $subId];
            $_SESSION['error'] = 'Fehler beim Aktualisieren: ';
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
