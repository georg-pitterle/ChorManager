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
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'voice_groups/index.twig', [
            'voice_groups' => $voiceGroups,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function createGroup(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');

        if (!$name) {
            $_SESSION['error'] = 'Der Name der Stimmgruppe darf nicht leer sein.';
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            VoiceGroup::create(['name' => $name]);
            $_SESSION['success'] = 'Stimmgruppe erfolgreich angelegt.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Anlegen: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }

    public function updateGroup(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');

        if (!$name) {
            $_SESSION['error'] = 'Der Name darf nicht leer sein.';
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            $group = VoiceGroup::findOrFail($id);
            $group->update(['name' => $name]);
            $_SESSION['success'] = 'Stimmgruppe erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Aktualisieren: ' . $e->getMessage();
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
            $_SESSION['error'] = 'Fehler beim Löschen: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }

    public function createSubVoice(Request $request, Response $response, array $args): Response
    {
        $groupId = (int)$args['id'];
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');

        if (!$name) {
            $_SESSION['error'] = 'Der Name der Unterstimme darf nicht leer sein.';
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            SubVoice::create([
                'name' => $name,
                'voice_group_id' => $groupId
            ]);
            $_SESSION['success'] = 'Unterstimme erfolgreich angelegt.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Anlegen: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }

    public function updateSubVoice(Request $request, Response $response, array $args): Response
    {
        $subId = (int)$args['sub_id'];
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');

        if (!$name) {
            $_SESSION['error'] = 'Der Name darf nicht leer sein.';
            return $response->withHeader('Location', '/voice-groups')->withStatus(302);
        }

        try {
            $subVoice = SubVoice::findOrFail($subId);
            $subVoice->update(['name' => $name]);
            $_SESSION['success'] = 'Unterstimme erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Aktualisieren: ' . $e->getMessage();
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
            $_SESSION['error'] = 'Fehler beim Löschen: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/voice-groups')->withStatus(302);
    }
}
