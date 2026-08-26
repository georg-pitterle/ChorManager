<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\EventRegistration;
use App\Models\Project;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use App\Services\EventAudienceService;
use App\Services\NameFormatterService;
use App\Util\TableQueryParams;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class EvaluationController
{
    private Twig $view;
    private ProjectQuery $projectQuery;
    private NameFormatterService $nameFormatter;
    private ProjectMemberPolicy $memberPolicy;
    private LoggerInterface $logger;

    public function __construct(
        Twig $view,
        ProjectQuery $projectQuery,
        NameFormatterService $nameFormatter,
        ?ProjectMemberPolicy $memberPolicy = null,
        ?LoggerInterface $logger = null
    ) {
        $this->view = $view;
        $this->projectQuery = $projectQuery;
        $this->nameFormatter = $nameFormatter;
        $this->memberPolicy = $memberPolicy ?? new ProjectMemberPolicy();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Abweisung mit sichtbarer Begründung: Ein 403 ohne Körper liefert eine leere
     * Seite, auf der niemand erkennt, woran es lag.
     */
    private function denyProjectAccess(Response $response, int $projectId): Response
    {
        $this->logger->info('Access denied.', [
            'event' => 'authz.denied',
            'reason' => 'evaluation.project_forbidden',
            'project_id' => $projectId,
        ]);

        return $this->view->render($response->withStatus(403), 'errors/403.twig', [
            'error' => 'Du hast keinen Zugriff auf die Auswertungen dieses Projekts.',
        ]);
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $tableParams = TableQueryParams::from(
            $params,
            array_merge(
                $this->nameFormatter->orderColumns(),
                ['percentage', 'present_count', 'excused_count', 'unexcused_count']
            )
        );
        $projectId = (int)($params['project_id'] ?? 0);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $projects = $this->projectQuery->getAccessibleProjects($userId, $this->canSeeAllProjects());
        $accessibleProjectIds = $projects->pluck('id')->map(static fn($id) => (int) $id)->all();

        if ($projectId <= 0) {
            $projectId = $this->resolveDefaultProjectId($accessibleProjectIds, $userId);
        }

        $stats = [];
        $selectedProject = null;
        $totalEvents = 0;

        if ($projectId > 0) {
            if (!in_array($projectId, $accessibleProjectIds, true)) {
                return $this->denyProjectAccess($response, $projectId);
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

                $totalEvents = $selectedProject->events()
                    ->where('attendance_required', true)
                    ->where('starts_at', '<=', Carbon::now())
                    ->count();

                if ($totalEvents > 0) {
                    // Get all active users, eager load their attendances for this specific project's events
                    $userQuery = User::where('is_active', 1)
                        ->whereHas('projects', function ($projectQuery) use ($projectId) {
                            $projectQuery->where('projects.id', $projectId);
                        })
                        ->with(['voiceGroups', 'attendances' => function ($q) use ($projectId) {
                            $q->whereHas('event', function ($sq) use ($projectId) {
                                $sq->where('attendance_required', true)
                                    ->where('starts_at', '<=', Carbon::now())
                                    ->whereHas('audienceSources', function ($asq) use ($projectId) {
                                        $asq->where('source_type', EventAudienceSource::TYPE_PROJECT_MEMBERS)
                                            ->where('reference_id', $projectId);
                                    });
                            });
                        }]);

                    foreach ($this->nameFormatter->orderColumns() as $column) {
                        $userQuery->orderBy($column);
                    }

                    $users = $userQuery->get();

                    foreach ($users as $user) {
                        $vgName = $user->voiceGroups->pluck('name')->implode(', ');

                        $present = $user->attendances->where('status', Attendance::STATUS_PRESENT)->count();
                        $excused = $user->attendances->where('status', Attendance::STATUS_EXCUSED)->count();
                        $unexcused = $user->attendances->where('status', Attendance::STATUS_UNEXCUSED)->count();
                        // Offene Einträge tragen nur eine Notiz und sind keine Erfassung.
                        $totalRecorded = $user->attendances
                            ->whereIn('status', Attendance::RECORDED_STATUSES)
                            ->count();

                        $percentage = $totalEvents > 0 ? round(($present / $totalEvents) * 100, 1) : 0;

                        $stats[] = [
                            'user_id' => (int) $user->id,
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
        $projects = $this->projectQuery->getAccessibleProjects($userId, $this->canSeeAllProjects());
        $accessibleProjectIds = $projects->pluck('id')->map(static fn($id) => (int) $id)->all();

        if ($projectId <= 0) {
            $projectId = $this->resolveDefaultProjectId($accessibleProjectIds, $userId);
        }

        $selectedProject = null;
        $groupedMembers = [];

        if ($projectId > 0) {
            if (!in_array($projectId, $accessibleProjectIds, true)) {
                return $this->denyProjectAccess($response, $projectId);
            }

            $selectedProject = Project::find($projectId);
            if ($selectedProject) {
                // Die Besetzung eines Projekts ist fuer alle Mitglieder des Projekts einsehbar;
                // ein Stimmgruppen-Filter haette hier frueher nur am Hierarchie-Level gehangen.
                $groupedMembers = $this->projectQuery
                    ->getProjectMembersGroupedByVoice($projectId, null);

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
            'grouped_members' => $groupedMembers,
            // Der Sprung in die Mitgliederpflege folgt derselben Policy wie die Zielseite:
            // die Auswertung ist breiter sichtbar als die Verwaltung.
            'can_manage_members' => $projectId > 0 && $this->memberPolicy->canViewMembers($projectId),
        ]);
    }

    public function registrations(Request $request, Response $response): Response
    {
        $includePast = (string) ($request->getQueryParams()['include_past'] ?? '') === '1';

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $query = ((bool) ($_SESSION['can_manage_attendance_all'] ?? false)
            ? Event::query()
            : (new EventAudienceService())->visibleEventsQuery($userId))
            ->where('registration_enabled', true)
            ->orderBy('starts_at', 'asc');
        if (!$includePast) {
            $query->where('starts_at', '>', Carbon::now());
        }
        $events = $query->get();

        $voiceGroupNames = VoiceGroup::orderBy('id')->pluck('name')->all();
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

    /**
     * Vorauswahl ohne project_id-Parameter: zuerst das aktuell laufende Projekt,
     * danach die zuletzt gewaehlte Auswahl des Nutzers. Beide Kandidaten muessen
     * in den fuer den Nutzer zugaenglichen Projekten liegen.
     *
     * @param int[] $accessibleProjectIds
     */
    private function resolveDefaultProjectId(array $accessibleProjectIds, int $userId): int
    {
        $currentProjectId = $this->projectQuery->findCurrentProjectId($accessibleProjectIds);
        if ($currentProjectId > 0) {
            return $currentProjectId;
        }

        if ($userId > 0) {
            $user = User::find($userId);
            $lastProjectId = $user ? (int) $user->last_project_id : 0;
            if ($lastProjectId > 0 && in_array($lastProjectId, $accessibleProjectIds, true)) {
                return $lastProjectId;
            }
        }

        return 0;
    }

    /**
     * Auswertungen sind Anwesenheits-/Anmeldedaten: projektuebergreifend sieht sie, wer
     * Anwesenheit fuer alle Mitglieder verwalten darf - nicht, wer Mitglieder verwaltet.
     */
    private function canSeeAllProjects(): bool
    {
        return (bool) ($_SESSION['can_manage_attendance_all'] ?? false);
    }
}
