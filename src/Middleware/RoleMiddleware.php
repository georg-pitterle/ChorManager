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
    private bool $requiresSponsoringManagement;
    private bool $requiresSongLibraryManagement;
    private bool $requiresTaskManagement;
    private bool $requiresAttendanceManagement;

    public function __construct(
        bool $requiresUserManagement = false,
        int $minHierarchyLevel = 0,
        bool $allowVoiceGroupReps = false,
        bool $requiresProjectMemberManagement = false,
        bool $requiresFinanceManagement = false,
        bool $requiresMasterDataManagement = false,
        bool $requiresSponsoringManagement = false,
        bool $requiresSongLibraryManagement = false,
        bool $requiresTaskManagement = false,
        bool $requiresAttendanceManagement = false
    ) {
        $this->requiresUserManagement = $requiresUserManagement;
        $this->minHierarchyLevel = $minHierarchyLevel;
        $this->allowVoiceGroupReps = $allowVoiceGroupReps;
        $this->requiresProjectMemberManagement = $requiresProjectMemberManagement;
        $this->requiresFinanceManagement = $requiresFinanceManagement;
        $this->requiresMasterDataManagement = $requiresMasterDataManagement;
        $this->requiresSponsoringManagement = $requiresSponsoringManagement;
        $this->requiresSongLibraryManagement = $requiresSongLibraryManagement;
        $this->requiresTaskManagement = $requiresTaskManagement;
        $this->requiresAttendanceManagement = $requiresAttendanceManagement;
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
        $canManageSponsoring = $_SESSION['can_manage_sponsoring'] ?? false;
        $canManageSongLibrary = $_SESSION['can_manage_song_library'] ?? false;
        $canManageTasks = $_SESSION['can_manage_tasks'] ?? false;
        $canManageAttendance = $_SESSION['can_manage_attendance'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;

        if ($this->requiresTaskManagement && !$canManageTasks) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Aufgabenverwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresAttendanceManagement && !$canManageAttendance && !$canManageUsers) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Anwesenheitsverwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresSongLibraryManagement && !$canManageSongLibrary && !$canManageUsers) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Liedbibliothek-Verwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresSponsoringManagement && !$canManageSponsoring && !$canManageUsers) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Sponsoring-Verwaltung.");
            return $response->withStatus(403);
        }

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
            $response->getBody()->write("Zugriff verweigert: Keine Berechtigung zur Projektmitgliederverwaltung.");
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
