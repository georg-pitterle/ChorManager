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
        ]);
        Capsule::table('projects')->insert([
            ['id' => 10, 'name' => 'Adventkonzert'],
        ]);
        Capsule::table('project_users')->insert([
            ['user_id' => 3, 'project_id' => 10],
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
        $policy->method('canManageMember')
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
