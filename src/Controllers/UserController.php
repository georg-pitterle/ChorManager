<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Queries\UserQuery;
use App\Queries\ProjectQuery;
use App\Persistence\UserPersistence;
use App\Persistence\ProjectPersistence;
use App\Models\User;
use App\Models\Role;
use App\Models\VoiceGroup;
use App\Models\SubVoice;
use App\Models\Project;
use App\Services\PasswordPolicyService;
use App\Services\ModalFormService;
use App\Models\AppSetting;
use App\Models\InvitationToken;
use App\Services\Mailer;
use App\Services\MailQueueService;
use App\Util\AppUrlResolver;
use Psr\Log\LoggerInterface;

class UserController
{
    private Twig $view;
    private UserQuery $userQuery;
    private ProjectQuery $projectQuery;
    private UserPersistence $userPersistence;
    private ProjectPersistence $projectPersistence;
    private PasswordPolicyService $passwordPolicyService;
    private MailQueueService $mailQueueService;
    private LoggerInterface $logger;

    public function __construct(
        Twig $view,
        UserQuery $userQuery,
        ProjectQuery $projectQuery,
        UserPersistence $userPersistence,
        ProjectPersistence $projectPersistence,
        PasswordPolicyService $passwordPolicyService,
        MailQueueService $mailQueueService,
        LoggerInterface $logger
    ) {
        $this->view = $view;
        $this->userQuery = $userQuery;
        $this->projectQuery = $projectQuery;
        $this->userPersistence = $userPersistence;
        $this->projectPersistence = $projectPersistence;
        $this->passwordPolicyService = $passwordPolicyService;
        $this->mailQueueService = $mailQueueService;
        $this->logger = $logger;
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
        $subVoices = SubVoice::orderBy('id')->get();
        $projects = Project::orderBy('name')->get();

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

        // Get all edit form states
        $editStates = [];
        foreach ($users as $user) {
            $editService = new ModalFormService('user_edit_' . $user->id);
            $editStates[$user->id] = $editService->getState();
            $editService->clear();
        }

        $hasModalError = $createState['open_modal']
            || !empty(array_filter($editStates, fn($s) => $s['open_modal']));

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
            'modal_form_edits' => $editStates
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

            $this->userPersistence->syncRoles($user, $roleIds);

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
                    'exception' => $e,
                ]
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
        $isInMyGroup = !empty(array_intersect($myVgs, $targetVgIds));

