<?php

declare(strict_types=1);

namespace App\Controllers;

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
        // Simple dashboard placeholder handling both admin and basic views
        $data = [
            'can_manage_users' => $_SESSION['can_manage_users'] ?? false,
            'can_manage_attendance' => $_SESSION['can_manage_attendance'] ?? false,
            'role_level' => $_SESSION['role_level'] ?? 0,
            'voice_group_ids' => $_SESSION['voice_group_ids'] ?? []
        ];

        return $this->view->render($response, 'dashboard/index.twig', $data);
    }
}
