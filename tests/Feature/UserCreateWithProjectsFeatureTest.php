<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Policies\UserEditPolicy;
use App\Queries\UserQuery;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Projekte schon beim Anlegen zuordnen.
 *
 * Wer nach der ersten Probe einer Saison eine neue Sängerin einträgt, will sie
 * gleich ins laufende Projekt setzen. Bisher hieß das: anlegen, in der Liste
 * suchen, Bearbeiten öffnen, Projekt ankreuzen. Beim Anlegen gilt dieselbe
 * Rechteregel wie beim Bearbeiten - seit Lauf 59 über
 * UserEditPolicy::canAssignProjectsOnCreate() statt über eine eigene Fassung
 * aus der Sitzung, und das Recht dafür ist allein can_manage_project_members.
 */
final class UserCreateWithProjectsFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private Role $role;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $_SESSION = [
            'user_id' => 1,
            'can_manage_users' => true,
            'can_edit_users' => true,
            'role_level' => 100,
            'voice_group_ids' => [],
            'can_manage_project_members' => false,
            'can_manage_own_voice_group' => false,
        ];

        $this->role = Role::create(['name' => 'Anlegen ' . bin2hex(random_bytes(3)), 'hierarchy_level' => 10]);
        $this->project = Project::create([
            'name' => 'Laufendes Projekt ' . bin2hex(random_bytes(3)),
            'start_date' => '2026-09-01',
            'end_date' => '2099-06-30',
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * "Mitglieder editieren erlauben" allein reicht nicht. Die Regel stand hier
     * zuvor anders, und zwar als vierte selbst zusammengesetzte Fassung
     * (`can_edit_users or can_manage_project_members`); aufgelöst ist sie
     * zugunsten der Policy, also zugunsten der Projektbesetzung. Für die
     * ausgelieferten Rollen kostet das nichts - Admin und Vorstand halten beide
     * Rechte ohnehin.
     */
    public function testMemberEditorsAloneDoNotAssignProjectsWhileCreating(): void
    {
        $user = $this->createMember();

        $this->assertSame(
            0,
            $user->projects()->count(),
            'Ohne "Projektmitglieder verwalten" bleibt die Zuordnung leer.'
        );
    }

    public function testProjectMemberManagersAssignWhileCreating(): void
    {
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_project_members'] = true;

        $user = $this->createMember();

        $this->assertSame([(int) $this->project->id], $user->projects()->pluck('projects.id')->map('intval')->all());
    }

    public function testWithoutTheRightTheProjectsAreIgnored(): void
    {
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_project_members'] = false;

        $user = $this->createMember();

        $this->assertSame(0, $user->projects()->count(), 'Ohne Recht dürfen keine Projekte zugeordnet werden.');
    }

    private function createMember(): User
    {
        $email = 'anlegen-' . bin2hex(random_bytes(5)) . '@example.test';

        $controller = new UserController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new UserPersistence(new NullLogger()),
            new ProjectPersistence(),
            $this->createStub(MailQueueService::class),
            new NullLogger(),
            new UserEditPolicy($_SESSION)
        );

        $controller->create(
            $this->makeRequest('POST', '/users', [
                'first_name' => 'Neue',
                'last_name' => 'Sängerin',
                'email' => $email,
                'roles' => [(string) $this->role->id],
                'projects' => [(string) $this->project->id],
                'submit_action' => 'save',
            ]),
            $this->makeResponse()
        );

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user, 'Das Mitglied muss angelegt sein: ' . ($_SESSION['error'] ?? ''));

        return $user;
    }
}
