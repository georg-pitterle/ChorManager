<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Project;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Util\UploadValidator;

class DownloadController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    /**
     * Was der Abspieler auf der Download-Seite abspielen darf, ist genau das,
     * was der Upload durchlässt. Eine zweite Liste an dieser Stelle war der
     * Grund, warum der Abspieler ins Leere lief: Sie führte MP3 und MIDI, der
     * UploadValidator kannte kein einziges Audioformat.
     *
     * @return list<string>
     */
    public static function streamableMimeTypes(): array
    {
        return UploadValidator::getAudioMimeTypes();
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($userId <= 0) {
            return $this->view->render($response, 'songs/downloads.twig', [
                'projects' => [],
                'active_nav' => 'downloads',
            ]);
        }

        $projects = Project::query()
            ->select('projects.*')
            ->join('project_users', 'project_users.project_id', '=', 'projects.id')
            ->where('project_users.user_id', $userId)
            ->with([
                'assignedSongs' => function ($query) {
                    $query->orderBy('title', 'asc');
                },
                'assignedSongs.attachments' => function ($query) {
                    $query->orderBy('original_name', 'asc');
                },
                'assignedSongs.linkResources' => function ($query) {
                    $query->where('resource_type', 'link')->orderBy('title', 'asc');
                }
            ])
            ->distinct()
            ->chronological()
            ->get();

        return $this->view->render($response, 'songs/downloads.twig', [
            'projects' => $projects,
            'active_nav' => 'downloads',
        ]);
    }
}
