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
    private bool $requiresNewsletterManagement;
    private bool $requiresMailQueueManagement;
    private bool $requiresTaskManagement;
    private bool $requiresAttendanceManagement;
    private bool $requiresEventManagement;
    private bool $requiresFinanceRead;
    private bool $requiresSheetArchiveManagement;
    private bool $requiresBudgetManagement;
    private bool $requiresBudgetRead;
    private bool $requiresBackupManagement;
    private bool $requiresRoleManagement;

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
        bool $requiresAttendanceManagement = false,
        bool $requiresNewsletterManagement = false,
        bool $requiresMailQueueManagement = false,
        bool $requiresFinanceRead = false,
        bool $requiresSheetArchiveManagement = false,
        bool $requiresBudgetManagement = false,
        bool $requiresBudgetRead = false,
        bool $requiresBackupManagement = false,
        bool $requiresEventManagement = false,
        bool $requiresRoleManagement = false
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
        $this->requiresNewsletterManagement = $requiresNewsletterManagement;
        $this->requiresMailQueueManagement = $requiresMailQueueManagement;
        $this->requiresFinanceRead = $requiresFinanceRead;
        $this->requiresSheetArchiveManagement = $requiresSheetArchiveManagement;
        $this->requiresBudgetManagement = $requiresBudgetManagement;
        $this->requiresBudgetRead = $requiresBudgetRead;
        $this->requiresBackupManagement = $requiresBackupManagement;
        $this->requiresEventManagement = $requiresEventManagement;
        $this->requiresRoleManagement = $requiresRoleManagement;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if (!isset($_SESSION['user_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $canManageUsers = $_SESSION['can_manage_users'] ?? false;
        $canManageRoles = $_SESSION['can_manage_roles'] ?? false;
        $canManageProjectMembers = $_SESSION['can_manage_project_members'] ?? false;
        $canReadFinances = $_SESSION['can_read_finances'] ?? false;
        $canManageFinances = $_SESSION['can_manage_finances'] ?? false;
        $canManageMasterData = $_SESSION['can_manage_master_data'] ?? false;
        $canManageSponsoring = $_SESSION['can_manage_sponsoring'] ?? false;
        $canManageSongLibrary = $_SESSION['can_manage_song_library'] ?? false;
        $canManageNewsletters = $_SESSION['can_manage_newsletters'] ?? false;
        $canManageMailQueue = $_SESSION['can_manage_mail_queue'] ?? false;
        $canManageSheetArchive = $_SESSION['can_manage_sheet_archive'] ?? false;
        $canManageBudget = $_SESSION['can_manage_budget'] ?? false;
        $canManageTasks = $_SESSION['can_manage_tasks'] ?? false;
        $canManageAttendance = $_SESSION['can_manage_attendance'] ?? false;
        $canManageAttendanceAll = $_SESSION['can_manage_attendance_all'] ?? false;
        $canManageEvents = $_SESSION['can_manage_events'] ?? false;
        $canManageBackups = $_SESSION['can_manage_backups'] ?? false;
        $canManageOwnVoiceGroup = $_SESSION['can_manage_own_voice_group'] ?? false;
        $userLevel = $_SESSION['role_level'] ?? 0;

        if ($this->requiresTaskManagement && !$canManageTasks) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Aufgabenverwaltung.");
            return $response->withStatus(403);
        }

        // Terminverwaltung ist bewusst ohne Admin-Fallback: sie soll vergeben werden koennen,
        // ohne gleichzeitig Mitglieder-, Rollen- und Projektverwaltung mitzuliefern.
        if ($this->requiresEventManagement && !$canManageEvents) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Terminverwaltung.");
            return $response->withStatus(403);
        }

        // Die Anwesenheitsliste verlangt eines der beiden Anwesenheitsrechte; den Umfang
        // (eigene Stimmgruppe oder alle Mitglieder) setzt der AttendanceScopeService durch.
        // can_manage_own_voice_group deckt die Mitgliederpflege und Vertretungs-Anmeldungen
        // der eigenen Stimmgruppe ab, nicht die Anwesenheitsliste selbst.
        if ($this->requiresAttendanceManagement && !$canManageAttendance && !$canManageAttendanceAll) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Anwesenheitsverwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresRoleManagement && !$canManageRoles) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Rollenverwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresSongLibraryManagement && !$canManageSongLibrary) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Repertoire-Verwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresNewsletterManagement && !$canManageNewsletters) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Newsletter-Verwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresMailQueueManagement && !$canManageMailQueue) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Mailversand-Verwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresSheetArchiveManagement && !$canManageSheetArchive) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Notenarchiv-Verwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresBudgetManagement && !$canManageBudget) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Budgetverwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresBackupManagement && !$canManageBackups) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Backup-Verwaltung.");
            return $response->withStatus(403);
        }

        // Budget is an aggregated view of finance data, so finance readers may view it
        // read-only even without budget management rights.
        if (
            $this->requiresBudgetRead
            && !$canReadFinances && !$canManageFinances && !$canManageBudget
        ) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Budgetansicht.");
            return $response->withStatus(403);
        }

        if ($this->requiresSponsoringManagement && !$canManageSponsoring) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Sponsoring-Verwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresMasterDataManagement && !$canManageMasterData) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Stammdatenverwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresFinanceRead && !$canReadFinances && !$canManageFinances) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Finanzansicht.");
            return $response->withStatus(403);
        }

        if ($this->requiresFinanceManagement && !$canManageFinances) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung zur Finanzverwaltung.");
            return $response->withStatus(403);
        }

        if ($this->requiresProjectMemberManagement && !$canManageProjectMembers) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Keine Berechtigung zur Projektmitgliederverwaltung.");
            return $response->withStatus(403);
        }

        // Jedes Gate wird eigenstaendig geprueft: frueher hat allowVoiceGroupReps die beiden
        // folgenden Pruefungen komplett uebersprungen, sodass eine Kombination der Flags
        // stillschweigend Rechte verschenkt haette.
        if ($this->allowVoiceGroupReps && !$canManageUsers && !$canManageOwnVoiceGroup) {
            $response = new SlimResponse();
            $response->getBody()->write("Zugriff verweigert: Sie haben keine Berechtigung für diese Aktion.");
            return $response->withStatus(403);
        }

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

        return $handler->handle($request);
    }
}
