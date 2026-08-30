<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Queries\UserQuery;
use App\Logging\ExceptionLogContext;
use App\Persistence\UserPersistence;
use App\Persistence\ProjectPersistence;
use App\Policies\UserEditPolicy;
use App\Models\User;
use App\Models\Role;
use App\Models\VoiceGroup;
use App\Models\SubVoice;
use App\Models\Project;
use App\Services\ModalFormService;
use App\Models\AppSetting;
use App\Models\InvitationToken;
use App\Services\Mailer;
use App\Services\MailQueueService;
use App\Util\AppUrlResolver;
use App\Util\InvitationHandoffLink;
use App\Util\MailBranding;
use Psr\Log\LoggerInterface;

class UserController
{
    /** Sperrgrund, wenn sonst niemand mehr an die Mitgliederverwaltung kaeme. */
    private const LAST_MANAGER_MESSAGE = 'Das ist das letzte Mitglied mit Mitgliederverwaltung. '
        . 'Vergib das Recht zuerst an ein anderes Mitglied.';

    private Twig $view;
    private UserQuery $userQuery;
    private UserPersistence $userPersistence;
    private ProjectPersistence $projectPersistence;
    private MailQueueService $mailQueueService;
    private LoggerInterface $logger;
    private UserEditPolicy $userEditPolicy;

    public function __construct(
        Twig $view,
        UserQuery $userQuery,
        UserPersistence $userPersistence,
        ProjectPersistence $projectPersistence,
        MailQueueService $mailQueueService,
        LoggerInterface $logger,
        UserEditPolicy $userEditPolicy
    ) {
        $this->view = $view;
        $this->userQuery = $userQuery;
        $this->userPersistence = $userPersistence;
        $this->projectPersistence = $projectPersistence;
        $this->mailQueueService = $mailQueueService;
        $this->logger = $logger;
        $this->userEditPolicy = $userEditPolicy;
    }

