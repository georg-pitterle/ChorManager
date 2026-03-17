<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class RoleMiddleware implements MiddlewareInterface
{
    private bool $requiresUserManagement;
    private int $minHierarchyLevel;
    private bool $allowVoiceGroupReps;
    private bool $requiresProjectMemberManagement;
    private bool $requiresFinanceManagement;
    private bool $requiresMasterDataManagement;

    public function __construct(
        bool $requiresUserManagement = false,
        int $minHierarchyLevel = 0,
        bool $allowVoiceGroupReps = false,
        bool $requiresProjectMemberManagement = false,
        bool $requiresFinanceManagement = false,
        bool $requiresMasterDataManagement = false
    ) {
        $this->requiresUserManagement = $requiresUserManagement;
        $this->minHierarchyLevel = $minHierarchyLevel;
        $this->allowVoiceGroupReps = $allowVoiceGroupReps;
        $this->requiresProjectMemberManagement = $requiresProjectMemberManagement;
        $this->requiresFinanceManagement = $requiresFinanceManagement;
        $this->requiresMasterDataManagement = $requiresMasterDataManagement;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if (!isset($_SESSION['user_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $canManageUsers = $_SESSION['can_manage_users'] ?? false;
        $canManageProjectMembers = $_SESSION['can_manage_project_members'] ?? false;
        $canManageFinances = $_SESSION['can_manage_finances'] ?? false;
        $canManageMasterData = $_SESSION['can_manage_master_data'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;

        if ($this->requiresMasterDataManagement && !$canManageMasterData && !$canManageUsers) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Stammdatenverwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresFinanceManagement && !$canManageFinances && !$canManageUsers) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Finanzverwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresProjectMemberManagement && !$canManageProjectMembers && !$canManageUsers) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Projektmitgliederverwaltung.");
            return $response->withStatus(403);
        }

        if ($this->allowVoiceGroupReps) {
            // Must have global manage OR be a voice group rep (level >= 40)
            if (!$canManageUsers && $userLevel < 40) {
                $response = new SlimResponse();
                $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung für diese Aktion.");
                return $response->withStatus(403);
            }
        } else {
            if ($this->requiresUserManagement && !$canManageUsers) {
                $response = new SlimResponse();
                $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung für diese Aktion.");
                return $response->withStatus(403);
            }

            if ($this->minHierarchyLevel > 0 && $userLevel < $this->minHierarchyLevel) {
                $response = new SlimResponse();
                $response->getBody()->write("Zugriff verweigert: Ihre Rolle reicht für diese Ansicht nicht aus.");
                return $response->withStatus(403);
            }
        }

        return $handler->handle($request);
    }
}
