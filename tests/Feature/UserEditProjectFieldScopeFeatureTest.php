<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Policies\UserEditPolicy;
use App\Queries\UserQuery;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Das Bearbeiten-Formular bietet die Projektzuordnung genau dem an, der sie
 * auch speichern darf.
 *
 * Gefunden im Review-Lauf 59: `editForm()` setzte `can_edit_users` für jede
 * Sitzung ohne `can_manage_users` hart auf `true`, und das Formular schaltete
 * die Projekt-Kästchen über genau diesen Wert frei. Eine Stimmvertretung - sie
 * pflegt die eigene Stimmgruppe, hält aber weder `can_edit_users` noch
 * `can_manage_project_members` - sah die Kästchen deshalb, konnte sie anklicken
 * und bekam nach dem Speichern "Mitglied erfolgreich aktualisiert." zu lesen.
 * Geschrieben wurde die Zuordnung nicht: `update()` fragt dafür
 * UserEditPolicy::canEditProjects(), und die sagte nein. Ein stilles Verwerfen -
 * genau die Art Lücke, die die Policy schließen soll.
 *
 * Geprüft wird am gerenderten Formular, nicht am Quelltext der Vorlage: Die
 * Freigabe entsteht erst aus dem Zusammenspiel von Controller und Vorlage, und
 * eine Zeichenkettensuche im Twig hätte den hart gesetzten Wert im Controller
 * nicht gesehen.
 */
final class UserEditProjectFieldScopeFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private VoiceGroup $ownGroup;
    private Role $memberRole;
    private User $target;
    private Project $project;
    private Twig $twig;

    /** @var array<string, mixed> */
    private array $originalSession = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->originalSession = $_SESSION ?? [];

        // Vor dem Setzen der Sitzung: Der Twig-Aufbau ruft
        // SessionConfig::ensureStarted(), und session_start() ersetzt $_SESSION
        // durch den gespeicherten (leeren) Stand. Danach gebaut, stünde der
        // Controller ohne Rechte da - und der Test hätte eine Abweisung gemessen,
        // die nichts mit der Freigabe des Projektfelds zu tun hat.
        $this->twig = $this->buildTwig();
        Bootstrap::setupTestDatabase();

        $suffix = bin2hex(random_bytes(4));
        $this->ownGroup = VoiceGroup::create(['name' => 'Projektfeld-Gruppe ' . $suffix]);
        $this->memberRole = Role::create(['name' => 'Projektfeld-Mitglied ' . $suffix, 'hierarchy_level' => 10]);
        $this->project = Project::create([
            'name' => 'Projektfeld-Projekt ' . $suffix,
            'description' => 'Läuft, damit die Vorlage es anbietet',
            'start_date' => '2020-01-01',
            'end_date' => null,
        ]);

        $this->target = User::create([
            'first_name' => 'Ziel',
            'last_name' => 'Person',
            'email' => 'projektfeld.' . $suffix . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
        $this->target->voiceGroups()->attach($this->ownGroup->id);
        $this->target->roles()->attach($this->memberRole->id);
    }

    protected function tearDown(): void
    {
        $this->target->projects()->detach();
        $this->target->voiceGroups()->detach();
        $this->target->roles()->detach();
        $this->target->delete();
        $this->project->users()->detach();
        $this->project->delete();
        $this->memberRole->delete();
        $this->ownGroup->delete();

        $_SESSION = $this->originalSession;

        parent::tearDown();
    }

    /**
     * Die Stimmvertretung: eigene Stimmgruppe ja, Projektzuordnung nein.
     */
    private function voiceGroupManagerSession(): array
    {
        return [
            'user_id' => 999101,
            'can_manage_users' => false,
            'can_edit_users' => false,
            'can_manage_project_members' => false,
            'can_manage_own_voice_group' => true,
            'role_level' => 50,
            'voice_group_ids' => [(int) $this->ownGroup->id],
        ];
    }

    private function buildTwig(): Twig
    {
        $builder = new ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($builder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($builder);

        $container = $builder->build();
        $container->get(Capsule::class);

        return $container->get(Twig::class);
    }

    private function renderEditForm(): string
    {
        $controller = new UserController(
            $this->twig,
            new UserQuery(new NameFormatterService()),
            new UserPersistence(new Logger('test')),
            new ProjectPersistence(),
            $this->createStub(MailQueueService::class),
            new Logger('test'),
            new UserEditPolicy($_SESSION)
        );

        $response = $controller->editForm(
            $this->makeRequest('GET', '/users/' . $this->target->id . '/edit-form'),
            $this->makeResponse(),
            ['id' => (string) $this->target->id]
        );

        return (string) $response->getBody();
    }

    public function testVoiceGroupManagerIsNotOfferedTheProjectAssignment(): void
    {
        $_SESSION = $this->voiceGroupManagerSession();

        $html = $this->renderEditForm();

        // Das Formular steht - nur eben ohne die Projekt-Kästchen.
        self::assertStringContainsString('name="first_name"', $html);
        self::assertStringNotContainsString(
            'name="projects[]"',
            $html,
            'Die Projektzuordnung darf nicht angeboten werden, wenn update() sie verwirft.'
        );
    }

    public function testProjectMemberManagerIsStillOfferedTheProjectAssignment(): void
    {
        $_SESSION = [
            'user_id' => 999102,
            'can_manage_users' => false,
            'can_edit_users' => false,
            'can_manage_project_members' => true,
            'can_manage_own_voice_group' => false,
            'role_level' => 80,
            'voice_group_ids' => [],
        ];

        self::assertStringContainsString('name="projects[]"', $this->renderEditForm());
    }

    /**
     * Das Bearbeiten-Recht allein trägt die Projektzuordnung nicht. Es stand dafür
     * bis Lauf 59 als rohes Sitzungsflag in `update()` neben der Policy; aufgelöst
     * ist das zugunsten der Policy, also zugunsten von can_manage_project_members.
     *
     * Für die ausgelieferten Rollen ändert das nichts - Admin und Vorstand halten
     * beide Rechte. Eine eigens gebaute Rolle mit "Mitglieder editieren erlauben",
     * aber ohne "Projektmitglieder verwalten", bekommt das Feld jetzt nicht mehr
     * zu sehen und schreibt es auch nicht.
     */
    public function testUserEditorAloneIsNotOfferedTheProjectAssignment(): void
    {
        $_SESSION = [
            'user_id' => 999103,
            'can_manage_users' => true,
            'can_edit_users' => true,
            'can_manage_project_members' => false,
            'can_manage_own_voice_group' => false,
            'role_level' => 100,
            'voice_group_ids' => [],
        ];

        self::assertFalse(
            (new UserEditPolicy($_SESSION))->canEditProjects($this->target),
            'Die Projektzuordnung gehört can_manage_project_members.'
        );

        $html = $this->renderEditForm();

        // Das Formular steht - nur eben ohne die Projekt-Kästchen.
        self::assertStringContainsString('name="first_name"', $html);
        self::assertStringNotContainsString('name="projects[]"', $html);
    }

    /**
     * Das Anlegen setzt ein Mitglied gleich ins laufende Projekt und stand dafür
     * auf einer vierten, selbst zusammengesetzten Fassung derselben Regel
     * (`can_edit_users or can_manage_project_members`). Es folgt jetzt derselben
     * Policy wie das Bearbeiten.
     */
    public function testOnlyProjectMemberManagersMayAssignProjectsWhenCreating(): void
    {
        $editorOnly = [
            'can_edit_users' => true,
            'can_manage_project_members' => false,
        ];
        $projectManager = [
            'can_edit_users' => false,
            'can_manage_project_members' => true,
        ];

        self::assertFalse((new UserEditPolicy($editorOnly))->canAssignProjectsOnCreate());
        self::assertTrue((new UserEditPolicy($projectManager))->canAssignProjectsOnCreate());
        self::assertFalse((new UserEditPolicy([]))->canAssignProjectsOnCreate());
    }

    /**
     * Die Gegenprobe zum stillen Verwerfen: Eine Stimmvertretung, die den POST
     * trotzdem absetzt, ändert die Zuordnung nicht - und bekommt das Formular
     * dafür gar nicht erst zu sehen.
     */
    public function testVoiceGroupManagerPostDoesNotWriteTheProjectAssignment(): void
    {
        $_SESSION = $this->voiceGroupManagerSession();

        $controller = new UserController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new UserPersistence(new Logger('test')),
            new ProjectPersistence(),
            $this->createStub(MailQueueService::class),
            new Logger('test'),
            new UserEditPolicy($_SESSION)
        );

        $controller->update(
            $this->makeRequest('POST', '/users/' . $this->target->id, [
                'first_name' => 'Ziel',
                'last_name' => 'Person',
                'email' => (string) $this->target->email,
                'roles' => [(string) $this->memberRole->id],
                'voice_groups' => [(string) $this->ownGroup->id],
                'projects' => [(string) $this->project->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $this->target->id]
        );

        self::assertSame(
            [],
            $this->target->fresh()->projects->pluck('id')->all(),
            'Ohne das Recht bleibt die Projektzuordnung unberührt.'
        );
    }
}
