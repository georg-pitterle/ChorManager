<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Role;

class RoleController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    /**
     * @return array<string,int>
     */
    public static function buildPermissionFlags(array $data): array
    {
        return [
            'can_manage_users' => isset($data['can_manage_users']) ? 1 : 0,
            'can_edit_users' => isset($data['can_edit_users']) ? 1 : 0,
            'can_manage_attendance' => isset($data['can_manage_attendance']) ? 1 : 0,
            'can_manage_project_members' => isset($data['can_manage_project_members']) ? 1 : 0,
            'can_manage_finances' => isset($data['can_manage_finances']) ? 1 : 0,
            'can_manage_master_data' => isset($data['can_manage_master_data']) ? 1 : 0,
            'can_manage_sponsoring' => isset($data['can_manage_sponsoring']) ? 1 : 0,
            'can_manage_song_library' => isset($data['can_manage_song_library']) ? 1 : 0,
            'can_manage_newsletters' => isset($data['can_manage_newsletters']) ? 1 : 0,
            'can_manage_tasks' => isset($data['can_manage_tasks']) ? 1 : 0,
        ];
    }

    public function index(Request $request, Response $response): Response
    {
        // Eloquent equivalent of the Raw query with user count
        $roles = Role::withCount([
            'users' => function ($query) {
                $query->where('is_active', 1);
            }
        ])->orderBy('hierarchy_level', 'desc')->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'roles/index.twig', [
            'roles' => $roles,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $hierarchyLevel = (int) ($data['hierarchy_level'] ?? 0);
        $permissions = self::buildPermissionFlags($data);

        if (!$name) {
            $_SESSION['error'] = 'Der Rollenname darf nicht leer sein.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        try {
            Role::create([
                'name' => $name,
                'hierarchy_level' => $hierarchyLevel,
                'can_manage_users' => $permissions['can_manage_users'],
                'can_edit_users' => $permissions['can_edit_users'],
                'can_manage_attendance' => $permissions['can_manage_attendance'],
                'can_manage_project_members' => $permissions['can_manage_project_members'],
                'can_manage_finances' => $permissions['can_manage_finances'],
                'can_manage_master_data' => $permissions['can_manage_master_data'],
                'can_manage_sponsoring' => $permissions['can_manage_sponsoring'],
                'can_manage_song_library' => $permissions['can_manage_song_library'],
                'can_manage_newsletters' => $permissions['can_manage_newsletters'],
                'can_manage_tasks' => $permissions['can_manage_tasks']
            ]);
            $_SESSION['success'] = 'Rolle erfolgreich angelegt.';
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) {
                $_SESSION['error'] = 'Eine Rolle mit diesem Namen existiert bereits.';
            } else {
                $_SESSION['error'] = 'Datenbankfehler: ' . $e->getMessage();
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
        $permissions = self::buildPermissionFlags($data);

        if (!$name) {
            $_SESSION['error'] = 'Der Rollenname darf nicht leer sein.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        try {
            $role = Role::findOrFail($roleId);
            $role->update([
                'name' => $name,
                'hierarchy_level' => $hierarchyLevel,
                'can_manage_users' => $permissions['can_manage_users'],
                'can_edit_users' => $permissions['can_edit_users'],
                'can_manage_attendance' => $permissions['can_manage_attendance'],
                'can_manage_project_members' => $permissions['can_manage_project_members'],
                'can_manage_finances' => $permissions['can_manage_finances'],
                'can_manage_master_data' => $permissions['can_manage_master_data'],
                'can_manage_sponsoring' => $permissions['can_manage_sponsoring'],
                'can_manage_song_library' => $permissions['can_manage_song_library'],
                'can_manage_newsletters' => $permissions['can_manage_newsletters'],
                'can_manage_tasks' => $permissions['can_manage_tasks']
            ]);
            $_SESSION['success'] = 'Rolle erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) {
                $_SESSION['error'] = 'Eine andere Rolle mit diesem Namen existiert bereits.';
            } else {
                $_SESSION['error'] = 'Datenbankfehler beim Aktualisieren: ' . $e->getMessage();
            }
        }

        return $response->withHeader('Location', '/roles')->withStatus(302);
    }
}
