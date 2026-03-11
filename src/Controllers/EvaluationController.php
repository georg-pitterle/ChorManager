<?php
declare(strict_types = 1)
;

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Project;
use App\Models\User;
use App\Queries\ProjectQuery;

class EvaluationController
{
    private Twig $view;
    private ProjectQuery $projectQuery;

    public function __construct(Twig $view, ProjectQuery $projectQuery)
    {
        $this->view = $view;
        $this->projectQuery = $projectQuery;
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $projectId = (int)($params['project_id'] ?? 0);
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($projectId <= 0 && $userId > 0) {
            $user = User::find($userId);
            if ($user && $user->last_project_id > 0) {
                $projectId = $user->last_project_id;
            }
        }

        $projects = Project::orderBy('name')->get();
        $stats = [];
        $selectedProject = null;
        $totalEvents = 0;

        if ($projectId > 0) {
            $selectedProject = Project::find($projectId);

            if ($selectedProject) {
                if ($userId > 0) {
                    $user = User::find($userId);
                    if ($user) {
                        $user->last_project_id = $projectId;
                        $user->save();
                    }
                    $_SESSION['last_project_id'] = $projectId;
                }

                $totalEvents = $selectedProject->events()->count();

                if ($totalEvents > 0) {
                    // Get all active users, eager load their attendances for this specific project's events
                    $users = User::where('is_active', 1)
                        ->with(['voiceGroups', 'attendances' => function ($q) use ($projectId) {
                        $q->whereHas('event', function ($sq) use ($projectId) {
                                $sq->where('project_id', $projectId);
                            }
                            );
                        }])
                        ->orderBy('last_name')
                        ->orderBy('first_name')
                        ->get();

                    foreach ($users as $user) {
                        $vgName = $user->voiceGroups->pluck('name')->implode(', ');

                        $present = $user->attendances->where('status', 'present')->count();
                        $excused = $user->attendances->where('status', 'excused')->count();
                        $unexcused = $user->attendances->where('status', 'unexcused')->count();
                        $totalRecorded = $user->attendances->count();

                        $percentage = $totalEvents > 0 ? round(($present / $totalEvents) * 100, 1) : 0;

                        $stats[] = [
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'voice_group_name' => $vgName,
                            'present_count' => $present,
                            'excused_count' => $excused,
                            'unexcused_count' => $unexcused,
                            'total_recorded' => $totalRecorded,
                            'percentage' => $percentage
                        ];
                    }
                }
            }
        }

        return $this->view->render($response, 'evaluations/index.twig', [
            'projects' => $projects,
            'selected_project' => $selectedProject,
            'stats' => $stats,
            'total_events' => $totalEvents
        ]);
    }

    public function projectMembers(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $projectId = (int)($params['project_id'] ?? 0);
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($projectId <= 0 && $userId > 0) {
            $user = User::find($userId);
            if ($user && $user->last_project_id > 0) {
                $projectId = $user->last_project_id;
            }
        }

        $projects = Project::orderBy('name')->get();
        $selectedProject = null;
        $groupedMembers = [];

        if ($projectId > 0) {
            $selectedProject = Project::find($projectId);
            if ($selectedProject) {
                $roleLevel = (int)($_SESSION['role_level'] ?? 0);
                $filterVoiceGroupIds = null;
                if ($roleLevel < 80) {
                    $filterVoiceGroupIds = $_SESSION['voice_group_ids'] ?? [];
                }

                $groupedMembers = $this->projectQuery->getProjectMembersGroupedByVoice($projectId, $filterVoiceGroupIds);

                if ($userId > 0) {
                    $user = User::find($userId);
                    if ($user) {
                        $user->last_project_id = $projectId;
                        $user->save();
                    }
                    $_SESSION['last_project_id'] = $projectId;
                }
            }
        }

        return $this->view->render($response, 'evaluations/project_members.twig', [
            'projects' => $projects,
            'selected_project' => $selectedProject,
            'grouped_members' => $groupedMembers
        ]);
    }
}