        // Nobody may modify a member who outranks them in the role hierarchy - not even global
        // user managers. This prevents lower-ranked admins from hijacking higher-ranked accounts
        // (e.g. resetting an Obmann's password or e-mail from a lower administrative role).
        if ($this->outranksActor($targetUser)) {
            $_SESSION['error'] = 'Du hast keine Berechtigung, dieses Mitglied zu bearbeiten.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $canManageProjectMembers = $_SESSION['can_manage_project_members'] ?? false;
        if (!$canEditGlobal && !$canManageProjectMembers) {
            if ($userLevel < 40 || !$isInMyGroup) {
                $_SESSION['error'] = 'Du hast keine Berechtigung, dieses Mitglied zu bearbeiten.';
                return $response->withHeader('Location', '/users')->withStatus(302);
            }
        }

        $data = (array) $request->getParsedBody();
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        $roleIds = $data['roles'] ?? [];
        $voiceGroupIds = $data['voice_groups'] ?? [];
        $subVoices = $data['sub_voices'] ?? [];
        $projectIds = array_values(array_filter(
            array_map('intval', (array) ($data['projects'] ?? [])),
            fn(int $id): bool => $id > 0
        ));

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

        if (!$firstName || !$lastName || !$email || empty($roleIds)) {
            $editService = new ModalFormService('user_edit_' . $userId);
            $editService->setError('Bitte fülle alle Pflichtfelder aus (inkl. mind. einer Rolle).', $formData);
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        // Email uniqueness check (excluding self)
        if (User::where('email', $email)->where('id', '!=', $userId)->exists()) {
            $editService = new ModalFormService('user_edit_' . $userId);
            $editService->setError('Diese E-Mail-Adresse wird bereits verwendet.', $formData);
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        if ($password !== '') {
            $passwordError = $this->passwordPolicyService->validate($password);
            if ($passwordError !== null) {
                $editService = new ModalFormService('user_edit_' . $userId);
                $editService->setError($passwordError, $formData);
                return $response->withHeader('Location', '/users')->withStatus(302);
            }
        }

        try {
            if ($password) {
                $targetUser->password = password_hash($password, PASSWORD_DEFAULT);
            }
            $targetUser->first_name = $firstName;
            $targetUser->last_name = $lastName;
            $targetUser->email = $email;


            $this->userPersistence->save($targetUser);
            $this->userPersistence->syncRoles($targetUser, $roleIds);

            // Admin can override everything
            if ($canManageUsers) {
                $finalVgIds = (array) $voiceGroupIds;
            } else {
                // Non-admins can only touch their own groups, keep others
                $unmanageableVgs = array_diff($targetVgIds, $myVgs);
                $finalVgIds = array_merge(array_intersect((array) $voiceGroupIds, $myVgs), $unmanageableVgs);

                // Add subvoices for unmanageable groups
                foreach ($unmanageableVgs as $uVg) {
                    $vgPivot = $targetUser->voiceGroups->firstWhere('id', $uVg);
                    if ($vgPivot && $vgPivot->pivot->sub_voice_id) {
                        $subVoices[$uVg] = $vgPivot->pivot->sub_voice_id;
                    }
                }
            }

            $vgData = [];
            foreach ($finalVgIds as $vgId) {
                $svId = !empty($subVoices[$vgId]) ? (int) $subVoices[$vgId] : null;
                $vgData[$vgId] = ['sub_voice_id' => $svId];
            }


            $this->userPersistence->syncVoiceGroups($targetUser, $vgData);

            if ($canEditGlobal || $canManageProjectMembers) {
                if (!$canEditGlobal && $canManageProjectMembers) {
                    $policy = new \App\Policies\ProjectMemberPolicy();
                    $accessibleIds = $policy->getAccessibleProjectIds();
                    $projectIds = array_values(array_filter(
                        $projectIds,
                        fn(int $id): bool => in_array($id, $accessibleIds, true)
                    ));
                }

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
            $editService->setError('Fehler beim Speichern.', $formData);
        }

        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    public function deactivate(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];

        if ($userId === (int) ($_SESSION['user_id'] ?? 0)) {
            $_SESSION['error'] = 'Du kannst deinen eigenen Account nicht deaktivieren.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $canEditGlobal = $_SESSION['can_edit_users'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;
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
            if ($userLevel < 40 || !$isInMyGroup) {
                $_SESSION['error'] = 'Du hast keine Berechtigung, dieses Mitglied zu deaktivieren.';
                return $response->withHeader('Location', '/users')->withStatus(302);
            }
        }

        $targetUser->is_active = 0;
        $this->userPersistence->save($targetUser);

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
        $canManageUsers = $_SESSION['can_manage_users'] ?? false;
        if (!$canManageUsers) {
            $_SESSION['error'] = 'Du hast keine Berechtigung, Mitglieder wiederherzustellen.';
            return $response->withHeader('Location', '/users?archived=1')->withStatus(302);
        }

        $userId = (int) $args['id'];
        $targetUser = $this->userQuery->findById($userId);

        if (!$targetUser || (bool) $targetUser->is_active) {
            $_SESSION['error'] = 'Mitglied nicht gefunden oder bereits aktiv.';
            return $response->withHeader('Location', '/users?archived=1')->withStatus(302);
        }

        if ($this->outranksActor($targetUser)) {
            $_SESSION['error'] = 'Du hast keine Berechtigung, dieses Mitglied wiederherzustellen.';
            return $response->withHeader('Location', '/users?archived=1')->withStatus(302);
        }

        $targetUser->is_active = 1;
        $this->userPersistence->save($targetUser);

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
        if ($this->outranksActor($targetUser)) {
            return false;
        }

        $canEditGlobal = (bool) ($_SESSION['can_edit_users'] ?? false);
        if ($canEditGlobal) {
            return true;
        }

        $userLevel = (int) ($_SESSION['role_level'] ?? 0);
        $myVgs = $_SESSION['voice_group_ids'] ?? [];
        $targetVgIds = $targetUser->voiceGroups->pluck('id')->toArray();
        $isInMyGroup = !empty(array_intersect($myVgs, $targetVgIds));

        return $userLevel >= 40 && $isInMyGroup;
    }

    public function invite(Request $request, Response $response, array $args): Response
    {
        $canManageUsers = $_SESSION['can_manage_users'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;
        $myVgs = $_SESSION['voice_group_ids'] ?? [];

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

        if (!$canManageUsers) {
            $targetVgIds = $targetUser->voiceGroups->pluck('id')->toArray();
            $isInMyGroup = !empty(array_intersect($myVgs, $targetVgIds));
            if ($userLevel < 40 || !$isInMyGroup) {
                $response->getBody()->write(json_encode(['success' => false, 'message' => 'Keine Berechtigung.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }
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

            $htmlBody = $this->view->fetch('emails/invitation.twig', [
                'user'        => $targetUser,
                'invite_link' => $inviteLink,
                'app_name' => $branding['app_name'],
                'primary_color' => $branding['primary_color'],
                'logo_src' => $branding['logo_src'],
            ]);

            $this->mailQueueService->enqueueInvitationMail(
                recipientEmail: $targetUser->email,
                subject: 'Einladung zu ' . $branding['app_name'],
                bodyHtml: $htmlBody,
                userId: (int) $targetUser->id,
                invitationToken: $token
            );

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
     * @return array{app_name: string, primary_color: string, logo_src: string}
     */
    private function resolveInvitationBranding(): array
    {
        $appName = 'Chor-Manager';
        $primaryColor = AppSettingController::DEFAULT_PRIMARY_COLOR;
        $logoSrc = $this->buildDefaultInvitationLogoDataUri();

        try {
            $settings = AppSetting::query()
                ->whereIn('setting_key', ['app_name', 'primary_color'])
                ->pluck('setting_value', 'setting_key')
                ->toArray();

            $configuredAppName = trim((string) ($settings['app_name'] ?? ''));
            if ($configuredAppName !== '') {
                $appName = $configuredAppName;
            }

            $primaryColor = AppSettingController::normalizePrimaryColor($settings['primary_color'] ?? null);
        } catch (\Throwable $e) {
            $primaryColor = AppSettingController::DEFAULT_PRIMARY_COLOR;
        }

        try {
            $logo = AppSetting::query()->find('app_logo');
            if ($logo instanceof AppSetting && $logo->binary_content !== '') {
                $mimeType = trim((string) $logo->mime_type);
                if ($mimeType === '') {
                    $mimeType = 'image/png';
                }

                $logoSrc = 'data:' . $mimeType . ';base64,' . base64_encode($logo->binary_content);
            }
        } catch (\Throwable $e) {
            $logoSrc = $this->buildDefaultInvitationLogoDataUri();
        }

        return [
            'app_name' => $appName,
            'primary_color' => $primaryColor,
            'logo_src' => $logoSrc,
        ];
    }

    private function buildDefaultInvitationLogoDataUri(): string
    {
        $defaultLogoPath = __DIR__ . '/../../public/icons/icon-512.png';
        if (!is_file($defaultLogoPath)) {
            return '';
        }

        $binaryContent = file_get_contents($defaultLogoPath);
        if ($binaryContent === false || $binaryContent === '') {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($binaryContent);
    }
}