    public function index(Request $request, Response $response): Response
    {
        $canManageUsers = $_SESSION['can_manage_users'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;
        $myVgs = $_SESSION['voice_group_ids'] ?? [];
        $canEditGlobal = $_SESSION['can_edit_users'] ?? false;

        $params = $request->getQueryParams();
        $showArchived = isset($params['archived']) && $params['archived'] === '1';

        if ($showArchived && !$canManageUsers) {
            $showArchived = false;
        }

        if ($showArchived) {
            $users = $this->userQuery->getArchivedUsers();
        } else {
            $users = $this->userQuery->getAllUsers();

            if (!$canManageUsers) {
                if (empty($myVgs)) {
                    $users = collect();
                } else {
                    $users = $users->filter(function ($user) use ($myVgs) {
                        $uVgIds = $user->voiceGroups->pluck('id')->toArray();
                        return !empty(array_intersect($myVgs, $uVgIds));
                    });
                }
            }
        }

        $roles = Role::orderBy('hierarchy_level', 'desc')->get();
        $voiceGroups = VoiceGroup::orderBy('id')->get();
        $subVoices = SubVoice::orderBy('name')->get();
        $projects = Project::query()->chronological()->get();

        foreach ($users as $user) {
            $user->project_ids = $user->projects->pluck('id')->toArray();
            $user->project_count = count($user->project_ids);
            $user->project_participations = $this->buildProjectParticipations($user);
            $user->voice_group_ids = $user->voiceGroups->pluck('id')->toArray();
            $pivots = [];
            foreach ($user->voiceGroups as $vg) {
                $pivots[$vg->id] = $vg->pivot->sub_voice_id;
            }
            $user->voice_group_pivots = $pivots;
        }

        $canEditUsers = $canEditGlobal;
        if (!$canManageUsers) {
            $roles = $roles->filter(fn($r) => $r->hierarchy_level < $userLevel);
            $voiceGroups = $voiceGroups->filter(fn($vg) => in_array($vg->id, $myVgs));
            $canEditUsers = true;
        }

        $canManageProjectMembers = $_SESSION['can_manage_project_members'] ?? false;

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        // Get create form state
        $createService = new ModalFormService('user_create');
        $createState = $createService->getState();
        $createService->clear();

        // Edit form state is consumed by the lazy-loaded fragment endpoint
        // (editForm()), not here, so index() must not read or clear it. On a
        // validation error update() redirects to /users?edit={id}; the shell
        // auto-opens and the fragment reads the scoped state on its own request.
        $hasModalError = $createState['open_modal'];

        $canEditMember = [];
        foreach ($users as $user) {
            if ($this->userEditPolicy->canEdit($_SESSION, $user)) {
                $canEditMember[(int) $user->id] = true;
            }
        }

        $openEditUserId = null;
        $requestedEditId = (int) ($params['edit'] ?? 0);
        if ($requestedEditId > 0 && !$showArchived && isset($canEditMember[$requestedEditId])) {
            $openEditUserId = $requestedEditId;
        }

        return $this->view->render($response, 'users/manage.twig', [
            'users' => $users,
            'roles' => $roles,
            'voice_groups' => $voiceGroups,
            'sub_voices' => $subVoices,
            'projects' => $projects,
            'can_edit_users' => $canEditUsers,
            'can_manage_project_members' => $canManageProjectMembers,
            'show_archived' => $showArchived,
            'success' => $success,
            'error' => $error,
            'has_modal_error' => $hasModalError,
            'modal_form_create' => $createState,
            'can_edit_member' => $canEditMember,
            'open_edit_user_id' => $openEditUserId,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $submitAction = (string) ($data['submit_action'] ?? 'save');

        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $email = trim($data['email'] ?? '');

        $roleIds = $data['roles'] ?? [];
        $voiceGroupIds = $data['voice_groups'] ?? [];
        $subVoices = $data['sub_voices'] ?? [];

        $canManageUsers = $_SESSION['can_manage_users'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;
        $myVgs = $_SESSION['voice_group_ids'] ?? [];

        $formData = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'roles' => array_map('intval', (array) $roleIds),
            'voice_groups' => array_map('intval', (array) $voiceGroupIds),
            'sub_voices' => array_map('intval', (array) ($subVoices ?? [])),
        ];

        if (!$canManageUsers) {
            $allowedRoles = Role::where('hierarchy_level', '<', $userLevel)->pluck('id')->toArray();
            $roleIds = array_intersect((array) $roleIds, $allowedRoles);

            $voiceGroupIds = array_intersect((array) $voiceGroupIds, $myVgs);
            if (empty($voiceGroupIds)) {
                $createService = new ModalFormService('user_create');
                $createService->setError('Du musst mindestens eine deiner Stimmgruppen zuweisen.', $formData);
                return $response->withHeader('Location', '/users')->withStatus(302);
            }
        }

        // Never allow assigning a role that outranks the actor's own hierarchy level.
        $roleIds = $this->capRoleIdsToActorLevel($roleIds);

        if (!$firstName || !$lastName || !$email || empty($roleIds)) {
            $createService = new ModalFormService('user_create');
            $createService->setError('Bitte fülle alle Pflichtfelder aus (inkl. mind. einer Rolle).', $formData);
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        // Reject malformed or over-long addresses before the DB write. The email
        // column is varchar(255); without this guard an invalid value surfaces as
        // a generic QueryException/500 instead of a form-level hint.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            $createService = new ModalFormService('user_create');
            $createService->setError('Bitte gib eine gültige E-Mail-Adresse ein.', $formData);
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        if (User::where('email', $email)->exists()) {
            $createService = new ModalFormService('user_create');
            $createService->setError('Diese E-Mail-Adresse wird bereits verwendet.', $formData);
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        try {
            $user = new User();
            $user->first_name = $firstName;
            $user->last_name = $lastName;
            $user->email = $email;
            // users.password is NOT NULL in the current schema; generate an internal one-time placeholder hash
            $temporaryPassword = bin2hex(random_bytes(32));
            $user->password = password_hash($temporaryPassword, PASSWORD_DEFAULT);
            $user->is_active = 1;

            $this->userPersistence->save($user);

            $this->logger->info('User created.', [
                'event' => 'user.created',
                'user_id' => (int) $user->id,
            ]);

            $this->userPersistence->syncRoles($user, $roleIds);

            foreach (array_map('intval', $roleIds) as $assignedRoleId) {
                $this->logger->info('Role assigned to user.', [
                    'event' => 'user.role.assigned',
                    'user_id' => (int) $user->id,
                    'role_id' => $assignedRoleId,
                ]);
            }

            $vgData = [];
            foreach ($voiceGroupIds as $vgId) {
                $svId = !empty($subVoices[$vgId]) ? (int) $subVoices[$vgId] : null;
                $vgData[$vgId] = ['sub_voice_id' => $svId];
            }
            $this->userPersistence->syncVoiceGroups($user, $vgData);

            if ($submitAction === 'save_and_invite') {
                $inviteResult = $this->sendInvitationEmail($user, $request);
                if ($inviteResult['success']) {
                    $_SESSION['success'] = 'Mitglied erfolgreich angelegt und Einladungs-E-Mail gesendet.';
                } else {
                    $_SESSION['success'] = 'Mitglied erfolgreich angelegt.';
                    $_SESSION['error'] = $inviteResult['message'] ?? 'Einladungs-E-Mail konnte nicht gesendet werden.';
                }
            } else {
                $_SESSION['success'] = 'Mitglied erfolgreich angelegt.';
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'User creation failed.',
                [
                    'event' => 'user.create.failed',
                    'email' => $email,
                ] + ExceptionLogContext::build($e)
            );
            $createService = new ModalFormService('user_create');
            $createService->setError('Fehler beim Anlegen des Mitglieds.', $formData);
        }

        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $canEditGlobal = $_SESSION['can_edit_users'] ?? false;
        $canManageUsers = $_SESSION['can_manage_users'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;
        $myVgs = $_SESSION['voice_group_ids'] ?? [];

        $userId = (int) $args['id'];
        $targetUser = $this->userQuery->findById($userId);

        if (!$targetUser) {
            $_SESSION['error'] = 'Nutzer nicht gefunden.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $targetVgIds = $targetUser->voiceGroups->pluck('id')->toArray();

        // Nobody may modify a member who outranks them in the role hierarchy - not even global
        // user managers. This prevents lower-ranked admins from hijacking higher-ranked accounts
        // (e.g. resetting an Obmann's password or e-mail from a lower administrative role).
        if ($this->outranksActor($targetUser)) {
            $_SESSION['error'] = 'Du hast keine Berechtigung, dieses Mitglied zu bearbeiten.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        // Die Policy entscheidet über jede Stufe, damit Mitgliederliste, Formular
        // und Speichern nicht auseinanderlaufen: canEdit() öffnet das Formular,
        // canEditProfile() erlaubt das Schreiben der Mitgliedsdaten,
        // canEditProjects() das der Projektzuordnung. Die Basisprüfungen der Policy
        // - archiviertes Ziel, höher gereihtes Ziel - gelten damit auf jeder Stufe.
        $canEditProjects = $this->userEditPolicy->canEditProjects($_SESSION, $targetUser);
        $canEditProfile = $this->userEditPolicy->canEditProfile($_SESSION, $targetUser);
        $canEditEmail = $this->userEditPolicy->canEditEmail($_SESSION, $targetUser);

        if (!$canEditProfile && !$canEditProjects) {
            $_SESSION['error'] = 'Du hast keine Berechtigung, dieses Mitglied zu bearbeiten.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');

        // Ohne can_edit_users bleibt die Adresse, wie sie ist. Ein uebermittelter
        // Wert wird verworfen statt abgewiesen: das Formular zeigt das Feld dann
        // nur lesend an, ein trotzdem gesendeter Wert stammt nicht aus der
        // Oberflaeche und darf die erlaubten Aenderungen nicht blockieren.
        $email = $canEditEmail ? trim($data['email'] ?? '') : (string) $targetUser->email;

        $roleIds = $data['roles'] ?? [];
        $voiceGroupIds = $data['voice_groups'] ?? [];
        $subVoices = $data['sub_voices'] ?? [];
        $projectIds = array_values(array_filter(
            array_map('intval', (array) ($data['projects'] ?? [])),
            fn(int $id): bool => $id > 0
        ));

        // Wer nur can_manage_project_members haelt, aendert ausschliesslich die
        // Projektzuordnung. Die uebrigen Formularfelder werden bewusst verworfen
        // statt validiert: sie stehen dieser Rolle nicht zu, und eine E-Mail-Kollision
        // im Zielkonto duerfte die erlaubte Projektzuordnung nicht blockieren.
        if (!$canEditProfile) {
            $this->projectPersistence->setUserProjects($userId, $projectIds);

            $this->logger->info('User project assignment changed.', [
                'event' => 'user.projects.changed',
                'user_id' => $userId,
                'project_ids' => $projectIds,
            ]);

            $_SESSION['success'] = 'Projektzuordnung erfolgreich aktualisiert.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $formData = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'roles' => array_map('intval', (array) $roleIds),
            'voice_groups' => array_map('intval', (array) $voiceGroupIds),
            'sub_voices' => array_map('intval', (array) ($subVoices ?? [])),
        ];

        if (!$canManageUsers) {
            $allowedRoles = Role::where('hierarchy_level', '<', $userLevel)->pluck('id')->toArray();

            $unmanageableRoles = $targetUser->roles->where('hierarchy_level', '>=', $userLevel)->pluck('id')->toArray();
            $roleIds = array_merge(array_intersect((array) $roleIds, $allowedRoles), $unmanageableRoles);

            $unmanageableVgs = array_diff($targetVgIds, $myVgs);
            $voiceGroupIds = array_merge(array_intersect((array) $voiceGroupIds, $myVgs), $unmanageableVgs);

            foreach ($unmanageableVgs as $uVg) {
                // Keep the old subvoice for unmanageable voice groups
                $vgPivot = $targetUser->voiceGroups->firstWhere('id', $uVg);
                if ($vgPivot && $vgPivot->pivot->sub_voice_id) {
                    $subVoices[$uVg] = $vgPivot->pivot->sub_voice_id;
                }
            }
        }

        // Never allow assigning a role that outranks the actor's own hierarchy level,
        // regardless of whether the actor is a global user manager.
        $roleIds = $this->capRoleIdsToActorLevel($roleIds);

        // Gleichrangige duerfen einander verwalten - sonst koennten zwei Vorstaende
        // einander nie vertreten. Damit laesst sich aber auch dem letzten
        // verbleibenden Mitgliederverwalter sein Recht entziehen, und danach kommt
        // niemand mehr an die Mitgliederverwaltung heran.
        if ($this->wouldDropLastUserManager($targetUser, $roleIds)) {
            $editService = new ModalFormService('user_edit_' . $userId);
            $editService->setError(
                'Das ist die letzte Rolle mit Mitgliederverwaltung. Vergib das Recht zuerst an '
                . 'ein anderes Mitglied.',
                $formData,
                [],
                false
            );
            return $response->withHeader('Location', '/users?edit=' . $userId)->withStatus(302);
        }

        if (!$firstName || !$lastName || !$email || empty($roleIds)) {
            $editService = new ModalFormService('user_edit_' . $userId);
            $editService->setError(
                'Bitte fülle alle Pflichtfelder aus (inkl. mind. einer Rolle).',
                $formData,
                [],
                false
            );
            return $response->withHeader('Location', '/users?edit=' . $userId)->withStatus(302);
        }

        // Reject malformed or over-long addresses before the DB write (varchar(255)),
        // so an invalid value yields a form-level hint instead of a 500.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            $editService = new ModalFormService('user_edit_' . $userId);
            $editService->setError('Bitte gib eine gültige E-Mail-Adresse ein.', $formData, [], false);
            return $response->withHeader('Location', '/users?edit=' . $userId)->withStatus(302);
        }

        // Email uniqueness check (excluding self)
        if (User::where('email', $email)->where('id', '!=', $userId)->exists()) {
            $editService = new ModalFormService('user_edit_' . $userId);
            $editService->setError('Diese E-Mail-Adresse wird bereits verwendet.', $formData, [], false);
            return $response->withHeader('Location', '/users?edit=' . $userId)->withStatus(302);
        }

        // Captured before the target is mutated below, so the log calls after a successful
        // save can report what actually changed instead of re-deriving it from the request.
        $previousEmail = $targetUser->email;
        $previousRoleIds = $targetUser->roles->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        try {
            // Passwords are never set here - members choose their own via the invitation
            // or password reset link, so no administrator ever learns another member's password.
            $targetUser->first_name = $firstName;
            $targetUser->last_name = $lastName;
            $targetUser->email = $email;


            $this->userPersistence->save($targetUser);

            if ($previousEmail !== $email) {
                $this->logger->info('User email changed.', [
                    'event' => 'user.email.changed',
                    'user_id' => (int) $targetUser->id,
                    'old_email' => $previousEmail,
                    'new_email' => $email,
                ]);
            }

            $this->userPersistence->syncRoles($targetUser, $roleIds);

            $newRoleIds = array_map('intval', $roleIds);
            foreach (array_diff($newRoleIds, $previousRoleIds) as $assignedRoleId) {
                $this->logger->info('Role assigned to user.', [
                    'event' => 'user.role.assigned',
                    'user_id' => (int) $targetUser->id,
                    'role_id' => $assignedRoleId,
                ]);
            }
            foreach (array_diff($previousRoleIds, $newRoleIds) as $revokedRoleId) {
                $this->logger->info('Role revoked from user.', [
                    'event' => 'user.role.revoked',
                    'user_id' => (int) $targetUser->id,
                    'role_id' => $revokedRoleId,
                ]);
            }

            // $voiceGroupIds steht bereits fest: Für einen globalen Verwalter ist es die
            // Auswahl aus dem Formular, sonst hat der Block oben die eigenen Gruppen mit
            // den nicht verwaltbaren zusammengeführt und deren Untergruppen in $subVoices
            // gesichert. Die frühere zweite Berechnung an dieser Stelle war Wort für Wort
            // dieselbe und lieferte zwangsläufig dasselbe Ergebnis.
            $vgData = [];
            foreach ((array) $voiceGroupIds as $vgId) {
                $svId = !empty($subVoices[$vgId]) ? (int) $subVoices[$vgId] : null;
                $vgData[$vgId] = ['sub_voice_id' => $svId];
            }


            $this->userPersistence->syncVoiceGroups($targetUser, $vgData);

            // can_manage_project_members reicht projektübergreifend, deshalb wird die
            // Projektauswahl hier nicht mehr auf die eigenen Projekte gefiltert.
            if ($canEditGlobal || $canEditProjects) {
                $this->projectPersistence->setUserProjects($userId, $projectIds);
            }

            $_SESSION['success'] = 'Mitglied erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $this->logger->error(
                'User update failed.',
                [
                    'event' => 'user.update.failed',
                    'user_id' => $userId,
                    'exception' => $e,
                ]
            );
            $editService = new ModalFormService('user_edit_' . $userId);
            $editService->setError('Fehler beim Speichern.', $formData, [], false);
            return $response->withHeader('Location', '/users?edit=' . $userId)->withStatus(302);
        }

        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    /**
     * Renders the edit form for a single member as an HTML fragment, loaded lazily
     * into the shared #editUserModal shell on /users. Rendering the form once per
     * request (instead of once per user inline) keeps the /users response small
     * enough to stay inside nginx's FastCGI memory buffer.
     */
    public function editForm(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];
        $targetUser = $this->userQuery->findById($userId);

        if (!$targetUser) {
            $response->getBody()->write(
                '<div class="modal-content"><div class="modal-body">'
                . '<div class="alert alert-danger mb-0">Mitglied nicht gefunden.</div>'
                . '</div></div>'
            );
            return $response->withStatus(404);
        }

        // Mirror update()'s guards: never expose an editable form the actor could not save.
        if (!$this->userEditPolicy->canEdit($_SESSION, $targetUser)) {
            $response->getBody()->write(
                '<div class="modal-content"><div class="modal-body">'
                . '<div class="alert alert-danger mb-0">Keine Berechtigung, dieses Mitglied zu bearbeiten.</div>'
                . '</div></div>'
            );
            return $response->withStatus(403);
        }

        $canManageUsers = $_SESSION['can_manage_users'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;
        $myVgs = $_SESSION['voice_group_ids'] ?? [];
        $canEditUsers = (bool) ($_SESSION['can_edit_users'] ?? false);
        $canManageProjectMembers = $_SESSION['can_manage_project_members'] ?? false;
        // Deckt sich mit der Schreibpruefung in update(): ohne dieses Recht zeigt das
        // Formular nur die Projektzuordnung, damit niemand Felder ausfuellt, die beim
        // Speichern stillschweigend verworfen wuerden.
        $canEditProfile = $this->userEditPolicy->canEditProfile($_SESSION, $targetUser);

        $roles = Role::orderBy('hierarchy_level', 'desc')->get();
        $voiceGroups = VoiceGroup::orderBy('id')->get();
        $subVoices = SubVoice::orderBy('name')->get();
        $projects = Project::query()->chronological()->get();

        if (!$canManageUsers) {
            $roles = $roles->filter(fn($r) => $r->hierarchy_level < $userLevel);
            $voiceGroups = $voiceGroups->filter(fn($vg) => in_array($vg->id, $myVgs));
            $canEditUsers = true;
        }

        $targetUser->project_ids = $targetUser->projects->pluck('id')->toArray();
        $targetUser->voice_group_ids = $targetUser->voiceGroups->pluck('id')->toArray();
        $pivots = [];
        foreach ($targetUser->voiceGroups as $vg) {
            $pivots[$vg->id] = $vg->pivot->sub_voice_id;
        }
        $targetUser->voice_group_pivots = $pivots;

        $editService = new ModalFormService('user_edit_' . $userId);
        $editState = $editService->getState();
        $editService->clear();

        return $this->view->render($response, 'partials/user_edit_form.twig', [
            'user' => $targetUser,
            'roles' => $roles->values(),
            'voice_groups' => $voiceGroups->values(),
            'sub_voices' => $subVoices,
            'projects' => $projects,
            'can_edit_users' => $canEditUsers,
            'can_edit_profile' => $canEditProfile,
            // Adresse und Einladung haengen an der Mitgliederverwaltung, nicht am
            // Bearbeiten-Recht: siehe UserEditPolicy::canEditEmail().
            'can_edit_email' => $this->userEditPolicy->canEditEmail($_SESSION, $targetUser),
            'can_invite' => (bool) $canManageUsers,
            'can_manage_project_members' => $canManageProjectMembers,
            'edit_state' => $editState,
        ]);
    }

    public function deactivate(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];

        if ($userId === (int) ($_SESSION['user_id'] ?? 0)) {
            $_SESSION['error'] = 'Du kannst deinen eigenen Account nicht deaktivieren.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $canEditGlobal = $_SESSION['can_edit_users'] ?? false;
        $canManageOwnVoiceGroup = (bool) ($_SESSION['can_manage_own_voice_group'] ?? false);
        $myVgs = $_SESSION['voice_group_ids'] ?? [];

        $targetUser = $this->userQuery->findById($userId);
        if (!$targetUser) {
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        if ($this->outranksActor($targetUser)) {
            $_SESSION['error'] = 'Du hast keine Berechtigung, dieses Mitglied zu deaktivieren.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $targetVgIds = $targetUser->voiceGroups->pluck('id')->toArray();
        $isInMyGroup = !empty(array_intersect($myVgs, $targetVgIds));

        if (!$canEditGlobal) {
            if (!$canManageOwnVoiceGroup || !$isInMyGroup) {
                $_SESSION['error'] = 'Du hast keine Berechtigung, dieses Mitglied zu deaktivieren.';
                return $response->withHeader('Location', '/users')->withStatus(302);
            }
        }

        if ($this->isLastUserManager($targetUser)) {
            $_SESSION['error'] = self::LAST_MANAGER_MESSAGE;
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $targetUser->is_active = 0;
        $this->userPersistence->save($targetUser);

        $this->logger->info('User deactivated.', [
            'event' => 'user.deactivated',
            'user_id' => (int) $targetUser->id,
        ]);

        $_SESSION['success'] = 'Mitglied wurde archiviert (deaktiviert).';
        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    public function bulkDeactivate(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $sourceIds = $data['user_ids'] ?? [];

        if (is_string($sourceIds)) {
            $sourceIds = explode(',', $sourceIds);
        }

        $ids = array_values(array_filter(array_map('intval', (array) $sourceIds)));

        if (empty($ids)) {
            $_SESSION['error'] = 'Keine Mitglieder ausgewählt.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $processed = 0;
        $failed = [];

        foreach ($ids as $id) {
            if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
                $failed[] = $id;
                continue;
            }

            $targetUser = $this->userQuery->findById($id);
            if (!$targetUser) {
                $failed[] = $id;
                continue;
            }

            if (!$this->canDeactivateTargetUser($targetUser)) {
                $failed[] = $id;
                continue;
            }

            $targetUser->is_active = 0;
            $this->userPersistence->save($targetUser);

            $this->logger->info('User deactivated.', [
                'event' => 'user.deactivated',
                'user_id' => (int) $targetUser->id,
            ]);

            $processed++;
        }

        $_SESSION['success'] = sprintf(
            'Bulk-Aktion abgeschlossen: %d deaktiviert, %d fehlgeschlagen.',
            $processed,
            count($failed)
        );

        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];
        $targetUser = $this->userQuery->findById($userId);

        if (!$targetUser || (bool) $targetUser->is_active) {
            $_SESSION['error'] = 'Mitglied nicht gefunden oder bereits aktiv.';
            return $response->withHeader('Location', '/users?archived=1')->withStatus(302);
        }

        // Dieselbe Befugnis wie beim Archivieren: wer stilllegen darf, muss das
        // auch zuruecknehmen koennen.
        if (!$this->canArchiveTargetUser($targetUser)) {
            $_SESSION['error'] = 'Du hast keine Berechtigung, dieses Mitglied wiederherzustellen.';
            return $response->withHeader('Location', '/users?archived=1')->withStatus(302);
        }

        $targetUser->is_active = 1;
        $this->userPersistence->save($targetUser);

        $this->logger->info('User activated.', [
            'event' => 'user.activated',
            'user_id' => (int) $targetUser->id,
        ]);

        $_SESSION['success'] = 'Mitglied wurde erfolgreich wiederhergestellt.';
        return $response->withHeader('Location', '/users?archived=1')->withStatus(302);
    }

    private function buildProjectParticipations(User $user): array
    {
        return $user->projects
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(function (Project $project): array {
                return [
                    'name' => (string) $project->name,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Highest role hierarchy level currently held by the target user.
     */
    private function targetHierarchyLevel(User $targetUser): int
    {
        $max = 0;
        foreach ($targetUser->roles as $role) {
            $level = (int) ($role->hierarchy_level ?? 0);
            if ($level > $max) {
                $max = $level;
            }
        }

        return $max;
    }

    /**
     * True when the target user holds a role that outranks the acting user's own level.
     */
    private function outranksActor(User $targetUser): bool
    {
        $actorLevel = (int) ($_SESSION['role_level'] ?? 0);

        return $this->targetHierarchyLevel($targetUser) > $actorLevel;
    }

    /**
     * Restrict the given role ids to those at or below the acting user's hierarchy level,
     * preventing privilege escalation by assigning roles that outrank the actor.
     *
     * @param array<int|string> $roleIds
     * @return array<int>
     */
    private function capRoleIdsToActorLevel(array $roleIds): array
    {
        $actorLevel = (int) ($_SESSION['role_level'] ?? 0);

        $allowedIds = Role::where('hierarchy_level', '<=', $actorLevel)
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->all();

        return array_values(array_intersect(
            array_map('intval', $roleIds),
            $allowedIds
        ));
    }

    private function canDeactivateTargetUser(User $targetUser): bool
    {
        if ($this->isLastUserManager($targetUser)) {
            return false;
        }

        return $this->canArchiveTargetUser($targetUser);
    }

    /**
     * Archivieren und Wiederherstellen sind dieselbe Befugnis in zwei Richtungen.
     * Waere nur das Archivieren erlaubt, koennte ein Stimmgruppen-Verwalter ein
     * Mitglied stilllegen, aber nicht mehr zurueckholen.
     */
    private function canArchiveTargetUser(User $targetUser): bool
    {
        if ($this->outranksActor($targetUser)) {
            return false;
        }

        $canEditGlobal = (bool) ($_SESSION['can_edit_users'] ?? false);
        if ($canEditGlobal) {
            return true;
        }

        $canManageOwnVoiceGroup = (bool) ($_SESSION['can_manage_own_voice_group'] ?? false);
        $myVgs = $_SESSION['voice_group_ids'] ?? [];
        $targetVgIds = $targetUser->voiceGroups->pluck('id')->toArray();
        $isInMyGroup = !empty(array_intersect($myVgs, $targetVgIds));

        return $canManageOwnVoiceGroup && $isInMyGroup;
    }

    /**
     * Ist dieses Mitglied das letzte aktive, das ueber eine Rolle die
     * Mitgliederverwaltung haelt?
     */
    private function isLastUserManager(User $targetUser): bool
    {
        if (!$this->holdsUserManagement($targetUser->roles->pluck('id')->all())) {
            return false;
        }

        return !$this->otherActiveUserManagerExists((int) $targetUser->id);
    }

    /**
     * Wuerde dieser Rollensatz dem letzten Mitgliederverwalter sein Recht nehmen?
     *
     * @param array<int|string> $newRoleIds
     */
    private function wouldDropLastUserManager(User $targetUser, array $newRoleIds): bool
    {
        if (!$this->isLastUserManager($targetUser)) {
            return false;
        }

        return !$this->holdsUserManagement($newRoleIds);
    }

    /**
     * @param array<int|string> $roleIds
     */
    private function holdsUserManagement(array $roleIds): bool
    {
        $ids = array_values(array_filter(array_map('intval', $roleIds)));
        if ($ids === []) {
            return false;
        }

        return Role::whereIn('id', $ids)->where('can_manage_users', 1)->exists();
    }

    private function otherActiveUserManagerExists(int $exceptUserId): bool
    {
        return User::where('is_active', 1)
            ->where('id', '!=', $exceptUserId)
            ->whereHas('roles', static function ($query): void {
                $query->where('can_manage_users', 1);
            })
            ->exists();
    }

    public function invite(Request $request, Response $response, array $args): Response
    {
        $canManageUsers = $_SESSION['can_manage_users'] ?? false;

        $userId = (int) $args['id'];
        $targetUser = $this->userQuery->findById($userId);

        if (!$targetUser) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Nutzer nicht gefunden.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        if ($this->outranksActor($targetUser)) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Keine Berechtigung.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        // Die Einladung setzt das Passwort des Zielkontos neu. Zusammen mit einer
        // aenderbaren Adresse waere das ein Uebernahmepfad; deshalb bleibt sie der
        // Mitgliederverwaltung vorbehalten, so wie die Adresse selbst an
        // can_edit_users haengt (siehe UserEditPolicy::canEditEmail()).
        if (!$canManageUsers) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Keine Berechtigung.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $inviteResult = $this->sendInvitationEmail($targetUser, $request);
        if (!$inviteResult['success']) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => $inviteResult['message'] ?? 'Fehler beim Senden der E-Mail.',
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Einladungs-E-Mail wurde gesendet.',
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    private function sendInvitationEmail(User $targetUser, Request $request): array
    {
        try {
            $token = bin2hex(random_bytes(32));

            InvitationToken::where('user_id', $targetUser->id)->delete();

            InvitationToken::create([
                'user_id'    => $targetUser->id,
                'selector'   => bin2hex(random_bytes(9)),
                'token_hash' => password_hash($token, PASSWORD_DEFAULT),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $appUrl = AppUrlResolver::resolveBaseUrl($request);
            $inviteLink = $appUrl . '/reset-password?token=' . $token . '&email=' . urlencode($targetUser->email);
            $branding = $this->resolveInvitationBranding();
            $handoff = InvitationHandoffLink::resolve();

            if ($handoff === null && InvitationHandoffLink::isMisconfigured()) {
                $this->logger->warning('Invitation handoff link ignored: URL is not an absolute http(s) URL.', [
                    'event' => 'invitation.handoff_link.invalid',
                    'env_key' => InvitationHandoffLink::ENV_URL,
                ]);
            }

            $htmlBody = $this->view->fetch('emails/invitation.twig', [
                'user'        => $targetUser,
                'invite_link' => $inviteLink,
                'app_name' => $branding['app_name'],
                'primary_color' => $branding['primary_color'],
                'primary_strong' => $branding['primary_strong'],
                'primary_tint' => $branding['primary_tint'],
                'primary_edge' => $branding['primary_edge'],
                'logo_src' => $branding['logo_src'],
                'handoff_url' => $handoff['url'] ?? '',
                'handoff_label' => $handoff['label'] ?? '',
            ]);

            $this->mailQueueService->enqueueInvitationMail(
                recipientEmail: $targetUser->email,
                subject: 'Einladung zu ' . $branding['app_name'],
                bodyHtml: $htmlBody,
                userId: (int) $targetUser->id,
                invitationToken: $token
            );

            $this->logger->info('Invitation created.', [
                'event' => 'invitation.created',
                'user_id' => (int) $targetUser->id,
            ]);

            return [
                'success' => true,
                'message' => 'Einladungs-E-Mail wurde zur Queue hinzugefügt.',
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Invitation email enqueue failed.',
                [
                    'event' => 'user.invitation.failed',
                    'user_id' => (int) $targetUser->id,
                    'recipient_email' => (string) $targetUser->email,
                    'exception' => $e,
                ]
            );
            return [
                'success' => false,
                'message' => 'Fehler beim Senden der E-Mail.',
            ];
        }
    }

    /**
     * Die Aufloesung liegt in MailBranding, damit alle Systemmails dieselbe Quelle nutzen.
     *
     * @return array{
     *     app_name: string,
     *     primary_color: string,
     *     primary_strong: string,
     *     primary_tint: string,
     *     primary_edge: string,
     *     logo_src: string
     * }
     */
    private function resolveInvitationBranding(): array
    {
        return MailBranding::resolve();
    }
}
