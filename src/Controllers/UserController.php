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

class UserController
{
    private Twig $view;
    private UserQuery $userQuery;
    private ProjectQuery $projectQuery;
    private UserPersistence $userPersistence;
    private ProjectPersistence $projectPersistence;

    public function __construct(
        Twig $view,
        UserQuery $userQuery,
        ProjectQuery $projectQuery,
        UserPersistence $userPersistence,
        ProjectPersistence $projectPersistence
    ) {
        $this->view = $view;
        $this->userQuery = $userQuery;
        $this->projectQuery = $projectQuery;
        $this->userPersistence = $userPersistence;
        $this->projectPersistence = $projectPersistence;
    }

    public function index(Request $request, Response $response): Response
    {
        $canManageUsers = $_SESSION['can_manage_users'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;
        $myVgs = $_SESSION['voice_group_ids'] ?? [];
        $canEditGlobal = $_SESSION['can_edit_users'] ?? false;

        $users = $this->userQuery->getAllUsers();

        if (!$canManageUsers) {
            if (empty($myVgs)) {
                $users = collect(); // empty eloquent collection
            } else {
                $users = $users->filter(function ($user) use ($myVgs) {
                    $uVgIds = $user->voiceGroups->pluck('id')->toArray();
                    return !empty(array_intersect($myVgs, $uVgIds));
                });
            }
        }

        $roles = Role::orderBy('hierarchy_level', 'desc')->get();
        $voiceGroups = VoiceGroup::orderBy('id')->get();
        $subVoices = SubVoice::orderBy('id')->get();
        $projects = Project::orderBy('name')->get();

        foreach ($users as $user) {
            $user->project_ids = $user->projects->pluck('id')->toArray();
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

        return $this->view->render($response, 'users/manage.twig', [
            'users' => $users,
            'roles' => $roles,
            'voice_groups' => $voiceGroups,
            'sub_voices' => $subVoices,
            'projects' => $projects,
            'can_edit_users' => $canEditUsers,
            'can_manage_project_members' => $canManageProjectMembers,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        $roleIds = $data['roles'] ?? [];
        $voiceGroupIds = $data['voice_groups'] ?? [];
        $subVoices = $data['sub_voices'] ?? [];

        $canManageUsers = $_SESSION['can_manage_users'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;
        $myVgs = $_SESSION['voice_group_ids'] ?? [];

        if (!$canManageUsers) {
            $allowedRoles = Role::where('hierarchy_level', '<', $userLevel)->pluck('id')->toArray();
            $roleIds = array_intersect((array) $roleIds, $allowedRoles);

            $voiceGroupIds = array_intersect((array) $voiceGroupIds, $myVgs);
            if (empty($voiceGroupIds)) {
                $_SESSION['error'] = 'Du musst mindestens eine deiner Stimmgruppen zuweisen.';
                return $response->withHeader('Location', '/users')->withStatus(302);
            }
        }

        if (!$firstName || !$lastName || !$email || !$password || empty($roleIds)) {
            $_SESSION['error'] = 'Bitte fülle alle Pflichtfelder aus (inkl. mind. einer Rolle).';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        if (User::where('email', $email)->exists()) {
            $_SESSION['error'] = 'Diese E-Mail-Adresse wird bereits verwendet.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        try {
            $user = new User();
            $user->first_name = $firstName;
            $user->last_name = $lastName;
            $user->email = $email;
            $user->password = password_hash($password, PASSWORD_DEFAULT);
            $user->is_active = 1;

            $this->userPersistence->save($user);

            $this->userPersistence->syncRoles($user, $roleIds);

            $vgData = [];
            foreach ($voiceGroupIds as $vgId) {
                $svId = !empty($subVoices[$vgId]) ? (int) $subVoices[$vgId] : null;
                $vgData[$vgId] = ['sub_voice_id' => $svId];
            }
            $this->userPersistence->syncVoiceGroups($user, $vgData);

            $_SESSION['success'] = 'Mitglied erfolgreich angelegt.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Anlegen des Mitglieds: ' . $e->getMessage();
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
        $projectIds = array_filter((array) ($data['projects'] ?? []), fn($id) => (int) $id > 0);

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

        if (!$firstName || !$lastName || !$email || empty($roleIds)) {
            $_SESSION['error'] = 'Bitte fülle alle Pflichtfelder aus (inkl. mind. einer Rolle).';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        // Email uniqueness check (excluding self)
        if (User::where('email', $email)->where('id', '!=', $userId)->exists()) {
            $_SESSION['error'] = 'Diese E-Mail-Adresse wird bereits verwendet.';
            return $response->withHeader('Location', '/users')->withStatus(302);
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
                $this->projectPersistence->setUserProjects($userId, $projectIds);
            }

            $_SESSION['success'] = 'Mitglied erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Speichern: ' . $e->getMessage();
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
}
