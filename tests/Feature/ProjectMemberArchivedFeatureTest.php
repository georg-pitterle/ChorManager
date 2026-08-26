<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use App\Models\Project;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Persistence\ProjectPersistence;
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class ProjectMemberArchivedFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private int $projectId = 0;

    /** @var array<string, int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];

        $bass = VoiceGroup::where('name', 'Bass')->firstOrFail();

        $people = [
            'aktiv' => ['Anna', 'Aktiv', 1, false],
            'archiviertOhneProjekt' => ['Bernd', 'Archiviert', 0, false],
            'mitglied' => ['Clara', 'Chor', 1, true],
            'archiviertesMitglied' => ['Dora', 'Dauerpause', 0, true],
        ];

        $project = Project::create(['name' => 'Adventkonzert ' . bin2hex(random_bytes(4))]);
        $this->projectId = (int) $project->id;

        foreach ($people as $key => [$firstName, $lastName, $isActive, $inProject]) {
            $user = User::create([
                'email' => strtolower($key) . '_' . bin2hex(random_bytes(4)) . '@example.test',
                'password' => password_hash('secret', PASSWORD_BCRYPT),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => $isActive,
            ]);
            $this->userIds[$key] = (int) $user->id;

            if ($inProject) {
                Capsule::table('project_users')->insert([
                    'user_id' => $user->id,
                    'project_id' => $this->projectId,
                ]);
                Capsule::table('user_voice_groups')->insert([
                    'user_id' => $user->id,
                    'voice_group_id' => $bass->id,
                    'sub_voice_id' => null,
                ]);
            }
        }
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

    private function id(string $key): int
    {
        return $this->userIds[$key];
    }

    public function testUsersNotInProjectIncludesArchivedMembers(): void
    {
        $query = new ProjectQuery(new \App\Services\NameFormatterService());

        $available = $query->getUsersNotInProject($this->projectId);
        $ids = $available->pluck('id')->map(fn($id) => (int) $id)->all();

        $this->assertContains($this->id('aktiv'), $ids, 'Aktive Mitglieder müssen auswählbar bleiben.');
        $this->assertContains(
            $this->id('archiviertOhneProjekt'),
            $ids,
            'Archivierte Mitglieder müssen auswählbar sein.'
        );
        $this->assertNotContains(
            $this->id('mitglied'),
            $ids,
            'Bereits zugeordnete Mitglieder dürfen nicht erscheinen.'
        );
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

        $ids = $query->getProjectMembers($this->projectId)->pluck('id')->map(fn($id) => (int) $id)->all();

        $this->assertContains($this->id('mitglied'), $ids, 'Aktive Mitglieder müssen in der Liste stehen.');
        $this->assertContains(
            $this->id('archiviertesMitglied'),
            $ids,
            'Archivierte Mitglieder dürfen nicht aus der Liste fallen.'
        );
    }

    public function testMembersViewMarksArchivedMembersAndKeepsThemRemovable(): void
    {
        $_SESSION['user_id'] = $this->id('aktiv');
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

        $controller->showMembers(
            $this->makeRequest('GET', '/projects/' . $this->projectId . '/members'),
            $this->makeResponse(),
            ['id' => (string) $this->projectId]
        );

        $members = [];
        foreach ($captured['members'] as $member) {
            $members[(int) $member['id']] = $member;
        }

        $archived = $this->id('archiviertesMitglied');
        $this->assertArrayHasKey($archived, $members, 'Das archivierte Mitglied muss sichtbar sein.');
        $this->assertFalse($members[$archived]['is_active'], 'Es muss als archiviert erkennbar sein.');
        $this->assertTrue($members[$archived]['can_remove'], 'Es muss entfernt werden können.');
        $this->assertTrue($members[$this->id('mitglied')]['is_active']);
    }

    public function testAddProjectMemberReactivatesArchivedUser(): void
    {
        $persistence = new ProjectPersistence();

        $archived = $this->id('archiviertOhneProjekt');
        $reactivated = $persistence->addProjectMember($this->projectId, $archived);

        $this->assertTrue($reactivated);
        $this->assertSame(1, (int) User::find($archived)->is_active);
        $this->assertSame(
            1,
            Capsule::table('project_users')
                ->where('user_id', $archived)
                ->where('project_id', $this->projectId)
                ->count()
        );
    }

    public function testAddProjectMemberLeavesActiveUserUnchanged(): void
    {
        $persistence = new ProjectPersistence();

        $active = $this->id('aktiv');
        $reactivated = $persistence->addProjectMember($this->projectId, $active);

        $this->assertFalse($reactivated);
        $this->assertSame(1, (int) User::find($active)->is_active);
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
