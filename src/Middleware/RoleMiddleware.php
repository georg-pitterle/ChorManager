<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Response as SlimResponse;

class RoleMiddleware implements MiddlewareInterface
{
    /**
     * Fallback fuer Instanzen, die - wie an allen Routen in Routes.php - per
     * `new RoleMiddleware(...)` statt ueber den DI-Container gebaut werden.
     * Der DI-Container kann hier nicht helfen: Routes.php uebergibt an jeder
     * der 21 Stellen bereits fertig gebaute Objekte an `->add(...)`, keine
     * Klassennamen, die der Container aufloesen wuerde. Routes.php setzt
     * diesen Wert einmalig auf den echten Container-Logger, bevor die Routen
     * registriert werden (siehe dortiger `setDefaultLogger`-Aufruf); ohne
     * diesen Aufruf (z. B. in Tests mit einem Minimal-Container) bleibt es
     * beim NullLogger. Dieser Zustand ist statisch und damit prozessweit
     * geteilt - `setDefaultLogger(null)` setzt ihn gezielt zurueck, damit ein
     * in einem Test gesetzter Logger nicht in spaetere, unabhaengige Tests
     * im selben PHPUnit-Prozess durchsickert.
     */
    private static ?LoggerInterface $defaultLogger = null;

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
    private LoggerInterface $logger;

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
        bool $requiresRoleManagement = false,
        ?LoggerInterface $logger = null
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
        $this->logger = $logger ?? self::$defaultLogger ?? new NullLogger();
    }

    /**
     * Setzt den Logger, den alle danach per `new RoleMiddleware(...)` gebauten
     * Instanzen verwenden, sofern ihnen nicht explizit ein eigener Logger
     * uebergeben wird. Wird einmal aus Routes.php mit dem Container-Logger
     * aufgerufen, bevor die Routen registriert werden.
     *
     * `null` setzt den Zustand explizit zurueck (kein Fallback auf den zuletzt
     * gesetzten Logger) - wichtig fuer Tests, die einen eigenen Logger setzen
     * und ihn danach wieder entfernen muessen, statt ihn fuer den Rest des
     * PHPUnit-Prozesses stehen zu lassen.
     */
    public static function setDefaultLogger(?LoggerInterface $logger): void
    {
        self::$defaultLogger = $logger;
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
        $canAssignOwnVoiceGroupToProject = $_SESSION['can_assign_own_voice_group_to_project'] ?? false;
        $userLevel = (int) ($_SESSION['role_level'] ?? 0);

        if ($this->requiresTaskManagement && !$canManageTasks) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Aufgabenverwaltung.',
                'can_manage_tasks'
            );
        }

        // Terminverwaltung ist bewusst ohne Admin-Fallback: sie soll vergeben werden koennen,
        // ohne gleichzeitig Mitglieder-, Rollen- und Projektverwaltung mitzuliefern.
        if ($this->requiresEventManagement && !$canManageEvents) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Terminverwaltung.',
                'can_manage_events'
            );
        }

        // Die Anwesenheitsliste verlangt eines der beiden Anwesenheitsrechte; den Umfang
        // (eigene Stimmgruppe oder alle Mitglieder) setzt der AttendanceScopeService durch.
        // can_manage_own_voice_group deckt die Mitgliederpflege und Vertretungs-Anmeldungen
        // der eigenen Stimmgruppe ab, nicht die Anwesenheitsliste selbst.
        if ($this->requiresAttendanceManagement && !$canManageAttendance && !$canManageAttendanceAll) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Anwesenheitsverwaltung.',
                'can_manage_attendance'
            );
        }

        if ($this->requiresRoleManagement && !$canManageRoles) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Rollenverwaltung.',
                'can_manage_roles'
            );
        }

        if ($this->requiresSongLibraryManagement && !$canManageSongLibrary) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Repertoire-Verwaltung.',
                'can_manage_song_library'
            );
        }

        if ($this->requiresNewsletterManagement && !$canManageNewsletters) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Newsletter-Verwaltung.',
                'can_manage_newsletters'
            );
        }

        if ($this->requiresMailQueueManagement && !$canManageMailQueue) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Mailversand-Verwaltung.',
                'can_manage_mail_queue'
            );
        }

        if ($this->requiresSheetArchiveManagement && !$canManageSheetArchive) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Notenarchiv-Verwaltung.',
                'can_manage_sheet_archive'
            );
        }

        if ($this->requiresBudgetManagement && !$canManageBudget) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Budgetverwaltung.',
                'can_manage_budget'
            );
        }

        if ($this->requiresBackupManagement && !$canManageBackups) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Backup-Verwaltung.',
                'can_manage_backups'
            );
        }

        // Budget is an aggregated view of finance data, so finance readers may view it
        // read-only even without budget management rights.
        if (
            $this->requiresBudgetRead
            && !$canReadFinances && !$canManageFinances && !$canManageBudget
        ) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Budgetansicht.',
                'can_read_finances'
            );
        }

        if ($this->requiresSponsoringManagement && !$canManageSponsoring) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Sponsoring-Verwaltung.',
                'can_manage_sponsoring'
            );
        }

        if ($this->requiresMasterDataManagement && !$canManageMasterData) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Stammdatenverwaltung.',
                'can_manage_master_data'
            );
        }

        if ($this->requiresFinanceRead && !$canReadFinances && !$canManageFinances) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Finanzansicht.',
                'can_read_finances'
            );
        }

        if ($this->requiresFinanceManagement && !$canManageFinances) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung zur Finanzverwaltung.',
                'can_manage_finances'
            );
        }

        // Das voice-group-beschraenkte Recht teilt sich die Projektmitglieder-Routen mit dem
        // breiten Recht; den Umfang (alle Stimmgruppen oder nur die eigene) setzt anschliessend
        // die ProjectMemberPolicy im Controller durch.
        if (
            $this->requiresProjectMemberManagement
            && !$canManageProjectMembers && !$canAssignOwnVoiceGroupToProject
        ) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Keine Berechtigung zur Projektmitgliederverwaltung.',
                'can_manage_project_members'
            );
        }

        // Jedes Gate wird eigenstaendig geprueft: frueher hat allowVoiceGroupReps die beiden
        // folgenden Pruefungen komplett uebersprungen, sodass eine Kombination der Flags
        // stillschweigend Rechte verschenkt haette.
        if ($this->allowVoiceGroupReps && !$canManageUsers && !$canManageOwnVoiceGroup) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung für diese Aktion.',
                'can_manage_own_voice_group'
            );
        }

        if ($this->requiresUserManagement && !$canManageUsers) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Sie haben keine Berechtigung für diese Aktion.',
                'can_manage_users'
            );
        }

        if ($this->minHierarchyLevel > 0 && $userLevel < $this->minHierarchyLevel) {
            return $this->deny(
                $request,
                'Zugriff verweigert: Ihre Rolle reicht für diese Ansicht nicht aus.',
                'hierarchy_level'
            );
        }

        return $handler->handle($request);
    }

    /**
     * Einziger Ausgang fuer alle Rechte-Abweisungen dieser Middleware: baut die
     * 403-Antwort und protokolliert authz.denied genau einmal pro Abweisung.
     * Benutzerkennung und IP kommen ueber den Request-Kontext und werden hier
     * nicht wiederholt.
     */
    private function deny(Request $request, string $message, string $permission): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write($message);

        $this->logger->info('Access denied.', [
            'event' => 'authz.denied',
            'permission' => $permission,
        ]);

        // Ohne Zeichensatzangabe zeigt der Browser die Umlaute der Meldung als
        // Ersatzzeichen an - `X-Content-Type-Options: nosniff` verbietet ihm das Raten.
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withStatus(403);
    }
}
