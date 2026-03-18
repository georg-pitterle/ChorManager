<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Queries\UserQuery;
use App\Models\User;
use App\Models\Role;

class AuthController
{
    private Twig $view;
    private UserQuery $userQuery;

    public function __construct(Twig $view, UserQuery $userQuery)
    {
        $this->view = $view;
        $this->userQuery = $userQuery;
    }

    public function showLogin(Request $request, Response $response): Response
    {
        // Redirect to setup if no users exist
        if (User::count() === 0) {
            return $response->withHeader('Location', '/setup')->withStatus(302);
        }

        if (isset($_SESSION['user_id'])) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['error']);

        return $this->view->render($response, 'auth/login.twig', [
            'error' => $error
        ]);
    }

    public function processLogin(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        $user = $this->userQuery->findByEmail($email);

        if ($user && password_verify($password, $user->password)) {
            $_SESSION['user_id'] = (int) $user->id;
            $_SESSION['user_name'] = $user->first_name . ' ' . $user->last_name;

            $canManageUsers = false;
            $canEditUsers = false;
            $canManageProjectMembers = false;
            $canManageFinances = false;
            $canManageMasterData = false;
            $maxRoleLevel = 0;

            foreach ($user->roles as $role) {
                // Determine logic from old PDO behavior (the roles table holds these columns currently)
                // Note: since schema.sql didn't define can_manage_users, can_edit_users, can_manage_project_members
                // on the roles table, this logic might need those columns mapped if they exist,
                // or rely on hierarchy_level.
                // Assuming hierarchy_level > 80 means can manage users (based on old code).
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

            $voiceGroupIds = $user->voiceGroups->pluck('id')->toArray();
            $_SESSION['voice_group_ids'] = $voiceGroupIds;

            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $_SESSION['error'] = 'Ungültige E-Mail-Adresse oder Passwort.';
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    public function showSetup(Request $request, Response $response): Response
    {
        // Only allow setup if no users exist
        if (User::count() > 0) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['error']);

        return $this->view->render($response, 'auth/setup.twig', [
            'error' => $error
        ]);
    }

    public function processSetup(Request $request, Response $response): Response
    {
        // Protect against running setup if users already exist
        if (User::count() > 0) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (!$firstName || !$lastName || !$email || !$password) {
            $_SESSION['error'] = 'Alle Felder sind Pflichtfelder.';
            return $response->withHeader('Location', '/setup')->withStatus(302);
        }

        try {
            // First create the Admin Role
            $adminRole = Role::firstOrCreate(['name' => 'Admin'], [
                'hierarchy_level' => 100,
                'can_manage_users' => 1,
                'can_edit_users' => 1,
                'can_manage_project_members' => 1,
                'can_manage_finances' => 1,
                'can_manage_master_data' => 1
            ]);

            // Create the first user
            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'is_active' => 1
            ]);

            // Assign Admin role
            $user->roles()->attach($adminRole->id);

            // Log them in immediately
            $_SESSION['user_id'] = (int) $user->id;
            $_SESSION['user_name'] = $user->first_name . ' ' . $user->last_name;
            $_SESSION['can_manage_users'] = true;
            $_SESSION['can_edit_users'] = true;
            $_SESSION['can_manage_project_members'] = true;
            $_SESSION['can_manage_finances'] = true;
            $_SESSION['can_manage_master_data'] = true;
            $_SESSION['role_level'] = 100;
            $_SESSION['voice_group_ids'] = [];

            $_SESSION['success'] = 'Administratorkonto erfolgreich erstellt!';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Erstellen des Kontos: ' . $e->getMessage();
            return $response->withHeader('Location', '/setup')->withStatus(302);
        }
    }

    public function logout(Request $request, Response $response): Response
    {
        session_destroy();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
