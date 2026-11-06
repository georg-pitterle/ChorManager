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
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use App\Services\AttendanceScopeService;
use App\Services\AttendanceStanding;
use App\Services\EventAudienceService;
use App\Services\NameFormatterService;
use App\Util\InputValidator;
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
        $this->memberPolicy = $memberPolicy ?? new ProjectMemberPolicy($_SESSION);
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

    /**
     * Merkt das gewählte Projekt für den nächsten Besuch.
     *
     * Geschrieben wird nur, wenn sich der Wert tatsächlich ändert. Beide
     * Auswertungsseiten sind GET-Aufrufe, und jeder einzelne setzte bisher ein
     * UPDATE auf `users` ab - auch der zehnte Aufruf derselben Seite mit
     * derselben Auswahl. Nebenbei verschob ein fremdes
     * `<img src="/evaluations?project_id=5">` die gemerkte Auswahl der lesenden
     * Person. Ganz ausschließen lässt sich das hier nicht, ohne das Merken an
     * ein POST zu binden; es greift jetzt aber nur noch im Fall "diese Person
     * hatte Projekt 5 noch nie gewählt", und ein zugängliches Projekt muss es
     * ohnehin sein - die Aufrufer prüfen das vor dem Aufruf.
     *
     * Stand zuvor zweimal wortgleich in index() und projectMembers().
     */
    private function rememberSelectedProject(int $userId, int $projectId): void
    {
        if ($userId <= 0) {
            return;
        }

        $_SESSION['last_project_id'] = $projectId;

        $user = User::find($userId);
        if ($user === null || (int) $user->last_project_id === $projectId) {
            return;
        }

        $user->last_project_id = $projectId;
        $user->save();
    }

    /**
     * Ab hier gilt eine Quote als niedrig. Die Vorlage färbt mit derselben
     * Schwelle, die Stimmgruppen-Zusammenfassung zählt mit ihr.
     */
    private const QUOTA_LOW = 50;

    /** Ab hier gilt eine Quote als gut. */
    private const QUOTA_GOOD = 75;

    private const NO_VOICE_GROUP = 'Ohne Stimmgruppe';

    /**
     * Ab dieser Anwesenheit steht man namentlich bei "Immer dabei". Bewusst über
     * QUOTA_GOOD: Die Liste soll eine Auszeichnung sein, kein Normalzustand.
     */
    private const ALWAYS_THERE_THRESHOLD = 90;

    /**
     * Erst ab so vielen stattgefundenen Pflichtterminen gibt es "Immer dabei" und
     * einen Vergleich mit dem Vorprojekt - nach einem Termin stünden sonst alle
     * Anwesenden bei 100 %.
     */
    private const MIN_EVENTS_FOR_RECOGNITION = 3;

    /** So viele frühere Projekte werden höchstens nach einem Vergleichswert durchsucht. */
    private const PREVIOUS_PROJECT_CANDIDATES = 3;

    /**
     * Die Seite ist für jedes Mitglied offen, zeigt aber nicht jedem dasselbe.
     *
     * Was andere Personen betrifft, sieht ein Mitglied nur im Positiven: die
     * Stimmgruppen im Vergleich und die Liste "Immer dabei". Der eigene Stand
     * bleibt privat. Die namentliche Tabelle mit Unentschuldigt-Zählern und die
     * Zusammenfassung mit "unter X %" bekommt nur, wer Anwesenheit verwaltet, und
     * zwar im Umfang seines Rechts. Ausgeblendet wird hier, nicht in der Vorlage:
     * Was ein Mitglied nicht sehen soll, steht gar nicht erst in den Daten.
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
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
        $emptyReason = null;

        if ($projectId > 0) {
            if (!in_array($projectId, $accessibleProjectIds, true)) {
                return $this->denyProjectAccess($response, $projectId);
            }

            $selectedProject = Project::find($projectId);

            if ($selectedProject) {
                $this->rememberSelectedProject($userId, $projectId);

                ['total_events' => $totalEvents, 'stats' => $stats] = $this->collectStats($selectedProject);

                // Der Leerzustand nennt den Grund, statt zwei Möglichkeiten zu raten.
                if ($totalEvents === 0) {
                    $emptyReason = 'no_events';
                } elseif ($stats === []) {
                    $emptyReason = 'no_members';
                }
            }
        }

        $scope = new AttendanceScopeService($_SESSION);
        $canViewDetails = $scope->canManageOthers();
        $detailStats = $canViewDetails ? $this->limitToManageable($stats, $scope) : [];
        $ownStat = $this->statForUser($stats, $userId);
        $ownVoiceGroup = $ownStat !== null && $ownStat['voice_group_rank'] !== PHP_INT_MAX
            ? (string) $ownStat['primary_voice_group']
            : null;
        $recognitionReady = $totalEvents >= self::MIN_EVENTS_FOR_RECOGNITION;

        return $this->view->render($response, 'evaluations/index.twig', [
            'projects' => $projects,
            'selected_project' => $selectedProject,
            'stats' => $detailStats,
            'total_events' => $totalEvents,
            'voice_group_summary' => $this->summarizeByVoiceGroup($detailStats),
            'can_view_details' => $canViewDetails,
            'details_scope' => $canViewDetails ? ($this->canSeeAllProjects() ? 'all' : 'own_voice_group') : null,
            'own_standing' => $ownStat !== null && $selectedProject !== null
                ? $this->buildOwnStanding($ownStat, $ownVoiceGroup, $selectedProject, $userId)
                : null,
            'voice_group_race' => AttendanceStanding::voiceGroupRace(
                $this->summarizeByVoiceGroup($stats),
                $ownVoiceGroup,
                self::NO_VOICE_GROUP
            ),
            'always_there' => $recognitionReady ? $this->alwaysThere($stats, $userId) : [],
            'always_there_ready' => $recognitionReady,
            'always_there_threshold' => self::ALWAYS_THERE_THRESHOLD,
            'min_events_for_recognition' => self::MIN_EVENTS_FOR_RECOGNITION,
            'empty_reason' => $emptyReason,
            'quota_low' => self::QUOTA_LOW,
            'quota_good' => self::QUOTA_GOOD,
            'can_manage_members' => $selectedProject !== null
                && $this->memberPolicy->canViewMembers((int) $selectedProject->id),
        ]);
    }

    /**
     * Zählungen und Quote je aktivem Projektmitglied über die stattgefundenen
     * Pflichttermine, die für die Person gelten.
     *
     * @return array{total_events: int, stats: list<array<string, mixed>>}
     */
    private function collectStats(Project $project, ?int $onlyUserId = null): array
    {
        $projectId = (int) $project->id;
        $projectEvents = $project->eventsQuery()
            ->where('attendance_required', true)
            ->where('starts_at', '<=', Carbon::now())
            ->with('audienceFilters.conditions')
            ->get()
            // Jüngster zuerst: Die Serie zählt vom letzten Termin rückwärts.
            ->sortByDesc(static fn (Event $event): string => (string) $event->starts_at)
            ->values();
        $totalEvents = $projectEvents->count();

        if ($totalEvents === 0) {
            return ['total_events' => 0, 'stats' => []];
        }

        // Ein Termin "Sopran UND Projekt" gehört zum Projekt, gilt aber nicht für
        // die Alt-Stimmen darin. Nenner und Zählung laufen deshalb je Mitglied
        // über die Termine, deren Zielgruppe es trifft.
        $eventIdsByUser = [];
        foreach ((new EventAudienceService())->eligibleUserIdsForEvents($projectEvents) as $eventId => $memberIds) {
            foreach ($memberIds as $memberId) {
                $eventIdsByUser[$memberId][$eventId] = true;
            }
        }

        // Get all active users, eager load their attendances for this specific project's events
        $userQuery = User::where('is_active', 1)
            ->whereHas('projects', function ($projectQuery) use ($projectId) {
                $projectQuery->where('projects.id', $projectId);
            })
            ->with(['voiceGroups', 'attendances' => function ($q) use ($projectId) {
                $q->whereHas('event', function ($sq) use ($projectId) {
                    $sq->where('attendance_required', true)
                        ->where('starts_at', '<=', Carbon::now())
                        ->forProject((int) $projectId);
                });
            }]);
        if ($onlyUserId !== null) {
            $userQuery->where('users.id', $onlyUserId);
        }

        $this->nameFormatter->applyNameOrder($userQuery);

        $stats = [];
        foreach ($userQuery->get() as $user) {
            $vgName = $user->voiceGroups->pluck('name')->implode(', ');
            // Die Relation sortiert nach voice_groups.id, und das ist die
            // Chor-Reihenfolge (Sopran, Alt, Tenor, Bass). Die erste Gruppe
            // ordnet die Person ein, wie in der Besetzung.
            $primaryVoiceGroup = $user->voiceGroups->first();
            $ownEvents = $eventIdsByUser[(int) $user->id] ?? [];
            $ownTotal = count($ownEvents);
            $attendances = $user->attendances
                ->filter(static fn ($attendance): bool => isset($ownEvents[(int) $attendance->event_id]));

            $present = $attendances->where('status', Attendance::STATUS_PRESENT)->count();
            $excused = $attendances->where('status', Attendance::STATUS_EXCUSED)->count();
            $unexcused = $attendances->where('status', Attendance::STATUS_UNEXCUSED)->count();
            // Offene Einträge tragen nur eine Notiz und sind keine Erfassung.
            $totalRecorded = $attendances
                ->whereIn('status', Attendance::RECORDED_STATUSES)
                ->count();

            $statusByEvent = $attendances->pluck('status', 'event_id')->all();
            $statusesNewestFirst = [];
            foreach ($projectEvents as $event) {
                if (isset($ownEvents[(int) $event->id])) {
                    $statusesNewestFirst[] = $statusByEvent[(int) $event->id] ?? null;
                }
            }

            // Bezugsgröße ist jeder stattgefundene Pflichttermin, nicht nur der
            // erfasste: Eine nicht geführte Liste ist eine fehlende Angabe, keine
            // Abwesenheit - sie darf die Quote der übrigen aber auch nicht
            // schönrechnen. Wie viele Termine tatsächlich erfasst wurden, steht
            // daneben in der Spalte "Erfasst" und macht den Unterschied sichtbar.
            // Gilt für jemanden kein einziger Termin, gibt es keine Quote:
            // 0 % behauptete ein Fehlen, das nie stattfand.
            $percentage = $ownTotal > 0 ? round(($present / $ownTotal) * 100, 1) : null;

            $stats[] = [
                'user_id' => (int) $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'voice_group_name' => $vgName,
                'primary_voice_group' => $primaryVoiceGroup?->name ?? self::NO_VOICE_GROUP,
                'voice_group_rank' => $primaryVoiceGroup !== null ? (int) $primaryVoiceGroup->id : PHP_INT_MAX,
                'present_count' => $present,
                'excused_count' => $excused,
                'unexcused_count' => $unexcused,
                'total_recorded' => $totalRecorded,
                'total_events' => $ownTotal,
                'percentage' => $percentage,
                // Verlässlich ist, wer da war oder sich entschuldigt hat.
                'reliability' => $ownTotal > 0 ? round((($present + $excused) / $ownTotal) * 100, 1) : null,
                'streak' => AttendanceStanding::streak($statusesNewestFirst),
            ];
        }

        return ['total_events' => $totalEvents, 'stats' => $stats];
    }

    /**
     * Die namentliche Tabelle im Umfang des Verwaltungsrechts: alle Mitglieder
     * oder nur die der eigenen Stimmgruppen - dieselbe Menge, für die die Person
     * auch Anwesenheit eintragen darf.
     *
     * @param list<array<string, mixed>> $stats
     * @return list<array<string, mixed>>
     */
    private function limitToManageable(array $stats, AttendanceScopeService $scope): array
    {
        $manageable = array_flip($scope->getManageableUserIds());

        return array_values(array_filter(
            $stats,
            static fn (array $stat): bool => isset($manageable[(int) $stat['user_id']])
        ));
    }

    /**
     * @param list<array<string, mixed>> $stats
     * @return array<string, mixed>|null
     */
    private function statForUser(array $stats, int $userId): ?array
    {
        foreach ($stats as $stat) {
            if ((int) $stat['user_id'] === $userId) {
                return $stat;
            }
        }

        return null;
    }

    /**
     * Der eigene Stand: nur für die Person selbst, deshalb auch mit dem Abstand
     * zur Schwelle und dem Vergleich zum eigenen Vorprojekt.
     *
     * @param array<string, mixed> $ownStat
     * @return array<string, mixed>
     */
    private function buildOwnStanding(array $ownStat, ?string $voiceGroup, Project $project, int $userId): array
    {
        $present = (int) $ownStat['present_count'];
        $total = (int) $ownStat['total_events'];
        $toThreshold = AttendanceStanding::eventsToThreshold(
            $present,
            $total,
            self::ALWAYS_THERE_THRESHOLD,
            self::MIN_EVENTS_FOR_RECOGNITION
        );

        $previous = $ownStat['percentage'] !== null ? $this->previousProjectQuota($project, $userId) : null;

        return [
            'present_count' => $present,
            'excused_count' => (int) $ownStat['excused_count'],
            'total_events' => $total,
            'percentage' => $ownStat['percentage'],
            'reliability' => $ownStat['reliability'],
            'streak' => (int) $ownStat['streak'],
            'voice_group' => $voiceGroup,
            'is_always_there' => $total > 0 && $toThreshold === 0,
            'events_to_always_there' => $toThreshold,
            'previous' => $previous === null ? null : [
                'project_name' => $previous['project_name'],
                'percentage' => $previous['percentage'],
                'delta' => round((float) $ownStat['percentage'] - $previous['percentage'], 1),
            ],
        ];
    }

    /**
     * Quote der Person im jüngsten früheren Projekt, in dem für sie mindestens
     * MIN_EVENTS_FOR_RECOGNITION Pflichttermine stattfanden. "Früher" heißt:
     * früheres Startdatum. Ohne Startdatum gibt es keine Reihenfolge und damit
     * keinen Vergleich.
     *
     * @return array{project_name: string, percentage: float}|null
     */
    private function previousProjectQuota(Project $project, int $userId): ?array
    {
        if ($project->start_date === null) {
            return null;
        }

        $candidates = Project::whereHas('users', static function ($query) use ($userId): void {
            $query->where('users.id', $userId);
        })
            ->where('projects.id', '!=', (int) $project->id)
            ->whereNotNull('start_date')
            ->where('start_date', '<', $project->start_date->format('Y-m-d'))
            ->orderBy('start_date', 'desc')
            ->orderBy('projects.id', 'desc')
            ->limit(self::PREVIOUS_PROJECT_CANDIDATES)
            ->get();

        foreach ($candidates as $candidate) {
            $stat = $this->statForUser($this->collectStats($candidate, $userId)['stats'], $userId);
            if ($stat !== null && $stat['total_events'] >= self::MIN_EVENTS_FOR_RECOGNITION) {
                return ['project_name' => (string) $candidate->name, 'percentage' => (float) $stat['percentage']];
            }
        }

        return null;
    }

    /**
     * "Immer dabei": wer bei mindestens ALWAYS_THERE_THRESHOLD % liegt und für wen
     * genug Termine galten. Nur Namen - ohne Quote und ohne Rang, in Namensordnung,
     * wie die Abfrage sie liefert. Wer darunter liegt, taucht nirgends auf.
     *
     * @param list<array<string, mixed>> $stats
     * @return list<array{user_id: int, first_name: string, last_name: string, is_self: bool}>
     */
    private function alwaysThere(array $stats, int $userId): array
    {
        $names = [];
        foreach ($stats as $stat) {
            $qualifies = $stat['percentage'] !== null
                && $stat['total_events'] >= self::MIN_EVENTS_FOR_RECOGNITION
                && $stat['percentage'] >= self::ALWAYS_THERE_THRESHOLD;
            if (!$qualifies) {
                continue;
            }
            $names[] = [
                'user_id' => (int) $stat['user_id'],
                'first_name' => (string) $stat['first_name'],
                'last_name' => (string) $stat['last_name'],
                'is_self' => (int) $stat['user_id'] === $userId,
            ];
        }

        return $names;
    }

    /**
     * Quoten je Stimmgruppe: beantwortet "Steht die Besetzung?", ohne dass jemand
     * 60 Zeilen im Kopf zusammenzählt.
     *
     * Reihenfolge wie im Chor (Sopran, Alt, Tenor, Bass), "Ohne Stimmgruppe" zuletzt.
     * Wer keine Quote hat, weil für ihn kein Termin galt, zählt als Mitglied, geht
     * aber nicht in den Durchschnitt ein.
     *
     * @param list<array<string, mixed>> $stats
     * @return list<array{name: string, member_count: int, average: ?float, below_low: int}>
     */
    private function summarizeByVoiceGroup(array $stats): array
    {
        usort(
            $stats,
            static fn (array $a, array $b): int => $a['voice_group_rank'] <=> $b['voice_group_rank']
        );

        $groups = [];
        foreach ($stats as $stat) {
            $name = (string) $stat['primary_voice_group'];
            $groups[$name] ??= ['quotas' => [], 'member_count' => 0];
            $groups[$name]['member_count']++;
            if ($stat['percentage'] !== null) {
                $groups[$name]['quotas'][] = (float) $stat['percentage'];
            }
        }

        $summary = [];
        foreach ($groups as $name => $group) {
            $quotas = $group['quotas'];
            $summary[] = [
                'name' => $name,
                'member_count' => $group['member_count'],
                'average' => $quotas === [] ? null : round(array_sum($quotas) / count($quotas), 1),
                'below_low' => count(array_filter($quotas, static fn (float $quota): bool => $quota < self::QUOTA_LOW)),
            ];
        }

        return $summary;
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
                // Die Besetzung eines Projekts ist für alle Mitglieder des Projekts einsehbar;
                // ein Stimmgruppen-Filter hätte hier früher nur am Hierarchie-Level gehangen.
                $groupedMembers = $this->projectQuery
                    ->getProjectMembersGroupedByVoice($projectId);

                $this->rememberSelectedProject($userId, $projectId);
            }
        }

        // Die Summen stehen im Kopf der Seite: Wer die Besetzung prüft, will die Balance der
        // Stimmgruppen sehen, ohne selbst zu zählen. Archivierte zählen mit, werden aber benannt.
        $voiceGroupCounts = [];
        $archivedTotal = 0;
        foreach ($groupedMembers as $voiceGroup => $subVoices) {
            $voiceGroupCounts[$voiceGroup] = 0;
            foreach ($subVoices as $members) {
                $voiceGroupCounts[$voiceGroup] += count($members);
                foreach ($members as $member) {
                    if (!$member['is_active']) {
                        $archivedTotal++;
                    }
                }
            }
        }

        return $this->view->render($response, 'evaluations/project_members.twig', [
            'projects' => $projects,
            'selected_project' => $selectedProject,
            'grouped_members' => $groupedMembers,
            'voice_group_counts' => $voiceGroupCounts,
            'member_total' => array_sum($voiceGroupCounts),
            'archived_total' => $archivedTotal,
            // Der Sprung in die Mitgliederpflege folgt derselben Policy wie die Zielseite:
            // die Auswertung ist breiter sichtbar als die Verwaltung.
            'can_manage_members' => $projectId > 0 && $this->memberPolicy->canViewMembers($projectId),
        ]);
    }

    public function registrations(Request $request, Response $response): Response
    {
        $includePast = InputValidator::asString($request->getQueryParams()['include_past'] ?? null) === '1';

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
        // audienceFilters.conditions vorladen: Ohne das holte die Zielgruppen-Auflösung je
        // Termin eine eigene Abfrage, noch vor allem Weiteren.
        $events = $query->with('audienceFilters.conditions')->get();

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
            ->where('status', Attendance::STATUS_PRESENT)
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
     * danach die zuletzt gewählte Auswahl des Nutzers. Beide Kandidaten müssen
     * in den für den Nutzer zugänglichen Projekten liegen.
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
     * Auswertungen sind Anwesenheits-/Anmeldedaten: projektübergreifend sieht sie, wer
     * Anwesenheit für alle Mitglieder verwalten darf - nicht, wer Mitglieder verwaltet.
     */
    private function canSeeAllProjects(): bool
    {
        return (bool) ($_SESSION['can_manage_attendance_all'] ?? false);
    }
}
