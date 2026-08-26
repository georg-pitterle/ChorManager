<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use App\Models\Project;
use App\Controllers\ProjectController;
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;

/**
 * Die Projektliste darf nur Aktionen anbieten, die der Aufrufer auch ausführen
 * darf. Der Mitglieder-Link folgt deshalb derselben Regel wie die Policy:
 * das breite Recht erreicht jedes Projekt, das stimmgruppen-beschränkte Recht
 * nur die eigenen.
 */
class ProjectActionVisibilityFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private const OWN_PROJECT = 1;
    private const FOREIGN_PROJECT = 2;

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
            $table->boolean('is_active')->default(true);
            $table->integer('last_project_id')->nullable();
        });
        $schema->create('projects', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('project_users', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('project_id');
        });

        Capsule::table('users')->insert(['id' => 1, 'email' => 'manager@example.test']);
        Capsule::table('projects')->insert([
            ['id' => self::OWN_PROJECT, 'name' => 'Eigenes Projekt'],
            ['id' => self::FOREIGN_PROJECT, 'name' => 'Fremdes Projekt'],
        ]);
        Capsule::table('project_users')->insert([
            'user_id' => 1,
            'project_id' => self::OWN_PROJECT,
        ]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Capsule::schema()->drop('project_users');
        Capsule::schema()->drop('projects');
        Capsule::schema()->drop('users');
        parent::tearDown();
    }

    /**
     * @return array<string,mixed>
     */
    private function renderData(): array
    {
        $captured = [];

        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())
            ->method('render')
            ->willReturnCallback(
                function ($response, $template, $data) use (&$captured): ResponseInterface {
                    $captured = $data;
                    return $response;
                }
            );

        $projectQuery = $this->createStub(ProjectQuery::class);
        $projectQuery->method('getAllProjects')->willReturn(new Collection([]));

        $controller = new ProjectController(
            $twig,
            $projectQuery,
            $this->createStub(\App\Persistence\ProjectPersistence::class),
            new ProjectMemberPolicy()
        );

        $controller->index($this->makeRequest('GET', '/projects'), $this->makeResponse());

        return $captured;
    }

    public function testBroadManagerSeesTheMemberLinkOnEveryProject(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_project_members'] = true;

        $data = $this->renderData();

        $this->assertSame(
            [self::OWN_PROJECT, self::FOREIGN_PROJECT],
            $data['memberManagedProjectIds'] ?? null
        );
    }

    public function testVoiceGroupScopedRightSeesTheMemberLinkOnlyOnOwnProjects(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;

        $data = $this->renderData();

        $this->assertSame([self::OWN_PROJECT], $data['memberManagedProjectIds'] ?? null);
    }

    public function testWithoutAnyProjectMemberRightNoMemberLinkIsOffered(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = false;

        $data = $this->renderData();

        $this->assertSame([], $data['memberManagedProjectIds'] ?? null);
    }

    public function testProjectListGatesTheMemberLinkOnTheProjectItself(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/projects/index.twig');

        // Das blosse Recht reicht nicht mehr aus: der Link haengt am konkreten Projekt.
        $this->assertStringContainsString('project.id in memberManagedProjectIds', $template);
        $this->assertStringNotContainsString('{% if session.can_manage_project_members %}', $template);
    }

    /**
     * @return array<string,mixed>
     */
    private function evaluationRenderData(int $projectId): array
    {
        $captured = [];

        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())
            ->method('render')
            ->willReturnCallback(
                function ($response, $template, $data) use (&$captured): ResponseInterface {
                    $captured = $data;
                    return $response;
                }
            );

        $projectQuery = $this->createStub(ProjectQuery::class);
        $projectQuery->method('findCurrentProjectId')->willReturn(0);
        $projectQuery->method('getProjectMembersGroupedByVoice')->willReturn([]);
        // can_manage_attendance_all ist gesetzt, die Auswahl umfasst also alle Projekte -
        // genau das lieferte vorher die controllereigene Kopie der Abfrage.
        $projectQuery->method('getAccessibleProjects')
            ->willReturnCallback(static fn (): Collection => Project::orderBy('name')->get());

        $controller = new EvaluationController(
            $twig,
            $projectQuery,
            new NameFormatterService(),
            new ProjectMemberPolicy()
        );

        $controller->projectMembers(
            $this->makeRequest('GET', '/evaluations/project-members', [], ['project_id' => (string) $projectId]),
            $this->makeResponse()
        );

        return $captured;
    }

    public function testEvaluationOffersTheManageButtonOnlyWhereTheMemberPolicyAllowsIt(): void
    {
        $_SESSION['user_id'] = 1;
        // Die Auswertung selbst ist projektuebergreifend sichtbar ...
        $_SESSION['can_manage_attendance_all'] = true;
        // ... die Mitgliederpflege dagegen nur fuer die eigenen Projekte.
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;

        $this->assertTrue($this->evaluationRenderData(self::OWN_PROJECT)['can_manage_members'] ?? null);
        $this->assertFalse($this->evaluationRenderData(self::FOREIGN_PROJECT)['can_manage_members'] ?? null);
    }

    public function testEvaluationTemplateUsesThePolicyFlagInsteadOfTheBareRight(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/evaluations/project_members.twig');

        $this->assertStringContainsString('{% if can_manage_members %}', $template);
        $this->assertStringNotContainsString('{% if session.can_manage_project_members %}', $template);
    }
}
