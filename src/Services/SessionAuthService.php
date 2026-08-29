<?php

declare(strict_types=1);

namespace App\Services;

use App\Logging\RequestContext;
use App\Models\User;

class SessionAuthService
{
    private NameFormatterService $nameFormatter;
    private RequestContext $requestContext;

    public function __construct(NameFormatterService $nameFormatter, RequestContext $requestContext)
    {
        $this->nameFormatter = $nameFormatter;
        $this->requestContext = $requestContext;
    }

    public function setAuthenticatedUser(User $user): void
    {
        $_SESSION['user_id'] = (int) $user->id;
        $_SESSION['user_name'] = $this->nameFormatter->formatPerson($user);
        // Einziger Ort, an dem die Session-Benutzerkennung gesetzt wird (Login,
        // Remember-Me-Wiederherstellung, Rechte-Refresh). Der Logging-Kontext wird
        // hier mitgezogen, damit alle Folge-Logzeilen die user_id tragen.
        $this->requestContext->setUserId((int) $user->id);

        $canManageUsers = false;
        $canManageRoles = false;
        $canEditUsers = false;
        $canManageAttendance = false;
        $canManageAttendanceAll = false;
        $canManageEvents = false;
        $canManageProjectMembers = false;
        $canReadFinances = false;
        $canManageFinances = false;
        $canManageMasterData = false;
        $canManageSponsoring = false;
        $canCreateOwnSponsorships = false;
        $canManageSongLibrary = false;
        $canManageNewsletters = false;
        $canManageMailQueue = false;
        $canManageSheetArchive = false;
        $canManageBudget = false;
        $canManageTasks = false;
        $canManageBackups = false;
        $canManageOwnVoiceGroup = false;
        $canAssignOwnVoiceGroupToProject = false;
        $maxRoleLevel = 0;

        // Das Hierarchie-Level vergibt bewusst keine Rechte. Es entscheidet ausschliesslich
        // darueber, wessen Zuordnungen ein Mitglied noch aendern darf (siehe UserController
        // und RoleController) - jedes Recht muss einzeln an der Rolle gesetzt sein.
        foreach ($user->roles as $role) {
            if ($role->can_manage_users) {
                $canManageUsers = true;
            }
            if (($role->can_manage_roles ?? false)) {
                $canManageRoles = true;
            }
            if ($role->can_edit_users) {
                $canEditUsers = true;
            }
            if ($role->can_manage_attendance) {
                $canManageAttendance = true;
            }
            if (($role->can_manage_attendance_all ?? false)) {
                $canManageAttendanceAll = true;
            }
            if (($role->can_manage_events ?? false)) {
                $canManageEvents = true;
            }
            if ($role->can_manage_project_members) {
                $canManageProjectMembers = true;
            }
            if ($role->can_read_finances) {
                $canReadFinances = true;
            }
            if ($role->can_manage_finances) {
                $canReadFinances = true;
                $canManageFinances = true;
            }
            if ($role->can_manage_master_data) {
                $canManageMasterData = true;
            }
            if ($role->can_manage_sponsoring) {
                $canManageSponsoring = true;
                // Das Vollrecht schliesst das kleinere Recht ein, wie
                // can_manage_finances das Lesen der Finanzen einschliesst.
                $canCreateOwnSponsorships = true;
            }
            if (($role->can_create_own_sponsorships ?? false)) {
                $canCreateOwnSponsorships = true;
            }
            if ($role->can_manage_song_library) {
                $canManageSongLibrary = true;
            }
            if ($role->can_manage_newsletters) {
                $canManageNewsletters = true;
            }
            if ($role->can_manage_mail_queue) {
                $canManageMailQueue = true;
            }
            if (($role->can_manage_sheet_archive ?? false)) {
                $canManageSheetArchive = true;
            }
            if (($role->can_manage_budget ?? false)) {
                $canManageBudget = true;
            }
            if ($role->can_manage_tasks) {
                $canManageTasks = true;
            }
            if (($role->can_manage_backups ?? false)) {
                $canManageBackups = true;
            }
            if (($role->can_manage_own_voice_group ?? false)) {
                $canManageOwnVoiceGroup = true;
            }
            if (($role->can_assign_own_voice_group_to_project ?? false)) {
                $canAssignOwnVoiceGroupToProject = true;
            }

            if ($role->hierarchy_level > $maxRoleLevel) {
                $maxRoleLevel = (int) $role->hierarchy_level;
            }
        }

        $_SESSION['can_manage_users'] = $canManageUsers;
        $_SESSION['can_manage_roles'] = $canManageRoles;
        $_SESSION['can_edit_users'] = $canEditUsers;
        $_SESSION['can_manage_attendance'] = $canManageAttendance;
        $_SESSION['can_manage_attendance_all'] = $canManageAttendanceAll;
        $_SESSION['can_manage_events'] = $canManageEvents;
        $_SESSION['can_manage_project_members'] = $canManageProjectMembers;
        $_SESSION['can_read_finances'] = $canReadFinances;
        $_SESSION['can_manage_finances'] = $canManageFinances;
        $_SESSION['can_manage_master_data'] = $canManageMasterData;
        $_SESSION['can_manage_sponsoring'] = $canManageSponsoring;
        $_SESSION['can_create_own_sponsorships'] = $canCreateOwnSponsorships;
        $_SESSION['can_manage_song_library'] = $canManageSongLibrary;
        $_SESSION['can_manage_newsletters'] = $canManageNewsletters;
        $_SESSION['can_manage_mail_queue'] = $canManageMailQueue;
        $_SESSION['can_manage_sheet_archive'] = $canManageSheetArchive;
        $_SESSION['can_manage_budget'] = $canManageBudget;
        $_SESSION['can_manage_tasks'] = $canManageTasks;
        $_SESSION['can_manage_backups'] = $canManageBackups;
        $_SESSION['can_manage_own_voice_group'] = $canManageOwnVoiceGroup;
        $_SESSION['can_assign_own_voice_group_to_project'] = $canAssignOwnVoiceGroupToProject;
        $_SESSION['role_level'] = $maxRoleLevel;
        $_SESSION['voice_group_ids'] = $user->voiceGroups->pluck('id')->toArray();

        if (!isset($_SESSION['auth_epoch'])) {
            $_SESSION['auth_epoch'] = time();
        }
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
