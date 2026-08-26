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
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Id-Listen der Query- und Policy-Schicht müssen ein unbekanntes Mitglied
 * verkraften.
 *
 * Zeigt die Session auf ein Konto, das es nicht mehr gibt - gelöschtes Mitglied,
 * Session aus einem anderen Datenbestand -, darf die Projektliste nicht mit
 * einem Fatal Error abbrechen, sondern muss ohne eigene Projekte rendern.
 */
class ProjectQueryMissingUserFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private int $memberId = 0;
    private int $multiProjectMemberId = 0;
    private int $deletedUserId = 0;
    private int $ownProject = 0;
    private int $foreignProject = 0;
    private int $alto = 0;
    private int $tenor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];

        $this->alto = (int) VoiceGroup::where('name', 'Alt')->firstOrFail()->id;
        $this->tenor = (int) VoiceGroup::where('name', 'Tenor')->firstOrFail()->id;

        $member = $this->createUser('Marlene', 'Größing');
        $multi = $this->createUser('Konrad', 'Vielsinger');
        $this->memberId = (int) $member->id;
        $this->multiProjectMemberId = (int) $multi->id;

        // Eine Kennung, die es nachweislich nicht gibt: der Test steht und fällt damit, dass
        // sie auch in der geteilten Datenbank auf kein Konto zeigt.
        $this->deletedUserId = ((int) User::query()->max('id')) + 1000;

        $this->ownProject = (int) Project::create([
            'name' => 'Frühjahrskonzert ' . bin2hex(random_bytes(4)),
        ])->id;
        $this->foreignProject = (int) Project::create([
            'name' => 'Adventsingen ' . bin2hex(random_bytes(4)),
        ])->id;

        Capsule::table('project_users')->insert([
            ['user_id' => $this->memberId, 'project_id' => $this->ownProject],
            ['user_id' => $this->multiProjectMemberId, 'project_id' => $this->ownProject],
            ['user_id' => $this->multiProjectMemberId, 'project_id' => $this->foreignProject],
        ]);
        Capsule::table('user_voice_groups')->insert([
            ['user_id' => $this->memberId, 'voice_group_id' => $this->alto, 'sub_voice_id' => null],
            ['user_id' => $this->multiProjectMemberId, 'voice_group_id' => $this->alto, 'sub_voice_id' => null],
            ['user_id' => $this->multiProjectMemberId, 'voice_group_id' => $this->tenor, 'sub_voice_id' => null],
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

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'email' => 'projectquery_' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => 1,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function projectIds(mixed $projects): array
    {
        $ids = [];
        foreach ($projects as $project) {
            $ids[] = (int) (is_array($project) ? $project['id'] : $project->id);
        }

        return $ids;
    }

    public function testVoiceGroupIdsOfAMemberComeBackAsIntegers(): void
    {
        $query = new ProjectQuery(new NameFormatterService());

        $this->assertSame([$this->alto], $query->getUserVoiceGroupIds($this->memberId));
    }

    /**
     * Ab der zweiten Id greift die Umwandlung auf einen anderen Schlüssel zu -
     * genau dort verrutschte eine Zahlenbasis-basierte Umwandlung.
     */
    public function testEveryVoiceGroupIdOfAMemberWithTwoVoiceGroupsSurvivesTheConversion(): void
    {
        $query = new ProjectQuery(new NameFormatterService());

        $ids = $query->getUserVoiceGroupIds($this->multiProjectMemberId);
        sort($ids);

        $this->assertSame([min($this->alto, $this->tenor), max($this->alto, $this->tenor)], $ids);
    }

    public function testUnknownMemberHasNoVoiceGroupIds(): void
    {
        $query = new ProjectQuery(new NameFormatterService());

        $this->assertSame([], $query->getUserVoiceGroupIds($this->deletedUserId));
    }

    public function testIndexListsTheOwnProjectsOfAVoiceGroupScopedMember(): void
    {
        $_SESSION['user_id'] = $this->memberId;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;

        $data = $this->renderIndex();

        // Die Liste zeigt alle Projekte; gegen die geteilte Datenbank zählt daher nicht ihre
        // Gesamtzahl, sondern dass beide Testprojekte darin vorkommen.
        $this->assertContains($this->ownProject, $this->projectIds($data['projects']));
        $this->assertContains($this->foreignProject, $this->projectIds($data['projects']));
        $this->assertSame([$this->ownProject], $data['memberManagedProjectIds']);
    }

    public function testIndexRendersWhenTheSessionUserNoLongerExists(): void
    {
        $_SESSION['user_id'] = $this->deletedUserId;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;

        $data = $this->renderIndex();

        // Die Liste zeigt alle Projekte; gegen die geteilte Datenbank zählt daher nicht ihre
        // Gesamtzahl, sondern dass beide Testprojekte darin vorkommen.
        $this->assertContains($this->ownProject, $this->projectIds($data['projects']));
        $this->assertContains($this->foreignProject, $this->projectIds($data['projects']));
        $this->assertSame([], $data['memberManagedProjectIds']);
    }

    public function testIndexRendersWithoutAUserIdInTheSession(): void
    {
        $_SESSION['can_assign_own_voice_group_to_project'] = true;

        $data = $this->renderIndex();

        // Die Liste zeigt alle Projekte; gegen die geteilte Datenbank zählt daher nicht ihre
        // Gesamtzahl, sondern dass beide Testprojekte darin vorkommen.
        $this->assertContains($this->ownProject, $this->projectIds($data['projects']));
        $this->assertContains($this->foreignProject, $this->projectIds($data['projects']));
        $this->assertSame([], $data['memberManagedProjectIds']);
    }

    /**
     * @return array<string,mixed>
     */
    private function renderIndex(): array
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

        $controller = new ProjectController(
            $twig,
            new ProjectQuery(new NameFormatterService()),
            $this->createStub(ProjectPersistence::class),
            new ProjectMemberPolicy()
        );

        $controller->index($this->makeRequest('GET', '/projects'), $this->makeResponse());

        return $captured;
    }
}
