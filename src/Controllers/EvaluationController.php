<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Project;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Queries\ProjectQuery;
use App\Util\TableQueryParams;
use Carbon\Carbon;

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
        $tableParams = TableQueryParams::from(
            $params,
            ['last_name', 'first_name', 'percentage', 'present_count', 'excused_count', 'unexcused_count']
        );
        $projectId = (int)($params['project_id'] ?? 0);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        $projects = $this->getAccessibleProjects($userId, $canManageUsers);
        $accessibleProjectIds = $projects->pluck('id')->map(static fn($id) => (int) $id)->all();

        if ($projectId <= 0 && $userId > 0) {
            $user = User::find($userId);
            if ($user && $user->last_project_id > 0) {
                $projectId = $user->last_project_id;
            }
        }

        $stats = [];
        $selectedProject = null;
        $totalEvents = 0;

        if ($projectId > 0) {
            if (!in_array($projectId, $accessibleProjectIds, true)) {
                return $response->withStatus(403);
            }

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

                $totalEvents = $selectedProject->events()->where('attendance_required', true)->count();

                if ($totalEvents > 0) {
                    // Get all active users, eager load their attendances for this specific project's events
                    $users = User::where('is_active', 1)
                        ->whereHas('projects', function ($projectQuery) use ($projectId) {
                            $projectQuery->where('projects.id', $projectId);
                        })
                        ->with(['voiceGroups', 'attendances' => function ($q) use ($projectId) {
                            $q->whereHas('event', function ($sq) use ($projectId) {
                                $sq->where('project_id', $projectId)
                                    ->where('attendance_required', true);
                            });
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
            'total_events' => $totalEvents,
            'table_params' => $tableParams,
        ]);
    }

    public function projectMembers(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $projectId = (int)($params['project_id'] ?? 0);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        $projects = $this->getAccessibleProjects($userId, $canManageUsers);
        $accessibleProjectIds = $projects->pluck('id')->map(static fn($id) => (int) $id)->all();

        if ($projectId <= 0 && $userId > 0) {
            $user = User::find($userId);
            if ($user && $user->last_project_id > 0) {
                $projectId = $user->last_project_id;
            }
        }

        $selectedProject = null;
        $groupedMembers = [];

        if ($projectId > 0) {
            if (!in_array($projectId, $accessibleProjectIds, true)) {
                return $response->withStatus(403);
            }

            $selectedProject = Project::find($projectId);
            if ($selectedProject) {
                $roleLevel = (int)($_SESSION['role_level'] ?? 0);
                $filterVoiceGroupIds = null;
                if ($roleLevel < 80) {
                    $filterVoiceGroupIds = $_SESSION['voice_group_ids'] ?? [];
                }

                $groupedMembers = $this->projectQuery
                    ->getProjectMembersGroupedByVoice($projectId, $filterVoiceGroupIds);

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

    public function registrations(Request $request, Response $response): Response
    {
        $includePast = (string) ($request->getQueryParams()['include_past'] ?? '') === '1';

        $query = Event::where('registration_enabled', true)->orderBy('starts_at', 'asc');
        if (!$includePast) {
            $query->where('starts_at', '>', Carbon::now());
        }
        $events = $query->get();

        $voiceGroupNames = VoiceGroup::orderBy('name')->pluck('name')->all();
        $voiceGroupNames[] = 'Ohne Stimmgruppe';

        $matrix = [];
        foreach ($events as $event) {
            $matrix[] = $this->buildRegistrationRow($event, $voiceGroupNames);
        }

        return $this->view->render($response, 'evaluations/registrations.twig', [
            'voice_group_names' => $voiceGroupNames,
            'matrix' => $matrix,
            'include_past' => $includePast,
        ]);
    }

    /**
     * Builds one matrix row for a registration-enabled event: per-voice-group
     * yes/maybe occupancy, total yes count, response rate, and (for past
     * events with attendance_required=true) the actual attendance count.
     *
     * The eligible population used here — active users, restricted to
     * project members for project-bound events — mirrors
     * RegistrationController's eligibleUsers()/eligibleStatusCounts()
     * exactly. Both the numerator (answered/yes/maybe counts) and the
     * denominator (eligible count) are derived from the SAME queried user
     * set, so they can never diverge (unlike a design that counts
     * registrations from one query and eligible users from a second,
     * differently-filtered query).
     *
     * @param string[] $voiceGroupNames
     * @return array{
     *     event: Event,
     *     cells: array<string, array{yes: int, maybe: int}>,
     *     total_yes: int,
     *     response_rate: int,
     *     attendance_comparison: ?int
     * }
     */
    private function buildRegistrationRow(Event $event, array $voiceGroupNames): array
    {
        $eligibleUsers = $event->eligibleUsersQuery()
            ->with([
                'voiceGroups',
                'eventRegistrations' => fn($q) => $q->where('event_id', (int) $event->id),
            ])
            ->get();

        $cells = array_fill_keys($voiceGroupNames, ['yes' => 0, 'maybe' => 0]);
        $totalYes = 0;
        $answered = 0;

        foreach ($eligibleUsers as $user) {
            $registration = $user->eventRegistrations->first();
            if (!$registration || !in_array($registration->status, EventRegistration::STATUSES, true)) {
                continue;
            }

            $answered++;
            $groupName = $user->voiceGroups->first()->name ?? 'Ohne Stimmgruppe';
            if (!isset($cells[$groupName])) {
                $groupName = 'Ohne Stimmgruppe';
            }

            if ($registration->status === EventRegistration::STATUS_YES) {
                $cells[$groupName]['yes']++;
                $totalYes++;
            } elseif ($registration->status === EventRegistration::STATUS_MAYBE) {
                $cells[$groupName]['maybe']++;
            }
        }

        $eligible = $eligibleUsers->count();

        $attendanceComparison = null;
        if (Carbon::parse($event->starts_at)->isPast() && (bool) $event->attendance_required) {
            $attendanceComparison = Attendance::where('event_id', (int) $event->id)
                ->where('status', 'present')
                ->count();
        }

        return [
            'event' => $event,
            'cells' => $cells,
            'total_yes' => $totalYes,
            'response_rate' => $eligible > 0 ? (int) round($answered * 100 / $eligible) : 0,
            'attendance_comparison' => $attendanceComparison,
        ];
    }

    private function getAccessibleProjects(int $userId, bool $canManageUsers)
    {
        if ($canManageUsers) {
            return Project::orderBy('name')->get();
        }

        if ($userId <= 0) {
            return Project::query()->whereRaw('1 = 0')->get();
        }

        return Project::query()
            ->select('projects.*')
            ->join('project_users', 'project_users.project_id', '=', 'projects.id')
            ->where('project_users.user_id', $userId)
            ->distinct()
            ->orderBy('projects.name')
            ->get();
    }
}
