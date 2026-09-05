<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use App\Services\NotificationService;
use App\Util\AppUrlResolver;
use App\Util\NotificationType;
use App\Persistence\ProjectPersistence;

class ProjectController
{
    private Twig $view;
    private ProjectQuery $projectQuery;
    private ProjectPersistence $projectPersistence;
    private ProjectMemberPolicy $policy;
    private LoggerInterface $logger;

    /**
     * Steht am Ende und optional, weil viele Tests diesen Controller mit festen
     * Positionsargumenten bauen. Im Betrieb reicht ihn die ausdrückliche
     * Registrierung in `Dependencies.php` durch - PHP-DI füllt optionale
     * Parameter nicht selbst; dagegen steht `NotificationWiringFeatureTest`.
     */
    private ?NotificationService $notificationService;

    public function __construct(
        Twig $view,
        ProjectQuery $projectQuery,
        ProjectPersistence $projectPersistence,
        ProjectMemberPolicy $policy,
        ?LoggerInterface $logger = null,
        ?NotificationService $notificationService = null
    ) {
        $this->view = $view;
        $this->projectQuery = $projectQuery;
        $this->projectPersistence = $projectPersistence;
        $this->policy = $policy;
        $this->logger = $logger ?? new NullLogger();
        $this->notificationService = $notificationService;
    }

    /**
     * Einziger Ausgang für alle Rechte-Abweisungen dieses Controllers: die
     * Middleware lässt Inhaber eines Projektmitglieder-Rechts passieren, erst
     * die Policy entscheidet projektbezogen. Diese Abweisungen blieben früher
     * unprotokolliert und lieferten eine leere 403-Seite ohne Begründung.
     */
    private function denyProjectAccess(Response $response, int $projectId, string $message): Response
    {
        $this->logger->info('Access denied.', [
            'event' => 'authz.denied',
            'permission' => 'can_manage_project_members',
            'project_id' => $projectId,
        ]);

        return $this->view->render($response->withStatus(403), 'errors/403.twig', [
            'error' => $message,
        ]);
    }

