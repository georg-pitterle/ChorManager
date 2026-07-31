<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Role;

class RoleController
{
    /**
     * Permissions whose form controls only exist while their module is active.
     *
     * @var array<string,string>
     */
    private const MODULE_GATED_PERMISSIONS = [
        'can_read_finances' => 'finance',
        'can_manage_finances' => 'finance',
        'can_manage_budget' => 'budget',
        'can_manage_sponsoring' => 'sponsoring',
        'can_manage_newsletters' => 'newsletter',
        'can_manage_sheet_archive' => 'sheet_archive',
        'can_manage_tasks' => 'tasks',
    ];

    private Twig $view;
    private array $settings;

    public function __construct(Twig $view, array $settings = [])
    {
        $this->view = $view;
        $this->settings = $settings;
    }

    /**
     * @param array<string,mixed> $data submitted form data
     * @param array<string,bool>|null $modules active module flags, null disables module gating
     * @param array<string,mixed> $existing current permission values of the edited role
     * @return array<string,int>
     */
    public static function buildPermissionFlags(
        array $data,
        ?array $modules = null,
        array $existing = []
    ): array {
        $canReadFinances = isset($data['can_read_finances']) || isset($data['can_manage_finances']);

        $flags = [
            'can_manage_users' => isset($data['can_manage_users']) ? 1 : 0,
            'can_manage_roles' => isset($data['can_manage_roles']) ? 1 : 0,
            'can_edit_users' => isset($data['can_edit_users']) ? 1 : 0,
            'can_manage_attendance' => isset($data['can_manage_attendance']) ? 1 : 0,
            'can_manage_attendance_all' => isset($data['can_manage_attendance_all']) ? 1 : 0,
            'can_manage_events' => isset($data['can_manage_events']) ? 1 : 0,
            'can_manage_project_members' => isset($data['can_manage_project_members']) ? 1 : 0,
            'can_read_finances' => $canReadFinances ? 1 : 0,
            'can_manage_finances' => isset($data['can_manage_finances']) ? 1 : 0,
            'can_manage_master_data' => isset($data['can_manage_master_data']) ? 1 : 0,
            'can_manage_sponsoring' => isset($data['can_manage_sponsoring']) ? 1 : 0,
            'can_manage_song_library' => isset($data['can_manage_song_library']) ? 1 : 0,
            'can_manage_newsletters' => isset($data['can_manage_newsletters']) ? 1 : 0,
            'can_manage_mail_queue' => isset($data['can_manage_mail_queue']) ? 1 : 0,
            'can_manage_sheet_archive' => isset($data['can_manage_sheet_archive']) ? 1 : 0,
            'can_manage_budget' => isset($data['can_manage_budget']) && $data['can_manage_budget'] === '1' ? 1 : 0,
            'can_manage_tasks' => isset($data['can_manage_tasks']) ? 1 : 0,
            'can_manage_backups' => isset($data['can_manage_backups']) ? 1 : 0,
            'can_manage_own_voice_group' => isset($data['can_manage_own_voice_group']) ? 1 : 0,
        ];

        if ($modules === null) {
            return $flags;
        }

        // A permission belonging to an inactive module has no checkbox in the form, so every
        // save would submit it as absent and silently clear the right. Keep the stored value
        // instead and ignore anything submitted for it - the field can only be forged.
        foreach (self::MODULE_GATED_PERMISSIONS as $permission => $module) {
            if ((bool) ($modules[$module] ?? false)) {
                continue;
            }

            $flags[$permission] = (int) ($existing[$permission] ?? 0);
        }

        return $flags;
    }

    /**
     * @return array<string,bool>
     */
    private function moduleFlags(): array
    {
        $modules = $this->settings['modules'] ?? [];

        return is_array($modules) ? $modules : [];
    }

