<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Project;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DashboardController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $today = date('Y-m-d');
        $currentProject = Project::where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->orderBy('end_date', 'asc')
            ->first();

        $upcomingProject = Project::where('start_date', '>', $today)
            ->orderBy('start_date', 'asc')
            ->first();

        // Simple dashboard placeholder handling both admin and basic views
        $data = [
            'can_manage_users' => $_SESSION['can_manage_users'] ?? false,
            'can_manage_attendance' => $_SESSION['can_manage_attendance'] ?? false,
            'role_level' => $_SESSION['role_level'] ?? 0,
            'voice_group_ids' => $_SESSION['voice_group_ids'] ?? [],
            'current_project' => $currentProject,
            'upcoming_project' => $upcomingProject,
        ];

        return $this->view->render($response, 'dashboard/index.twig', $data);
    }
}
