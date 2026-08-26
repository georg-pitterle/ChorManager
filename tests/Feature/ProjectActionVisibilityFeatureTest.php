<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use App\Controllers\ProjectController;
use App\Models\Project;
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Projektliste darf nur Aktionen anbieten, die der Aufrufer auch ausführen
 * darf. Der Mitglieder-Link folgt deshalb derselben Regel wie die Policy:
 * das breite Recht erreicht jedes Projekt, das stimmgruppen-beschränkte Recht
 * nur die eigenen.
 */
class ProjectActionVisibilityFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private int $managerUserId = 0;
    private int $ownProjectId = 0;
    private int $foreignProjectId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];

        $suffix = bin2hex(random_bytes(4));

        $this->managerUserId = (int) Capsule::table('users')->insertGetId([
            'email' => 'manager' . $suffix . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'first_name' => 'Manager',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);

        $this->ownProjectId = (int) Capsule::table('projects')->insertGetId([
            'name' => 'Eigenes Projekt ' . $suffix,
        ]);
        $this->foreignProjectId = (int) Capsule::table('projects')->insertGetId([
            'name' => 'Fremdes Projekt ' . $suffix,
        ]);

        Capsule::table('project_users')->insert([
            'user_id' => $this->managerUserId,
            'project_id' => $this->ownProjectId,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
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
        $_SESSION['user_id'] = $this->managerUserId;
        $_SESSION['can_manage_project_members'] = true;

        $data = $this->renderData();
        $memberManagedProjectIds = $data['memberManagedProjectIds'] ?? null;

        // Gegen die echte Datenbank gibt es neben den beiden selbst angelegten
        // Projekten auch Bestandsprojekte. Das breite Recht muss trotzdem
        // WIRKLICH jedes Projekt liefern - geprueft ueber die beiden eigenen
        // Ids und ueber die Gesamtzahl, die exakt der Projekttabelle entspricht.
        $this->assertIsArray($memberManagedProjectIds);
        $this->assertContains($this->ownProjectId, $memberManagedProjectIds);
        $this->assertContains($this->foreignProjectId, $memberManagedProjectIds);
        $this->assertCount((int) Project::query()->count(), $memberManagedProjectIds);
    }

    public function testVoiceGroupScopedRightSeesTheMemberLinkOnlyOnOwnProjects(): void
    {
        $_SESSION['user_id'] = $this->managerUserId;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;

        $data = $this->renderData();

        $this->assertSame([$this->ownProjectId], $data['memberManagedProjectIds'] ?? null);
    }

    public function testWithoutAnyProjectMemberRightNoMemberLinkIsOffered(): void
    {
        $_SESSION['user_id'] = $this->managerUserId;
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
        $_SESSION['user_id'] = $this->managerUserId;
        // Die Auswertung selbst ist projektuebergreifend sichtbar ...
        $_SESSION['can_manage_attendance_all'] = true;
        // ... die Mitgliederpflege dagegen nur fuer die eigenen Projekte.
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;

        $this->assertTrue($this->evaluationRenderData($this->ownProjectId)['can_manage_members'] ?? null);
        $this->assertFalse($this->evaluationRenderData($this->foreignProjectId)['can_manage_members'] ?? null);
    }

    public function testEvaluationTemplateUsesThePolicyFlagInsteadOfTheBareRight(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/evaluations/project_members.twig');

        $this->assertStringContainsString('{% if can_manage_members %}', $template);
        $this->assertStringNotContainsString('{% if session.can_manage_project_members %}', $template);
    }
}
