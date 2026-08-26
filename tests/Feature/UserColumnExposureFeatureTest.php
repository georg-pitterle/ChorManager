<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Queries\ProjectQuery;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Project;
use App\Models\VoiceGroup;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Listenabfragen reichen ihre User-Modelle unverändert an die View-Schicht durch.
 * Sie dürfen deshalb nur die Spalten laden, die eine Liste wirklich braucht -
 * insbesondere nie den Passwort-Hash.
 */
class UserColumnExposureFeatureTest extends TestCase
{
    private int $projectId = 0;
    private int $sopranId = 0;
    private string $memberEmail = '';

    /** @var array<string, int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        $this->sopranId = (int) VoiceGroup::where('name', 'Sopran')->firstOrFail()->id;

        $project = Project::create(['name' => 'Adventkonzert ' . bin2hex(random_bytes(4))]);
        $this->projectId = (int) $project->id;

        $people = [
            'mitglied' => ['Anna', 'Alt', 1, true, true],
            'ohneProjekt' => ['Berta', 'Bass', 1, false, true],
            'archiviert' => ['Clara', 'Chor', 0, false, false],
        ];

        foreach ($people as $key => [$firstName, $lastName, $isActive, $inProject, $inVoiceGroup]) {
            $email = strtolower($key) . '_' . bin2hex(random_bytes(4)) . '@example.test';
            $user = User::create([
                'email' => $email,
                // Der Hash darf in keiner Listenabfrage auftauchen - genau das prüft die Klasse.
                'password' => '$2y$12$souldneverleavethequerylayer',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => $isActive,
            ]);
            $this->userIds[$key] = (int) $user->id;

            if ($key === 'mitglied') {
                $this->memberEmail = $email;
                $user->last_project_id = $this->projectId;
                $user->save();
            }

            if ($inProject) {
                Capsule::table('project_users')->insert([
                    'user_id' => $user->id,
                    'project_id' => $this->projectId,
                ]);
            }

            if ($inVoiceGroup) {
                Capsule::table('user_voice_groups')->insert([
                    'user_id' => $user->id,
                    'voice_group_id' => $this->sopranId,
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

        parent::tearDown();
    }

    /**
     * @return array<string, Collection>
     */
    private function listResults(): array
    {
        $userQuery = new UserQuery(new NameFormatterService());
        $projectQuery = new ProjectQuery(new NameFormatterService());

        return [
            'UserQuery::getAllUsers' => $userQuery->getAllUsers(),
            'UserQuery::getArchivedUsers' => $userQuery->getArchivedUsers(),
            'ProjectQuery::getProjectMembers' => $projectQuery->getProjectMembers($this->projectId),
            'ProjectQuery::getUsersNotInProject' => $projectQuery->getUsersNotInProject($this->projectId),
            'ProjectQuery::getUsersNotInProjectForVoiceGroups'
                => $projectQuery->getUsersNotInProjectForVoiceGroups($this->projectId, [$this->sopranId]),
        ];
    }

    public function testListQueriesNeverLoadThePasswordHash(): void
    {
        foreach ($this->listResults() as $label => $users) {
            $this->assertGreaterThan(0, $users->count(), $label . ' liefert keine Mitglieder zum Prüfen.');

            foreach ($users as $user) {
                $this->assertArrayNotHasKey(
                    'password',
                    $user->getAttributes(),
                    $label . ' lädt den Passwort-Hash in die View-Schicht.'
                );
            }
        }
    }

    public function testListQueriesStillProvideTheColumnsTheViewsNeed(): void
    {
        foreach ($this->listResults() as $label => $users) {
            foreach ($users as $user) {
                foreach (['id', 'email', 'first_name', 'last_name', 'is_active'] as $column) {
                    $this->assertArrayHasKey(
                        $column,
                        $user->getAttributes(),
                        $label . ' lädt die von den Listen benötigte Spalte ' . $column . ' nicht.'
                    );
                }
            }
        }
    }

    public function testListColumnsExcludeTheSensitiveOnes(): void
    {
        $this->assertNotContains('password', User::LIST_COLUMNS);
        $this->assertContains('id', User::LIST_COLUMNS, 'Ohne id lassen sich keine Relationen zuordnen.');
    }

    public function testAuthenticationLookupsStillLoadThePasswordHash(): void
    {
        $userQuery = new UserQuery(new NameFormatterService());

        $byEmail = $userQuery->findByEmail($this->memberEmail);
        $this->assertNotNull($byEmail);
        $this->assertArrayHasKey(
            'password',
            $byEmail->getAttributes(),
            'findByEmail() ist der Login-Pfad und braucht den Hash weiterhin.'
        );

        $byId = $userQuery->findById($this->userIds['mitglied']);
        $this->assertNotNull($byId);
        $this->assertArrayHasKey('password', $byId->getAttributes());
    }

    public function testRelationsStillResolveOnTheReducedSelection(): void
    {
        $members = (new ProjectQuery(new NameFormatterService()))->getProjectMembers($this->projectId);

        $this->assertCount(1, $members);
        $this->assertSame($this->userIds['mitglied'], (int) $members->first()->id);
        $this->assertSame('Sopran', $members->first()->voiceGroups->first()->name);
    }
}
