<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Project;
use App\Queries\ProjectQuery;
use App\Persistence\ProjectPersistence;

class ProjectController
{
    private Twig $view;
    private ProjectQuery $projectQuery;
    private ProjectPersistence $projectPersistence;

    public function __construct(
        Twig $view,
        ProjectQuery $projectQuery,
        ProjectPersistence $projectPersistence
    ) {
        $this->view = $view;
        $this->projectQuery = $projectQuery;
        $this->projectPersistence = $projectPersistence;
    }

    public function index(Request $request, Response $response): Response
    {
        $projects = clone $this->projectQuery->getAllProjects();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'projects/index.twig', [
            'projects' => $projects,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $startDate = $data['start_date'] ?? null;
        $endDate = $data['end_date'] ?? null;

        if (!$name) {
            $_SESSION['error'] = 'Geben Sie einen Namen für das Projekt ein.';
            return $response->withHeader('Location', '/projects')->withStatus(302);
        }

        $project = new Project();
        $project->name = $name;
        $project->description = $description;
        $project->start_date = $startDate ?: null;
        $project->end_date = $endDate ?: null;
        $project->save();

        $_SESSION['success'] = 'Projekt erfolgreich angelegt.';
        return $response->withHeader('Location', '/projects')->withStatus(302);
    }

    public function showMembers(Request $request, Response $response, array $args): Response
    {
        $projectId = (int)$args['id'];

        $project = $this->projectQuery->findById($projectId);

        if (!$project) {
            $_SESSION['error'] = 'Projekt nicht gefunden.';
            return $response->withHeader('Location', '/projects')->withStatus(302);
        }

        $members = $this->projectQuery->getProjectMembers($projectId);
        $availableUsers = $this->projectQuery->getUsersNotInProject($projectId);

        // Map members to the array structure Twig expects
        $mappedMembers = $members->map(function ($user) {
            // Group concatenating the voice groups manually to replicate old behavior
            $vgDisplays = [];
            foreach ($user->voiceGroups as $vg) {
                $display = $vg->name;
                if ($vg->pivot->sub_voice_id) {
                    $sv = $user->subVoices->firstWhere('id', $vg->pivot->sub_voice_id);
                    if ($sv) {
                        $display .= ' (' . $sv->name . ')';
                    }
                }
                $vgDisplays[] = $display;
            }

            return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'voice_groups_display' => implode(', ', array_unique($vgDisplays))
            ];
        });

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'projects/members.twig', [
            'project' => $project,
            'members' => $mappedMembers,
            'available_users' => $availableUsers,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function addMember(Request $request, Response $response, array $args): Response
    {
        $projectId = (int)$args['id'];
        $data = (array)$request->getParsedBody();
        $userId = (int)($data['user_id'] ?? 0);

        if ($userId <= 0) {
            $_SESSION['error'] = 'Bitte einen Benutzer auswählen.';
            return $response->withHeader('Location', '/projects/' . $projectId . '/members')->withStatus(302);
        }

        $this->projectPersistence->addProjectMember($projectId, $userId);
        $_SESSION['success'] = 'Mitglied dem Projekt hinzugefügt.';
        return $response->withHeader('Location', '/projects/' . $projectId . '/members')->withStatus(302);
    }

    public function removeMember(Request $request, Response $response, array $args): Response
    {
        $projectId = (int)$args['id'];
        $userId = (int)($args['user_id'] ?? 0);

        if ($userId <= 0) {
            $_SESSION['error'] = 'Ungültige Anfrage.';
            return $response->withHeader('Location', '/projects/' . $projectId . '/members')->withStatus(302);
        }

        $this->projectPersistence->removeProjectMember($projectId, $userId);
        $_SESSION['success'] = 'Mitglied vom Projekt entfernt.';
        return $response->withHeader('Location', '/projects/' . $projectId . '/members')->withStatus(302);
    }
}
