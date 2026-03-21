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
        $canManageUsers = isset($data['can_manage_users']) ? 1 : 0;
        $canEditUsers = isset($data['can_edit_users']) ? 1 : 0;
        $canManageProjectMembers = isset($data['can_manage_project_members']) ? 1 : 0;
        $canManageFinances = isset($data['can_manage_finances']) ? 1 : 0;
        $canManageMasterData = isset($data['can_manage_master_data']) ? 1 : 0;
        $canManageSponsoring = isset($data['can_manage_sponsoring']) ? 1 : 0;

        if (!$name) {
            $_SESSION['error'] = 'Der Rollenname darf nicht leer sein.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        try {
            Role::create([
                'name' => $name,
                'hierarchy_level' => $hierarchyLevel,
                'can_manage_users' => $canManageUsers,
                'can_edit_users' => $canEditUsers,
                'can_manage_project_members' => $canManageProjectMembers,
                'can_manage_finances' => $canManageFinances,
                'can_manage_master_data' => $canManageMasterData,
                'can_manage_sponsoring' => $canManageSponsoring
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
        $canManageUsers = isset($data['can_manage_users']) ? 1 : 0;
        $canEditUsers = isset($data['can_edit_users']) ? 1 : 0;
        $canManageProjectMembers = isset($data['can_manage_project_members']) ? 1 : 0;
        $canManageFinances = isset($data['can_manage_finances']) ? 1 : 0;
        $canManageMasterData = isset($data['can_manage_master_data']) ? 1 : 0;
        $canManageSponsoring = isset($data['can_manage_sponsoring']) ? 1 : 0;

        if (!$name) {
            $_SESSION['error'] = 'Der Rollenname darf nicht leer sein.';
            return $response->withHeader('Location', '/roles')->withStatus(302);
        }

        try {
            $role = Role::findOrFail($roleId);
            $role->update([
                'name' => $name,
                'hierarchy_level' => $hierarchyLevel,
                'can_manage_users' => $canManageUsers,
                'can_edit_users' => $canEditUsers,
                'can_manage_project_members' => $canManageProjectMembers,
                'can_manage_finances' => $canManageFinances,
                'can_manage_master_data' => $canManageMasterData,
                'can_manage_sponsoring' => $canManageSponsoring
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
