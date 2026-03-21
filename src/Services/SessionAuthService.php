<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class SessionAuthService
{
    public function setAuthenticatedUser(User $user): void
    {
        $_SESSION['user_id'] = (int) $user->id;
        $_SESSION['user_name'] = $user->first_name . ' ' . $user->last_name;

        $canManageUsers = false;
        $canEditUsers = false;
        $canManageProjectMembers = false;
        $canManageFinances = false;
        $canManageMasterData = false;
        $maxRoleLevel = 0;

        foreach ($user->roles as $role) {
            if ($role->hierarchy_level >= 80) {
                $canManageUsers = true;
                $canEditUsers = true;
                $canManageProjectMembers = true;
            }

            if ($role->can_manage_users) {
                $canManageUsers = true;
            }
            if ($role->can_edit_users) {
                $canEditUsers = true;
            }
            if ($role->can_manage_project_members) {
                $canManageProjectMembers = true;
            }
            if ($role->can_manage_finances) {
                $canManageFinances = true;
            }
            if ($role->can_manage_master_data) {
                $canManageMasterData = true;
            }

            if ($role->hierarchy_level > $maxRoleLevel) {
                $maxRoleLevel = (int) $role->hierarchy_level;
            }
        }

        $_SESSION['can_manage_users'] = $canManageUsers;
        $_SESSION['can_edit_users'] = $canEditUsers;
        $_SESSION['can_manage_project_members'] = $canManageProjectMembers;
        $_SESSION['can_manage_finances'] = $canManageFinances;
        $_SESSION['can_manage_master_data'] = $canManageMasterData;
        $_SESSION['role_level'] = $maxRoleLevel;
        $_SESSION['voice_group_ids'] = $user->voiceGroups->pluck('id')->toArray();
    }

    public function clearSession(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => (bool) $params['secure'],
                    'httponly' => (bool) $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax'
                ]
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