    public function index(Request $request, Response $response): Response
    {
        $roles = Role::withCount([
            'users as active_users_count' => function ($query) {
                $query->where('is_active', 1);
            },
            // Fuer das Loeschen zaehlt jede Zuweisung, auch die eines archivierten Mitglieds.
            'users as assigned_users_count',
        ])->orderBy('hierarchy_level', 'desc')->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'roles/index.twig', [
            'roles' => $roles,
            'success' => $success,
            'error' => $error,
            'role_create_action' => '/roles',
            'role_edit_action' => '/roles/0'
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $hierarchyLevel = (int) ($data['hierarchy_level'] ?? 0);
        $permissions = self::buildPermissionFlags($data, $this->moduleFlags());

        if (!$name) {
            $_SESSION['error'] = 'Der Rollenname darf nicht leer sein.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        $actorLevel = (int) ($_SESSION['role_level'] ?? 0);
        if ($hierarchyLevel > $actorLevel) {
            $_SESSION['error'] = 'Du kannst keine Rolle oberhalb deines eigenen Levels anlegen.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        try {
            Role::create([
                'name' => $name,
                'hierarchy_level' => $hierarchyLevel,
                'can_manage_users' => $permissions['can_manage_users'],
                'can_manage_roles' => $permissions['can_manage_roles'],
                'can_edit_users' => $permissions['can_edit_users'],
                'can_manage_attendance' => $permissions['can_manage_attendance'],
                'can_manage_attendance_all' => $permissions['can_manage_attendance_all'],
                'can_manage_events' => $permissions['can_manage_events'],
                'can_manage_project_members' => $permissions['can_manage_project_members'],
                'can_read_finances' => $permissions['can_read_finances'],
                'can_manage_finances' => $permissions['can_manage_finances'],
                'can_manage_master_data' => $permissions['can_manage_master_data'],
                'can_manage_sponsoring' => $permissions['can_manage_sponsoring'],
                'can_manage_song_library' => $permissions['can_manage_song_library'],
                'can_manage_newsletters' => $permissions['can_manage_newsletters'],
                'can_manage_mail_queue' => $permissions['can_manage_mail_queue'],
                'can_manage_sheet_archive' => $permissions['can_manage_sheet_archive'],
                'can_manage_budget' => $permissions['can_manage_budget'],
                'can_manage_tasks' => $permissions['can_manage_tasks'],
                'can_manage_backups' => $permissions['can_manage_backups'],
                'can_manage_own_voice_group' => $permissions['can_manage_own_voice_group']
            ]);
            $_SESSION['success'] = 'Rolle erfolgreich angelegt.';
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) {
                $_SESSION['error'] = 'Eine Rolle mit diesem Namen existiert bereits.';
            } else {
                $_SESSION['error'] = 'Datenbankfehler: ';
            }
        }

        return $response->withHeader('Location', '/roles')->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $roleId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $hierarchyLevel = (int) ($data['hierarchy_level'] ?? 0);

        if (!$name) {
            $_SESSION['error'] = 'Der Rollenname darf nicht leer sein.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        $existingRole = Role::find($roleId);
        if (!$existingRole) {
            $_SESSION['error'] = 'Rolle nicht gefunden.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        $permissions = self::buildPermissionFlags(
            $data,
            $this->moduleFlags(),
            $existingRole->getAttributes()
        );

        // A user administrator may neither modify a role that already outranks their own
        // hierarchy level nor lift a role above it - both would be a privilege escalation.
        $actorLevel = (int) ($_SESSION['role_level'] ?? 0);
        if ((int) $existingRole->hierarchy_level > $actorLevel || $hierarchyLevel > $actorLevel) {
            $_SESSION['error'] = 'Du kannst keine Rolle oberhalb deines eigenen Levels bearbeiten.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        try {
            $role = $existingRole;
            $role->update([
                'name' => $name,
                'hierarchy_level' => $hierarchyLevel,
                'can_manage_users' => $permissions['can_manage_users'],
                'can_manage_roles' => $permissions['can_manage_roles'],
                'can_edit_users' => $permissions['can_edit_users'],
                'can_manage_attendance' => $permissions['can_manage_attendance'],
                'can_manage_attendance_all' => $permissions['can_manage_attendance_all'],
                'can_manage_events' => $permissions['can_manage_events'],
                'can_manage_project_members' => $permissions['can_manage_project_members'],
                'can_read_finances' => $permissions['can_read_finances'],
                'can_manage_finances' => $permissions['can_manage_finances'],
                'can_manage_master_data' => $permissions['can_manage_master_data'],
                'can_manage_sponsoring' => $permissions['can_manage_sponsoring'],
                'can_manage_song_library' => $permissions['can_manage_song_library'],
                'can_manage_newsletters' => $permissions['can_manage_newsletters'],
                'can_manage_mail_queue' => $permissions['can_manage_mail_queue'],
                'can_manage_sheet_archive' => $permissions['can_manage_sheet_archive'],
                'can_manage_budget' => $permissions['can_manage_budget'],
                'can_manage_tasks' => $permissions['can_manage_tasks'],
                'can_manage_backups' => $permissions['can_manage_backups'],
                'can_manage_own_voice_group' => $permissions['can_manage_own_voice_group']
            ]);
            $_SESSION['success'] = 'Rolle erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) {
                $_SESSION['error'] = 'Eine andere Rolle mit diesem Namen existiert bereits.';
            } else {
                $_SESSION['error'] = 'Datenbankfehler beim Aktualisieren: ';
            }
        }

        return $response->withHeader('Location', '/roles')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $roleId = (int) $args['id'];
        $role = Role::find($roleId);

        if (!$role) {
            $_SESSION['error'] = 'Rolle nicht gefunden.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        // Wie beim Bearbeiten: eine hoeher eingestufte Rolle bleibt unantastbar.
        $actorLevel = (int) ($_SESSION['role_level'] ?? 0);
        if ((int) $role->hierarchy_level > $actorLevel) {
            $_SESSION['error'] = 'Du kannst keine Rolle oberhalb deines eigenen Levels löschen.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        // Jede Zuweisung zaehlt, auch die eines archivierten Mitglieds: sonst stuende es
        // nach dem Wiederherstellen ohne Rolle da.
        $assignedUsers = $role->users()->count();
        if ($assignedUsers > 0) {
            $_SESSION['error'] = sprintf(
                'Die Rolle ist noch %d Mitglied(ern) zugewiesen und kann deshalb nicht gelöscht werden.',
                $assignedUsers
            );
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        try {
            $role->delete();
            $_SESSION['success'] = 'Rolle erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Datenbankfehler beim Löschen: ';
        }

        return $response->withHeader('Location', '/roles')->withStatus(302);
    }
}
