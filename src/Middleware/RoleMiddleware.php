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
     * Ersatzwert für Instanzen, die - wie an allen Routen in Routes.php - per
     * `new RoleMiddleware(...)` statt über den DI-Container gebaut werden.
     * Der DI-Container kann hier nicht helfen: Routes.php übergibt an jeder
     * der 21 Stellen bereits fertig gebaute Objekte an `->add(...)`, keine
     * Klassennamen, die der Container auflösen würde. Routes.php setzt
     * diesen Wert einmalig auf den echten Container-Logger, bevor die Routen
     * registriert werden (siehe dortiger `setDefaultLogger`-Aufruf); ohne
     * diesen Aufruf (z. B. in Tests mit einem Minimal-Container) bleibt es
     * beim NullLogger. Dieser Zustand ist statisch und damit prozessweit
     * geteilt - `setDefaultLogger(null)` setzt ihn gezielt zurück, damit ein
     * in einem Test gesetzter Logger nicht in spätere, unabhängige Tests
     * im selben PHPUnit-Prozess durchsickert.
     */
    private static ?LoggerInterface $defaultLogger = null;

    /**
     * Alle Rechte-Gates - je Gate die Sitzungsrechte, von denen **eines**
     * genügt, die Meldung bei Abweisung und der Rechte-Schlüssel, der ins
     * Protokoll geht.
     *
     * Die Reihenfolge dieser Tabelle ist zugleich die Prüfreihenfolge. Sie
     * spielt nur eine Rolle, wenn eine Route mehrere Gates gleichzeitig setzt;
     * dann entscheidet sie, welche Meldung die abgewiesene Person sieht.
     *
     * Wo mehrere Rechte gelistet sind, ist das Absicht, und den Umfang setzt
     * anschließend eine andere Stelle durch:
     * - Anwesenheit: eigene Stimmgruppe oder alle Mitglieder - AttendanceScopeService.
     *   `can_manage_own_voice_group` deckt Mitgliederpflege, Vertretungs-Anmeldungen
     *   und die Anwesenheitsliste der eigenen Stimmgruppe ab. Bis 20260902 stand hier
     *   zusätzlich `can_manage_attendance`; das Recht hatte aber denselben Umfang -
     *   AttendanceScopeService schränkte ohne `_all` ohnehin auf die eigenen
     *   Stimmgruppen ein - und ist ersatzlos entfallen.
     * - Budgetansicht: eine verdichtete Sicht auf die Finanzdaten, deshalb dürfen
     *   Finanz-Lesende sie auch ohne Budgetrecht ansehen.
     * - Sponsoring-Bereich: Lesen und eigene Vereinbarungen - SponsoringPolicy.
     * - Projektmitglieder: alle Stimmgruppen oder nur die eigene - ProjectMemberPolicy.
     *
     * Termin- und Aufgabenverwaltung stehen bewusst ohne Admin-Ersatzrecht da: Sie
     * sollen vergeben werden können, ohne gleichzeitig Mitglieder-, Rollen- und
     * Projektverwaltung mitzuliefern.
     *
     * @var array<string, array{permissions: list<string>, logged_permission: string, message: string}>
     */
    private const GATES = [
        'requiresTaskManagement' => [
            'permissions' => ['can_manage_tasks'],
            'logged_permission' => 'can_manage_tasks',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Aufgabenverwaltung.',
        ],
        'requiresEventManagement' => [
            'permissions' => ['can_manage_events'],
            'logged_permission' => 'can_manage_events',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Terminverwaltung.',
        ],
        'requiresAttendanceManagement' => [
            'permissions' => ['can_manage_own_voice_group', 'can_manage_attendance_all'],
            'logged_permission' => 'can_manage_own_voice_group',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Anwesenheitsverwaltung.',
        ],
        'requiresRoleManagement' => [
            'permissions' => ['can_manage_roles'],
            'logged_permission' => 'can_manage_roles',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Rollenverwaltung.',
        ],
        'requiresSongLibraryManagement' => [
            'permissions' => ['can_manage_song_library'],
            'logged_permission' => 'can_manage_song_library',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Repertoire-Verwaltung.',
        ],
        'requiresNewsletterManagement' => [
            'permissions' => ['can_manage_newsletters'],
            'logged_permission' => 'can_manage_newsletters',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Newsletter-Verwaltung.',
        ],
        'requiresMailQueueManagement' => [
            'permissions' => ['can_manage_mail_queue'],
            'logged_permission' => 'can_manage_mail_queue',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Mailversand-Verwaltung.',
        ],
        'requiresSheetArchiveManagement' => [
            'permissions' => ['can_manage_sheet_archive'],
            'logged_permission' => 'can_manage_sheet_archive',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Notenarchiv-Verwaltung.',
        ],
        'requiresBudgetManagement' => [
            'permissions' => ['can_manage_budget'],
            'logged_permission' => 'can_manage_budget',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Budgetverwaltung.',
        ],
        'requiresBackupManagement' => [
            'permissions' => ['can_manage_backups'],
            'logged_permission' => 'can_manage_backups',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Backup-Verwaltung.',
        ],
        'requiresBudgetRead' => [
            'permissions' => ['can_read_finances', 'can_manage_finances', 'can_manage_budget'],
            'logged_permission' => 'can_read_finances',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Budgetansicht.',
        ],
        'requiresSponsoringAccess' => [
            'permissions' => ['can_manage_sponsoring', 'can_create_own_sponsorships'],
            'logged_permission' => 'can_create_own_sponsorships',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung für den Sponsoring-Bereich.',
        ],
        'requiresSponsoringManagement' => [
            'permissions' => ['can_manage_sponsoring'],
            'logged_permission' => 'can_manage_sponsoring',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Sponsoring-Verwaltung.',
        ],
        'requiresMasterDataManagement' => [
            'permissions' => ['can_manage_master_data'],
            'logged_permission' => 'can_manage_master_data',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Stammdatenverwaltung.',
        ],
        'requiresFinanceRead' => [
            'permissions' => ['can_read_finances', 'can_manage_finances'],
            'logged_permission' => 'can_read_finances',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Finanzansicht.',
        ],
        'requiresFinanceManagement' => [
            'permissions' => ['can_manage_finances'],
            'logged_permission' => 'can_manage_finances',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung zur Finanzverwaltung.',
        ],
        'requiresProjectMemberManagement' => [
            'permissions' => ['can_manage_project_members', 'can_assign_own_voice_group_to_project'],
            'logged_permission' => 'can_manage_project_members',
            'message' => 'Zugriff verweigert: Keine Berechtigung zur Projektmitgliederverwaltung.',
        ],
        'allowVoiceGroupReps' => [
            'permissions' => ['can_manage_users', 'can_manage_own_voice_group'],
            'logged_permission' => 'can_manage_own_voice_group',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung für diese Aktion.',
        ],
        'requiresUserManagement' => [
            'permissions' => ['can_manage_users'],
            'logged_permission' => 'can_manage_users',
            'message' => 'Zugriff verweigert: Sie haben keine Berechtigung für diese Aktion.',
        ],
    ];

    /**
     * Die Gates dieser Instanz, in der Reihenfolge von self::GATES.
     *
     * @var list<string>
     */
    private array $activeGates;

    private int $minHierarchyLevel;
    private LoggerInterface $logger;

    /**
     * Jeder Schalter trägt den Namen seines Gates in self::GATES; alle
     * Aufrufstellen in Routes.php übergeben ihn benannt.
     *
     * Früher stand hier für jedes Gate ein eigener Wahrheitswert, dessen
     * Bedeutung allein an der Position hing: Ein Einschub in der Mitte verschob
     * still die Bedeutung aller folgenden, weshalb neue Schalter ans Ende
     * gehängt werden mussten - zuletzt sogar hinter den Logger. Ein neues Gate
     * braucht jetzt einen Eintrag in self::GATES und einen gleichnamigen
     * Parameter; RoleMiddlewareGateTableFeatureTest weist jede Hälfte ohne die
     * andere zurück.
     */
    public function __construct(
        bool $requiresTaskManagement = false,
        bool $requiresEventManagement = false,
        bool $requiresAttendanceManagement = false,
        bool $requiresRoleManagement = false,
        bool $requiresSongLibraryManagement = false,
        bool $requiresNewsletterManagement = false,
        bool $requiresMailQueueManagement = false,
        bool $requiresSheetArchiveManagement = false,
        bool $requiresBudgetManagement = false,
        bool $requiresBackupManagement = false,
        bool $requiresBudgetRead = false,
        bool $requiresSponsoringAccess = false,
        bool $requiresSponsoringManagement = false,
        bool $requiresMasterDataManagement = false,
        bool $requiresFinanceRead = false,
        bool $requiresFinanceManagement = false,
        bool $requiresProjectMemberManagement = false,
        bool $allowVoiceGroupReps = false,
        bool $requiresUserManagement = false,
        int $minHierarchyLevel = 0,
        ?LoggerInterface $logger = null
    ) {
        $requestedGates = [
            'requiresTaskManagement' => $requiresTaskManagement,
            'requiresEventManagement' => $requiresEventManagement,
            'requiresAttendanceManagement' => $requiresAttendanceManagement,
            'requiresRoleManagement' => $requiresRoleManagement,
            'requiresSongLibraryManagement' => $requiresSongLibraryManagement,
            'requiresNewsletterManagement' => $requiresNewsletterManagement,
            'requiresMailQueueManagement' => $requiresMailQueueManagement,
            'requiresSheetArchiveManagement' => $requiresSheetArchiveManagement,
            'requiresBudgetManagement' => $requiresBudgetManagement,
            'requiresBackupManagement' => $requiresBackupManagement,
            'requiresBudgetRead' => $requiresBudgetRead,
            'requiresSponsoringAccess' => $requiresSponsoringAccess,
            'requiresSponsoringManagement' => $requiresSponsoringManagement,
            'requiresMasterDataManagement' => $requiresMasterDataManagement,
            'requiresFinanceRead' => $requiresFinanceRead,
            'requiresFinanceManagement' => $requiresFinanceManagement,
            'requiresProjectMemberManagement' => $requiresProjectMemberManagement,
            'allowVoiceGroupReps' => $allowVoiceGroupReps,
            'requiresUserManagement' => $requiresUserManagement,
        ];

        // Über self::GATES laufen, nicht über $requestedGates: So bestimmt die
        // Tabelle die Prüfreihenfolge, nicht die Schreibweise dieser Zuordnung.
        $this->activeGates = array_values(array_filter(
            array_keys(self::GATES),
            static fn (string $gate): bool => $requestedGates[$gate] ?? false
        ));

        $this->minHierarchyLevel = $minHierarchyLevel;
        $this->logger = $logger ?? self::$defaultLogger ?? new NullLogger();
    }

    /**
     * Setzt den Logger, den alle danach per `new RoleMiddleware(...)` gebauten
     * Instanzen verwenden, sofern ihnen nicht explizit ein eigener Logger
     * übergeben wird. Wird einmal aus Routes.php mit dem Container-Logger
     * aufgerufen, bevor die Routen registriert werden.
     *
     * `null` setzt den Zustand explizit zurück (kein Ersatzwert aus dem zuletzt
     * gesetzten Logger) - wichtig für Tests, die einen eigenen Logger setzen
     * und ihn danach wieder entfernen müssen, statt ihn für den Rest des
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

        // Jedes Gate wird eigenständig geprüft: früher hat allowVoiceGroupReps die
        // Prüfung auf can_manage_users komplett übersprungen, sodass eine Kombination
        // der Schalter stillschweigend Rechte verschenkt hätte.
        foreach ($this->activeGates as $gate) {
            $definition = self::GATES[$gate];

            if (!$this->hasAnyPermission($definition['permissions'])) {
                return $this->deny($request, $definition['message'], $definition['logged_permission']);
            }
        }

        $userLevel = (int) ($_SESSION['role_level'] ?? 0);
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
     * Ein einziges der genannten Rechte genügt. Ein fehlender Eintrag zählt
     * wie ein abgeschaltetes Recht - genau wie die früheren Einzelabfragen mit
     * `$_SESSION[...] ?? false`.
     *
     * @param list<string> $permissions
     */
    private function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!empty($_SESSION[$permission])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Einziger Ausgang für alle Rechte-Abweisungen dieser Middleware: baut die
     * 403-Antwort und protokolliert authz.denied genau einmal pro Abweisung.
     * Benutzerkennung und IP kommen über den Request-Kontext und werden hier
     * nicht wiederholt.
     */
    private function deny(Request $request, string $message, string $permission): Response
    {
        $response = new SlimResponse();

        $this->logger->info('Access denied.', [
            'event' => 'authz.denied',
            'permission' => $permission,
        ]);

        // Die Oberfläche ruft rechtegeschützte Routen per fetch auf und wertet JSON aus.
        // Als text/plain blieb der eigentliche Grund unsichtbar: newsletters.js prüfte den
        // Inhaltstyp und fiel auf ein pauschales "Speichern fehlgeschlagen." zurück.
        // `error` liest newsletters.js, `message` liest users.js - beide tragen denselben
        // Text, damit kein Aufrufer angepasst werden muss.
        if ($this->expectsJson($request)) {
            $response->getBody()->write((string) json_encode([
                'error' => $message,
                'message' => $message,
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        $response->getBody()->write($message);

        // Ohne Zeichensatzangabe zeigt der Browser die Umlaute der Meldung als
        // Ersatzzeichen an - `X-Content-Type-Options: nosniff` verbietet ihm das Raten.
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withStatus(403);
    }

    /**
     * Gleiche Erkennung wie in den Controllern (siehe `expectsJson` dort): die
     * Oberfläche schickt je nach Aufrufstelle nur `X-Requested-With` (etwa
     * newsletters.js) oder zusätzlich `Accept` (etwa users.js).
     */
    private function expectsJson(Request $request): bool
    {
        if (strtolower(trim($request->getHeaderLine('X-Requested-With'))) === 'xmlhttprequest') {
            return true;
        }

        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }
}
