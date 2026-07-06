<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DashboardController
{
    private Twig $view;
    private \App\Services\MailQueueAdminService $mailQueueAdminService;
    private array $settings;

    public function __construct(
        Twig $view,
        \App\Services\MailQueueAdminService $mailQueueAdminService,
        array $settings = []
    ) {
        $this->view = $view;
        $this->mailQueueAdminService = $mailQueueAdminService;
        $this->settings = $settings;
    }

    public function index(Request $request, Response $response): Response
    {
        $today = date('Y-m-d');
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        $canManageTasks = (bool) ($_SESSION['can_manage_tasks'] ?? false);
        $tasksModuleEnabled = (bool) ($this->settings['modules']['tasks'] ?? false);
        $newsletterModuleEnabled = (bool) ($this->settings['modules']['newsletter'] ?? false);
        $canViewNewsletterArea = $newsletterModuleEnabled
            && ((bool) ($_SESSION['can_manage_newsletters'] ?? false) || $canManageUsers);

        $currentProject = null;
        $upcomingProject = null;

        if ($tasksModuleEnabled && $canManageTasks) {
            $currentProject = Project::where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->orderBy('end_date', 'asc')
                ->first();

            $upcomingProject = Project::where('start_date', '>', $today)
                ->orderBy('start_date', 'asc')
                ->first();
        }

        $latestSentNewsletter = null;
        $deadMailCount = null;

        if ($canViewNewsletterArea && $userId > 0) {
            $newsletterQuery = Newsletter::query()
                ->where('status', Newsletter::STATUS_SENT)
                ->with(['project', 'recipientSources']);

            if (!$canManageUsers) {
                $user = User::find($userId);
                $accessibleProjectIds = $user
                    ? $user->projects()
                        ->pluck('projects.id')
                        ->map(fn($id) => (int) $id)
                        ->all()
                    : [];

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

        if ((bool) ($_SESSION['can_manage_mail_queue'] ?? false) || $canManageUsers) {
            $deadMailCount = $this->mailQueueAdminService->countDeadLetters();
        }

        $data = [
            'current_project' => $currentProject,
            'upcoming_project' => $upcomingProject,
            'latest_sent_newsletter' => $latestSentNewsletter,
            'dead_mail_count' => $deadMailCount,
        ];

        return $this->view->render($response, 'dashboard/index.twig', $data);
    }
}