    public function index(Request $request, Response $response): Response
    {
        $projects = $this->projectQuery->getAllProjects();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'projects/index.twig', [
            'projects' => $projects,
            // Die Liste spiegelt die Policy: das breite Recht erreicht jedes Projekt,
            // das stimmgruppen-beschränkte nur die eigenen. So bietet die Oberfläche
            // keinen Mitglieder-Link an, der im 403 endet.
            'memberManagedProjectIds' => $this->policy->getAccessibleProjectIds(),
            'success' => $success,
            'error' => $error
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $startDate = $data['start_date'] ?? null;
        $endDate = $data['end_date'] ?? null;

        if (!$name) {
            $_SESSION['error'] = 'Geben Sie einen Namen für das Projekt ein.';
            return $response->withHeader('Location', '/projects')->withStatus(302);
        }

        $project = new Project();
        $project->name = $name;
        $project->description = $description;
        $project->start_date = $startDate ?: null;
        $project->end_date = $endDate ?: null;
        $project->save();

        $_SESSION['success'] = 'Projekt erfolgreich angelegt.';
        return $response->withHeader('Location', '/projects')->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $projectId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $startDate = $data['start_date'] ?? null;
        $endDate = $data['end_date'] ?? null;

        if (!$name) {
            $_SESSION['error'] = 'Geben Sie einen Namen für das Projekt ein.';
            return $response->withHeader('Location', '/projects')->withStatus(302);
        }

        $project = Project::find($projectId);

        if (!$project) {
            $_SESSION['error'] = 'Projekt nicht gefunden.';
            return $response->withHeader('Location', '/projects')->withStatus(302);
        }

        $project->name = $name;
        $project->description = $description;
        $project->start_date = $startDate ?: null;
        $project->end_date = $endDate ?: null;
        $project->save();

        $_SESSION['success'] = 'Projekt erfolgreich aktualisiert.';
        return $response->withHeader('Location', '/projects')->withStatus(302);
    }

    public function showMembers(Request $request, Response $response, array $args): Response
    {
        $projectId = (int)$args['id'];

        if (!$this->policy->canViewMembers($projectId)) {
            return $this->denyProjectAccess(
                $response,
                $projectId,
                'Sie haben keine Berechtigung, die Mitglieder dieses Projekts zu verwalten.'
            );
        }

        $project = $this->projectQuery->findById($projectId);

        if (!$project) {
            return $this->denyProjectAccess($response, $projectId, 'Dieses Projekt existiert nicht.');
        }

        // Members are already loaded with relationships via ProjectQuery::getProjectMembers()
        $members = $this->projectQuery->getProjectMembers($projectId);

        // The broad right sees every candidate; the voice-group-scoped right only sees
        // members of its own voice group so it cannot pull in foreign singers.
        $availableUsers = $this->policy->canViewAllCandidates()
            ? $this->projectQuery->getUsersNotInProject($projectId)
            : $this->projectQuery->getUsersNotInProjectForVoiceGroups(
                $projectId,
                $this->policy->ownVoiceGroupIds()
            );

        // Map members to the array structure Twig expects
        $mappedMembers = $members->map(function ($user) use ($projectId) {
            // Group concatenating the voice groups manually to replicate old behavior
            $vgDisplays = [];
            foreach ($user->voiceGroups as $vg) {
                $display = $vg->name;
                if ($vg->pivot->sub_voice_id) {
                    $sv = $user->subVoices->firstWhere('id', $vg->pivot->sub_voice_id);
                    if ($sv) {
                        $display .= ' (' . $sv->name . ')';
                    }
                }
                $vgDisplays[] = $display;
            }

            // A voice-group-scoped manager may only remove members of their own voice
            // group; hide the remove button for everyone else.
            $memberVoiceGroupIds = $user->voiceGroups->pluck('id')->all();

            return [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'voice_groups_display' => implode(', ', array_unique($vgDisplays)),
                // Archivierte Mitglieder bleiben in der Liste, damit sie sich
                // entfernen lassen - die Oberfläche kennzeichnet sie.
                'is_active' => (bool) $user->is_active,
                'can_remove' => $this->policy->canManageMember($projectId, $memberVoiceGroupIds),
            ];
        });

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'projects/members.twig', [
            'project' => $project,
            'members' => $mappedMembers,
            'available_users' => $availableUsers,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function listForMembers(Request $request, Response $response): Response
    {
        // Die Einschränkung läuft in der Abfrage, nicht als filter() über alle
        // Projekte: die Datenbank liefert gleich nur die zugänglichen zurück.
        $projects = $this->projectQuery->getProjectsByIds($this->policy->getAccessibleProjectIds());

        return $this->view->render($response, 'projects/member_projects.twig', [
            'projects' => $projects,
        ]);
    }

    public function addMember(Request $request, Response $response, array $args): Response
    {
        $projectId = (int)$args['id'];

        if (!$this->policy->canAddMember($projectId)) {
            return $this->denyProjectAccess(
                $response,
                $projectId,
                'Sie haben keine Berechtigung, diesem Projekt Mitglieder hinzuzufügen.'
            );
        }

        $data = (array)$request->getParsedBody();
        $userId = (int)($data['user_id'] ?? 0);

        if ($userId <= 0) {
            $_SESSION['error'] = 'Bitte einen Benutzer auswählen.';
            return $response->withHeader('Location', '/projects/' . $projectId . '/members')->withStatus(302);
        }

        // Ohne diese Pruefung liefe eine unbekannte ID in den Fremdschluessel der
        // Zuordnungstabelle und quittierte die Eingabe mit einem HTTP 500.
        if (!$this->projectQuery->userExists($userId)) {
            $_SESSION['error'] = 'Das ausgewählte Mitglied existiert nicht.';
            return $response->withHeader('Location', '/projects/' . $projectId . '/members')->withStatus(302);
        }

        if (!$this->userIsInManageableVoiceGroup($projectId, $userId)) {
            $_SESSION['error'] = 'Sie dürfen nur Mitglieder Ihrer eigenen Stimmgruppe zuweisen.';
            return $response->withHeader('Location', '/projects/' . $projectId . '/members')->withStatus(302);
        }

        $reactivated = $this->projectPersistence->addProjectMember($projectId, $userId);

        $this->notifyMemberAdded($request, $projectId, $userId);

        $_SESSION['success'] = $reactivated
            ? 'Mitglied dem Projekt hinzugefügt und wieder aktiviert.'
            : 'Mitglied dem Projekt hinzugefügt.';
        return $response->withHeader('Location', '/projects/' . $projectId . '/members')->withStatus(302);
    }

    /**
     * Meldet der hinzugefügten Person, dass sie jetzt zum Projekt gehört.
     *
     * Empfänger ist nur sie selbst: Die übrigen Mitglieder eines Projekts über
     * jeden Zugang zu informieren, wäre bei einer Aufnahme von dreißig Leuten
     * eine Lawine ohne Nutzen.
     */
    private function notifyMemberAdded(Request $request, int $projectId, int $userId): void
    {
        if ($this->notificationService === null) {
            return;
        }

        $project = Project::find($projectId);
        $user = User::find($userId);

        if ($project === null || $user === null) {
            return;
        }

        $baseUrl = AppUrlResolver::resolveBaseUrl($request);

        $this->notificationService->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$user],
            'Du bist jetzt bei „' . $project->name . '“ dabei',
            'emails/notification_project_member_added.twig',
            [
                'project' => $project,
                'link' => $baseUrl . '/projects/' . $project->id . '/members',
                'profile_url' => $baseUrl . '/profile',
            ],
            (int) ($_SESSION['user_id'] ?? 0) ?: null
        );
    }

    public function removeMember(Request $request, Response $response, array $args): Response
    {
        $projectId = (int)$args['id'];

        if (!$this->policy->canRemoveMember($projectId)) {
            return $this->denyProjectAccess(
                $response,
                $projectId,
                'Sie haben keine Berechtigung, Mitglieder aus diesem Projekt zu entfernen.'
            );
        }

        $userId = (int)($args['user_id'] ?? 0);

        if ($userId <= 0) {
            $_SESSION['error'] = 'Ungültige Anfrage.';
            return $response->withHeader('Location', '/projects/' . $projectId . '/members')->withStatus(302);
        }

        if (!$this->userIsInManageableVoiceGroup($projectId, $userId)) {
            $_SESSION['error'] = 'Sie dürfen nur Mitglieder Ihrer eigenen Stimmgruppe entfernen.';
            return $response->withHeader('Location', '/projects/' . $projectId . '/members')->withStatus(302);
        }

        $this->projectPersistence->removeProjectMember($projectId, $userId);
        $_SESSION['success'] = 'Mitglied vom Projekt entfernt.';
        return $response->withHeader('Location', '/projects/' . $projectId . '/members')->withStatus(302);
    }

    /**
     * Guards add/remove against the voice-group scope. A broad project member
     * manager passes for every user; a voice-group-scoped holder only for users
     * that share one of their own voice groups. Ein unbekanntes Mitglied hat
     * keine Stimmgruppen und ist daher nur für das stimmgruppen-beschränkte
     * Recht ausgeschlossen - die Existenz prüft addMember() separat.
     */
    private function userIsInManageableVoiceGroup(int $projectId, int $userId): bool
    {
        $voiceGroupIds = $this->projectQuery->getUserVoiceGroupIds($userId);

        return $this->policy->canManageMember($projectId, $voiceGroupIds);
    }
}
