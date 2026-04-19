<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use App\Services\MailQueueAdminService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DashboardController
{
    private Twig $view;
    private MailQueueAdminService $mailQueueAdminService;

    public function __construct(Twig $view, MailQueueAdminService $mailQueueAdminService)
    {
        $this->view = $view;
        $this->mailQueueAdminService = $mailQueueAdminService;
    }

    public function index(Request $request, Response $response): Response
    {
        $today = date('Y-m-d');
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $canViewNewsletterArea = (bool) ($_SESSION['can_manage_newsletters'] ?? false)
            || (bool) ($_SESSION['can_manage_users'] ?? false);

        $currentProject = Project::where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->orderBy('end_date', 'asc')
            ->first();

        $upcomingProject = Project::where('start_date', '>', $today)
            ->orderBy('start_date', 'asc')
            ->first();

        $latestSentNewsletter = null;
        $deadMailCount = null;

        if ($canViewNewsletterArea && $userId > 0) {
            $user = User::find($userId);

            if ($user) {
                $roles = $user->roles()->pluck('roles.name')->toArray();
                $newsletterQuery = Newsletter::query()
                    ->where('status', Newsletter::STATUS_SENT)
                    ->with(['project', 'event']);

                if (!in_array('Admin', $roles, true)) {
                    $accessibleProjectIds = $user->projects()
                        ->pluck('projects.id')
                        ->map(fn($id) => (int) $id)
                        ->all();

                    if ($accessibleProjectIds === []) {
                        $newsletterQuery = null;
                    } else {
                        $newsletterQuery->whereIn('project_id', $accessibleProjectIds);
                    }
                }

                if ($newsletterQuery !== null) {
                    $latestSentNewsletter = $newsletterQuery
                        ->orderBy('sent_at', 'desc')
                        ->orderBy('created_at', 'desc')
                        ->first();
                }
            }
        }

        if ((bool) ($_SESSION['can_manage_mail_queue'] ?? false) || (bool) ($_SESSION['can_manage_users'] ?? false)) {
            $deadMailCount = $this->mailQueueAdminService->countDeadLetters();
        }

        // Simple dashboard placeholder handling both admin and basic views
        $data = [
            'can_manage_users' => $_SESSION['can_manage_users'] ?? false,
            'can_manage_attendance' => $_SESSION['can_manage_attendance'] ?? false,
            'role_level' => $_SESSION['role_level'] ?? 0,
            'voice_group_ids' => $_SESSION['voice_group_ids'] ?? [],
            'current_project' => $currentProject,
            'upcoming_project' => $upcomingProject,
            'latest_sent_newsletter' => $latestSentNewsletter,
            'dead_mail_count' => $deadMailCount,
        ];

        return $this->view->render($response, 'dashboard/index.twig', $data);
    }
}
