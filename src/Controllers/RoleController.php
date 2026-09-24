<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Role;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        'can_create_own_sponsorships' => 'sponsoring',
        'can_manage_newsletters' => 'newsletter',
        'can_manage_sheet_archive' => 'sheet_archive',
        'can_manage_tasks' => 'tasks',
    ];

    /**
     * Ab diesem Hierarchie-Level darf eine Rolle jedes Recht vergeben, auch eines,
     * das die vergebende Person selbst nicht hält.
     *
     * Ohne diese Ausnahme käme nach einem neu eingeführten Recht niemand mehr
     * daran: Es steht anfangs auf keiner Rolle, also hält es niemand, also darf es
     * niemand vergeben. 100 ist das Level, das die Erstinstallation der
     * Admin-Rolle gibt (AuthController::processSetup) und das damit als oberste
     * Stufe gilt.
     */
    private const UNRESTRICTED_LEVEL = 100;

    private Twig $view;
    private array $settings;
    private LoggerInterface $logger;

    public function __construct(Twig $view, array $settings = [], LoggerInterface $logger = new NullLogger())
    {
        $this->view = $view;
        $this->settings = $settings;
        $this->logger = $logger;
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
            'can_manage_attendance_all' => isset($data['can_manage_attendance_all']) ? 1 : 0,
            'can_manage_events' => isset($data['can_manage_events']) ? 1 : 0,
            'can_manage_project_members' => isset($data['can_manage_project_members']) ? 1 : 0,
            'can_read_finances' => $canReadFinances ? 1 : 0,
            'can_manage_finances' => isset($data['can_manage_finances']) ? 1 : 0,
            'can_manage_master_data' => isset($data['can_manage_master_data']) ? 1 : 0,
            'can_manage_sponsoring' => isset($data['can_manage_sponsoring']) ? 1 : 0,
            'can_create_own_sponsorships' => isset($data['can_create_own_sponsorships']) ? 1 : 0,
            'can_manage_song_library' => isset($data['can_manage_song_library']) ? 1 : 0,
            'can_manage_newsletters' => isset($data['can_manage_newsletters']) ? 1 : 0,
            'can_manage_mail_queue' => isset($data['can_manage_mail_queue']) ? 1 : 0,
            'can_manage_sheet_archive' => isset($data['can_manage_sheet_archive']) ? 1 : 0,
            'can_manage_budget' => isset($data['can_manage_budget']) && $data['can_manage_budget'] === '1' ? 1 : 0,
            'can_manage_tasks' => isset($data['can_manage_tasks']) ? 1 : 0,
            'can_manage_backups' => isset($data['can_manage_backups']) ? 1 : 0,
            'can_manage_own_voice_group' => isset($data['can_manage_own_voice_group']) ? 1 : 0,
            'can_assign_own_voice_group_to_project' =>
                isset($data['can_assign_own_voice_group_to_project']) ? 1 : 0,
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
     * @param array<string, bool> $before
     * @param array<string, bool> $after
     * @return array{granted: list<string>, revoked: list<string>}
     */
    public static function permissionDiff(array $before, array $after): array
    {
        $granted = [];
        $revoked = [];

        foreach ($after as $permission => $value) {
            $previous = (bool) ($before[$permission] ?? false);

            if ($value && !$previous) {
                $granted[] = $permission;
            }

            if (!$value && $previous) {
                $revoked[] = $permission;
            }
        }

        return ['granted' => $granted, 'revoked' => $revoked];
    }

    /**
     * Die Rechte, die der Aufrufer selbst nicht hält und deshalb auch nicht
     * vergeben darf.
     *
     * Ohne diese Grenze begrenzte nur das Hierarchie-Level, *welche* Rolle
     * bearbeitet werden darf - nicht, *was* hineingeschrieben wird. Wer
     * can_manage_roles hielt, konnte damit seine eigene Rolle auf seinem Level
     * bearbeiten und ihr jedes Recht zuschalten: Kassa, Backups, Newsletter. Das
     * Level allein vergibt bewusst kein Recht (siehe
     * SessionAuthService::highestHierarchyLevel), und genau deshalb darf es auch
     * nicht als Freibrief für die Rechtevergabe dienen.
     *
     * Zwei Fälle bleiben erlaubt, weil sie keine Ausweitung sind: ein Recht zu
     * *entziehen*, und ein Recht stehen zu lassen, das die Rolle schon trägt -
     * sonst könnte eine Rolle nach dem ersten Speichern nie wieder bearbeitet
     * werden, ohne ihre übrigen Rechte zu verlieren.
     *
     * @param array<string,int> $flags
     * @param array<string,mixed> $existing bisherige Werte der bearbeiteten Rolle
     * @return list<string>
     */
    private function permissionsBeyondActor(array $flags, array $existing = []): array
    {
        if ((int) ($_SESSION['role_level'] ?? 0) >= self::UNRESTRICTED_LEVEL) {
            return [];
        }

        $beyond = [];

        foreach ($flags as $permission => $value) {
            if ((int) $value !== 1) {
                continue;
            }

            if ((int) ($existing[$permission] ?? 0) === 1) {
                continue;
            }

            if ((bool) ($_SESSION[$permission] ?? false)) {
                continue;
            }

            $beyond[] = $permission;
        }

        return $beyond;
    }

    /**
     * Streicht die Rechte aus permissionsBeyondActor() wieder heraus.
     *
     * Verworfen statt abgewiesen - dieselbe Wahl wie bei den Rollen- und
     * Stimmgruppen-Zuordnungen in UserController: Ein Wert, der so nicht aus der
     * Oberfläche kommt, soll die erlaubten Änderungen derselben Maske nicht
     * blockieren. Dass etwas gestrichen wurde, meldet der Aufrufer.
     *
     * @param array<string,int> $flags
     * @param list<string> $beyond
     * @return array<string,int>
     */
    private function withoutPermissions(array $flags, array $beyond): array
    {
        foreach ($beyond as $permission) {
            $flags[$permission] = 0;
        }

        return $flags;
    }

    /**
     * @param list<string> $beyond
     */
    private function reportCappedPermissions(array $beyond, ?int $roleId): void
    {
        if ($beyond === []) {
            return;
        }

        $this->logger->warning('Role permission grant capped to the actor\'s own rights.', [
            'event' => 'authz.role.grant_capped',
            'role_id' => $roleId,
            'permissions' => $beyond,
        ]);

        // Ohne Aufzählung der Rechte: Ihre Beschriftungen stehen in
        // templates/roles/index.twig, und eine zweite Liste hier liefe damit
        // auseinander. Die Rechte selbst zeigt die Matrix nach dem Speichern.
        $_SESSION['error'] = sprintf(
            'Du kannst nur Rechte vergeben, die du selbst hast. %d der gewählten %s nicht gesetzt.',
            count($beyond),
            count($beyond) === 1 ? 'Recht wurde' : 'Rechte wurden'
        );
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
            // Für das Löschen zählt jede Zuweisung, auch die eines archivierten Mitglieds.
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

        // Eine neue Rolle trägt noch nichts, deshalb ohne $existing: jedes gesetzte
        // Recht ist hier eine Neuvergabe.
        $cappedPermissions = $this->permissionsBeyondActor($permissions);
        $permissions = $this->withoutPermissions($permissions, $cappedPermissions);

        try {
            $role = Role::create([
                'name' => $name,
                'hierarchy_level' => $hierarchyLevel,
                'can_manage_users' => $permissions['can_manage_users'],
                'can_manage_roles' => $permissions['can_manage_roles'],
                'can_edit_users' => $permissions['can_edit_users'],
                'can_manage_attendance_all' => $permissions['can_manage_attendance_all'],
                'can_manage_events' => $permissions['can_manage_events'],
                'can_manage_project_members' => $permissions['can_manage_project_members'],
                'can_read_finances' => $permissions['can_read_finances'],
                'can_manage_finances' => $permissions['can_manage_finances'],
                'can_manage_master_data' => $permissions['can_manage_master_data'],
                'can_manage_sponsoring' => $permissions['can_manage_sponsoring'],
                'can_create_own_sponsorships' => $permissions['can_create_own_sponsorships'],
                'can_manage_song_library' => $permissions['can_manage_song_library'],
                'can_manage_newsletters' => $permissions['can_manage_newsletters'],
                'can_manage_mail_queue' => $permissions['can_manage_mail_queue'],
                'can_manage_sheet_archive' => $permissions['can_manage_sheet_archive'],
                'can_manage_budget' => $permissions['can_manage_budget'],
                'can_manage_tasks' => $permissions['can_manage_tasks'],
                'can_manage_backups' => $permissions['can_manage_backups'],
                'can_manage_own_voice_group' => $permissions['can_manage_own_voice_group'],
                'can_assign_own_voice_group_to_project' =>
                    $permissions['can_assign_own_voice_group_to_project']
            ]);
            $_SESSION['success'] = 'Rolle erfolgreich angelegt.';

            $this->logger->info('Role created.', [
                'event' => 'role.created',
                'role_id' => (int) $role->id,
                'role_name' => $role->name,
            ]);

            $this->reportCappedPermissions($cappedPermissions, (int) $role->id);
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) {
                $_SESSION['error'] = 'Eine Rolle mit diesem Namen existiert bereits.';
            } else {
                $this->logger->error('Role creation failed.', [
                    'event' => 'role.create.failed',
                    'role_name' => $name,
                    'exception' => $e,
                ]);
                $_SESSION['error'] = 'Die Rolle konnte nicht angelegt werden. Bitte erneut versuchen.';
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

        // Was die Rolle schon trägt, bleibt; neu vergeben lässt sich nur, was der
        // Aufrufer selbst hält.
        $cappedPermissions = $this->permissionsBeyondActor($permissions, $existingRole->getAttributes());
        $permissions = $this->withoutPermissions($permissions, $cappedPermissions);

        // The flag list comes straight from buildPermissionFlags() so a newly added can_*
        // right is picked up automatically, without a second hardcoded list to keep in sync.
        $existingAttributes = $existingRole->getAttributes();
        $permissionsBefore = [];
        foreach (array_keys($permissions) as $permission) {
            $permissionsBefore[$permission] = (bool) ($existingAttributes[$permission] ?? false);
        }
        $permissionsAfter = array_map(static fn ($value): bool => (bool) $value, $permissions);

        try {
            $role = $existingRole;
            $role->update([
                'name' => $name,
                'hierarchy_level' => $hierarchyLevel,
                'can_manage_users' => $permissions['can_manage_users'],
                'can_manage_roles' => $permissions['can_manage_roles'],
                'can_edit_users' => $permissions['can_edit_users'],
                'can_manage_attendance_all' => $permissions['can_manage_attendance_all'],
                'can_manage_events' => $permissions['can_manage_events'],
                'can_manage_project_members' => $permissions['can_manage_project_members'],
                'can_read_finances' => $permissions['can_read_finances'],
                'can_manage_finances' => $permissions['can_manage_finances'],
                'can_manage_master_data' => $permissions['can_manage_master_data'],
                'can_manage_sponsoring' => $permissions['can_manage_sponsoring'],
                'can_create_own_sponsorships' => $permissions['can_create_own_sponsorships'],
                'can_manage_song_library' => $permissions['can_manage_song_library'],
                'can_manage_newsletters' => $permissions['can_manage_newsletters'],
                'can_manage_mail_queue' => $permissions['can_manage_mail_queue'],
                'can_manage_sheet_archive' => $permissions['can_manage_sheet_archive'],
                'can_manage_budget' => $permissions['can_manage_budget'],
                'can_manage_tasks' => $permissions['can_manage_tasks'],
                'can_manage_backups' => $permissions['can_manage_backups'],
                'can_manage_own_voice_group' => $permissions['can_manage_own_voice_group'],
                'can_assign_own_voice_group_to_project' =>
                    $permissions['can_assign_own_voice_group_to_project']
            ]);
            $_SESSION['success'] = 'Rolle erfolgreich aktualisiert.';

            $diff = self::permissionDiff($permissionsBefore, $permissionsAfter);

            $this->logger->info('Role updated.', [
                'event' => 'role.updated',
                'role_id' => (int) $role->id,
                'role_name' => $role->name,
                'granted' => $diff['granted'],
                'revoked' => $diff['revoked'],
            ]);

            $this->reportCappedPermissions($cappedPermissions, (int) $role->id);
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) {
                $_SESSION['error'] = 'Eine andere Rolle mit diesem Namen existiert bereits.';
            } else {
                $this->logger->error('Role update failed.', [
                    'event' => 'role.update.failed',
                    'role_id' => $roleId,
                    'exception' => $e,
                ]);
                $_SESSION['error'] = 'Die Rolle konnte nicht aktualisiert werden. Bitte erneut versuchen.';
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

        // Wie beim Bearbeiten: eine höher eingestufte Rolle bleibt unantastbar.
        $actorLevel = (int) ($_SESSION['role_level'] ?? 0);
        if ((int) $role->hierarchy_level > $actorLevel) {
            $_SESSION['error'] = 'Du kannst keine Rolle oberhalb deines eigenen Levels löschen.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        // Jede Zuweisung zählt, auch die eines archivierten Mitglieds: sonst stünde es
        // nach dem Wiederherstellen ohne Rolle da.
        $assignedUsers = $role->users()->count();
        if ($assignedUsers > 0) {
            $_SESSION['error'] = sprintf(
                'Die Rolle ist noch %d Mitglied(ern) zugewiesen und kann deshalb nicht gelöscht werden.',
                $assignedUsers
            );
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        $deletedRoleId = (int) $role->id;
        $deletedRoleName = $role->name;

        try {
            $role->delete();
            $_SESSION['success'] = 'Rolle erfolgreich gelöscht.';

            $this->logger->info('Role deleted.', [
                'event' => 'role.deleted',
                'role_id' => $deletedRoleId,
                'role_name' => $deletedRoleName,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Role deletion failed.', [
                'event' => 'role.delete.failed',
                'role_id' => $deletedRoleId,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Die Rolle konnte nicht gelöscht werden. Bitte erneut versuchen.';
        }

        return $response->withHeader('Location', '/roles')->withStatus(302);
    }
}
