<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

class ProjectMemberArchivedFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->boolean('is_active')->default(true);
        });
        $schema->create('projects', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('project_users', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('project_id');
        });
        $schema->create('voice_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('sub_voices', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('voice_group_id');
            $table->string('name');
        });
        $schema->create('user_voice_groups', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('voice_group_id');
            $table->integer('sub_voice_id')->nullable();
        });

        Capsule::table('users')->insert([
            [
                'id' => 1,
                'email' => 'active@example.test',
                'first_name' => 'Anna',
                'last_name' => 'Aktiv',
                'is_active' => 1,
            ],
            [
                'id' => 2,
                'email' => 'archived@example.test',
                'first_name' => 'Bernd',
                'last_name' => 'Archiviert',
                'is_active' => 0,
            ],
            [
                'id' => 3,
                'email' => 'member@example.test',
                'first_name' => 'Clara',
                'last_name' => 'Chor',
                'is_active' => 1,
            ],
            [
                'id' => 4,
                'email' => 'archived-member@example.test',
                'first_name' => 'Dora',
                'last_name' => 'Dauerpause',
                'is_active' => 0,
            ],
        ]);
        Capsule::table('projects')->insert([
            ['id' => 10, 'name' => 'Adventkonzert'],
        ]);
        Capsule::table('project_users')->insert([
            ['user_id' => 3, 'project_id' => 10],
            // Archiviert, aber weiterhin dem Projekt zugeordnet.
            ['user_id' => 4, 'project_id' => 10],
        ]);
        Capsule::table('voice_groups')->insert([
            ['id' => 4, 'name' => 'Bass'],
        ]);
        Capsule::table('user_voice_groups')->insert([
            ['user_id' => 3, 'voice_group_id' => 4, 'sub_voice_id' => null],
            ['user_id' => 4, 'voice_group_id' => 4, 'sub_voice_id' => null],
        ]);
    }

    public function testUsersNotInProjectIncludesArchivedMembers(): void
    {
        $query = new ProjectQuery(new \App\Services\NameFormatterService());

        $available = $query->getUsersNotInProject(10);
        $ids = $available->pluck('id')->map(fn($id) => (int) $id)->all();

        $this->assertContains(1, $ids, 'Aktive Mitglieder müssen auswählbar bleiben.');
        $this->assertContains(2, $ids, 'Archivierte Mitglieder müssen auswählbar sein.');
        $this->assertNotContains(3, $ids, 'Bereits zugeordnete Mitglieder dürfen nicht erscheinen.');
    }

    /**
     * Ein archiviertes Mitglied bleibt dem Projekt zugeordnet. Filtert die
     * Mitgliederliste es weg, taucht es nirgends mehr auf - weder als Mitglied
     * noch als Kandidat, denn getUsersNotInProject() blendet Zugeordnete aus -
     * und lässt sich damit auch nicht mehr entfernen.
     */
    public function testProjectMembersIncludeArchivedMembers(): void
    {
        $query = new ProjectQuery(new \App\Services\NameFormatterService());

        $ids = $query->getProjectMembers(10)->pluck('id')->map(fn($id) => (int) $id)->all();

        $this->assertContains(3, $ids, 'Aktive Mitglieder müssen in der Liste stehen.');
        $this->assertContains(4, $ids, 'Archivierte Mitglieder dürfen nicht aus der Liste fallen.');
    }

    public function testMembersViewMarksArchivedMembersAndKeepsThemRemovable(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_project_members'] = true;

        $captured = [];
        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())
            ->method('render')
            ->willReturnCallback(
                function ($response, $template, $data) use (&$captured) {
                    $captured = $data;
                    return $response;
                }
            );

        $controller = new ProjectController(
            $twig,
            new ProjectQuery(new \App\Services\NameFormatterService()),
            $this->createStub(ProjectPersistence::class),
            new ProjectMemberPolicy()
        );

        $controller->showMembers($this->makeRequest('GET', '/projects/10/members'), $this->makeResponse(), ['id' => '10']);

        $members = [];
        foreach ($captured['members'] as $member) {
            $members[(int) $member['id']] = $member;
        }

        $this->assertArrayHasKey(4, $members, 'Das archivierte Mitglied muss sichtbar sein.');
        $this->assertFalse($members[4]['is_active'], 'Es muss als archiviert erkennbar sein.');
        $this->assertTrue($members[4]['can_remove'], 'Es muss entfernt werden können.');
        $this->assertTrue($members[3]['is_active']);
    }

    public function testAddProjectMemberReactivatesArchivedUser(): void
    {
        $persistence = new ProjectPersistence();

        $reactivated = $persistence->addProjectMember(10, 2);

        $this->assertTrue($reactivated);
        $this->assertSame(1, (int) User::find(2)->is_active);
        $this->assertSame(1, Capsule::table('project_users')->where('user_id', 2)->where('project_id', 10)->count());
    }

    public function testAddProjectMemberLeavesActiveUserUnchanged(): void
    {
        $persistence = new ProjectPersistence();

        $reactivated = $persistence->addProjectMember(10, 1);

        $this->assertFalse($reactivated);
        $this->assertSame(1, (int) User::find(1)->is_active);
    }

    public function testAddMemberReportsReactivationInSuccessMessage(): void
    {
        $twig = $this->createStub(Twig::class);
        $projectQuery = $this->createStub(ProjectQuery::class);
        $projectQuery->method('userExists')->willReturn(true);

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->once())
            ->method('addProjectMember')
            ->with(10, 2)
            ->willReturn(true);

        $policy = $this->createMock(ProjectMemberPolicy::class);
        $policy->expects($this->once())
            ->method('canAddMember')
            ->with(10)
            ->willReturn(true);
        // The voice-group scope check passes for a broad manager.
        $policy->expects($this->once())
            ->method('canManageMember')
            ->with(10, [])
            ->willReturn(true);

        $controller = new ProjectController($twig, $projectQuery, $projectPersistence, $policy);
        $request = $this->makeRequest('POST', '/projects/10/members', ['user_id' => 2]);
        $response = $this->makeResponse();

        $result = $controller->addMember($request, $response, ['id' => '10']);

        $this->assertRedirect($result, '/projects/10/members');
        $this->assertSame(
            'Mitglied dem Projekt hinzugefügt und wieder aktiviert.',
            $_SESSION['success'] ?? null
        );
    }

    /**
     * Der Dev-Seed muss den Fall erzeugen, sonst lässt sich die Mitgliederliste
     * in Dev nicht mit einer archivierten Zuordnung ansehen.
     */
    public function testDevSeedCreatesArchivedProjectMemberships(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertIsString($content);
        $this->assertStringContainsString("'project_users_archived' => 0,", $content);
        $this->assertStringContainsString('function seedArchivedProjectMembers', $content);
        $this->assertStringContainsString("\$this->seedArchivedProjectMembers(\$projects, \$archivedUsers)", $content);
    }

    public function testMembersTemplateUsesSearchableSelectAndMarksArchivedUsers(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/projects/members.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('data-tom-select', $template);
        $this->assertStringContainsString('/vendor/tom-select/js/tom-select.complete.min.js', $template);
        $this->assertStringContainsString('/vendor/tom-select/css/tom-select.bootstrap5.min.css', $template);
        $this->assertStringContainsString('/js/tom-select-init.js', $template);
        $this->assertStringContainsString('Archiviert', $template);
    }
}
