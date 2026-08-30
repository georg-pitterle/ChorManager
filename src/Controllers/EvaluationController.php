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
use Illuminate\Database\Capsule\Manager as Capsule;
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
                ['percentage', 'present_count', 'excused_count', 'unexcused_count', 'total_recorded']
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

                        // Bezugsgroesse ist jeder stattgefundene Pflichttermin, nicht nur der
                        // erfasste: Eine nicht gefuehrte Liste ist eine fehlende Angabe, keine
                        // Abwesenheit - sie darf die Quote der uebrigen aber auch nicht
                        // schoenrechnen. Wie viele Termine tatsaechlich erfasst wurden, steht
                        // daneben in der Spalte "Erfasst" und macht den Unterschied sichtbar.
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
                    ->getProjectMembersGroupedByVoice($projectId);

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
        $audienceService = new EventAudienceService();
        $query = ((bool) ($_SESSION['can_manage_attendance_all'] ?? false)
            ? Event::query()
            : $audienceService->visibleEventsQuery($userId))
            ->where('registration_enabled', true)
            ->orderBy('starts_at', 'asc');
        if (!$includePast) {
            $query->where('starts_at', '>', Carbon::now());
        }
        // audienceSources vorladen: Ohne das holte die Zielgruppen-Auflösung je
        // Termin eine eigene Abfrage, noch vor allem Weiteren.
        $events = $query->with('audienceSources')->get();

        $voiceGroupNames = VoiceGroup::orderBy('id')->pluck('name')->all();
        $voiceGroupNames[] = 'Ohne Stimmgruppe';

        // Alles, was je Termin gebraucht wird, wird hier einmal für alle Termine
        // beschafft: berechtigte Mitglieder, deren Stimmgruppe, die Anmeldungen und
        // die Anwesenheiten. Vorher stellte jede Zeile ihre eigenen Abfragen - bei
        // einer Probenserie also dutzendfach dieselbe Arbeit.
        $eligibleByEvent = $audienceService->eligibleUserIdsForEvents($events);
        $voiceGroupByUser = $this->voiceGroupNamesByUser($eligibleByEvent);
        $registrationsByEvent = $this->registrationsByEvent($events);
        $attendanceByEvent = $this->presentCountsByEvent($events);

        $matrix = [];
        foreach ($events as $event) {
            $matrix[] = $this->buildRegistrationRow(
                $event,
                $voiceGroupNames,
                $eligibleByEvent[(int) $event->id] ?? [],
                $voiceGroupByUser,
                $registrationsByEvent[(int) $event->id] ?? [],
                $attendanceByEvent[(int) $event->id] ?? null
            );
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
     * Zähler und Bezugsgröße stammen weiterhin aus derselben Menge: Gezählt wird
     * ausschließlich über $eligibleUserIds, und der Nenner ist die Länge genau
     * dieser Liste. Sie kommt jetzt von aussen, weil sie für alle Termine
     * gemeinsam bestimmt wird - an der Invariante ändert das nichts, die Menge
     * ist dieselbe wie zuvor.
     *
     * @param string[] $voiceGroupNames
     * @param list<int> $eligibleUserIds
     * @param array<int, string> $voiceGroupByUser
     * @param array<int, string> $statusByUser
     * @return array{
     *     event: Event,
     *     cells: array<string, array{yes: int, maybe: int}>,
     *     total_yes: int,
     *     response_rate: int,
     *     attendance_comparison: ?int
     * }
     */
    private function buildRegistrationRow(
        Event $event,
        array $voiceGroupNames,
        array $eligibleUserIds,
        array $voiceGroupByUser,
        array $statusByUser,
        ?int $attendanceComparison
    ): array {
        $cells = array_fill_keys($voiceGroupNames, ['yes' => 0, 'maybe' => 0]);
        $totalYes = 0;
        $answered = 0;

        foreach ($eligibleUserIds as $eligibleUserId) {
            $status = $statusByUser[$eligibleUserId] ?? null;
            if ($status === null || !in_array($status, EventRegistration::STATUSES, true)) {
                continue;
            }

            $answered++;
            $groupName = $voiceGroupByUser[$eligibleUserId] ?? 'Ohne Stimmgruppe';
            if (!isset($cells[$groupName])) {
                $groupName = 'Ohne Stimmgruppe';
            }

            if ($status === EventRegistration::STATUS_YES) {
                $cells[$groupName]['yes']++;
                $totalYes++;
            } elseif ($status === EventRegistration::STATUS_MAYBE) {
                $cells[$groupName]['maybe']++;
            }
        }

        $eligible = count($eligibleUserIds);

        return [
            'event' => $event,
            'cells' => $cells,
            'total_yes' => $totalYes,
            'response_rate' => $eligible > 0 ? (int) round($answered * 100 / $eligible) : 0,
            'attendance_comparison' => $attendanceComparison,
        ];
    }

    /**
     * Stimmgruppe je Mitglied, in einer Abfrage für alle beteiligten Mitglieder.
     *
     * Maßgeblich ist wie bisher die erste Stimmgruppe eines Mitglieds; die
     * Reihenfolge über die Kennung entspricht der bisherigen Beziehungsabfrage.
     *
     * @param array<int, list<int>> $eligibleByEvent
     * @return array<int, string>
     */
    private function voiceGroupNamesByUser(array $eligibleByEvent): array
    {
        $userIds = [];
        foreach ($eligibleByEvent as $ids) {
            foreach ($ids as $id) {
                $userIds[$id] = true;
            }
        }

        if ($userIds === []) {
            return [];
        }

        $names = [];
        $rows = Capsule::table('user_voice_groups')
            ->join('voice_groups', 'voice_groups.id', '=', 'user_voice_groups.voice_group_id')
            ->whereIn('user_voice_groups.user_id', array_keys($userIds))
            ->orderBy('voice_groups.id')
            ->get(['user_voice_groups.user_id', 'voice_groups.name']);

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            if (!isset($names[$userId])) {
                $names[$userId] = (string) $row->name;
            }
        }

        return $names;
    }

    /**
     * Anmeldestatus je Termin und Mitglied, in einer Abfrage für alle Termine.
     *
     * @param iterable<Event> $events
     * @return array<int, array<int, string>>
     */
    private function registrationsByEvent(iterable $events): array
    {
        $eventIds = [];
        foreach ($events as $event) {
            $eventIds[] = (int) $event->id;
        }

        if ($eventIds === []) {
            return [];
        }

        $byEvent = [];
        $registrations = EventRegistration::whereIn('event_id', $eventIds)
            ->get(['event_id', 'user_id', 'status']);

        foreach ($registrations as $registration) {
            $byEvent[(int) $registration->event_id][(int) $registration->user_id] = (string) $registration->status;
        }

        return $byEvent;
    }

    /**
     * Zahl der Anwesenden je Termin, in einer Abfrage - und nur für die Termine,
     * bei denen die Spalte überhaupt angezeigt wird: vergangen und mit geführter
     * Anwesenheitsliste.
     *
     * @param iterable<Event> $events
     * @return array<int, int>
     */
    private function presentCountsByEvent(iterable $events): array
    {
        $eventIds = [];
        foreach ($events as $event) {
            if (Carbon::parse($event->starts_at)->isPast() && (bool) $event->attendance_required) {
                $eventIds[] = (int) $event->id;
            }
        }

        if ($eventIds === []) {
            return [];
        }

        $counts = [];
        $rows = Attendance::whereIn('event_id', $eventIds)
            ->where('status', 'present')
            ->groupBy('event_id')
            ->get([Capsule::raw('event_id'), Capsule::raw('COUNT(*) as present_count')]);

        foreach ($rows as $row) {
            $counts[(int) $row->event_id] = (int) $row->present_count;
        }

        // Termine ohne Anwesende bekommen ausdrücklich 0 - ein fehlender Eintrag
        // würde in der Spalte sonst als "keine Liste geführt" erscheinen.
        foreach ($eventIds as $eventId) {
            $counts[$eventId] ??= 0;
        }

        return $counts;
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
