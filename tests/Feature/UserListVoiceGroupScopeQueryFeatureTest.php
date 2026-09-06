<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceGroup;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Wer nur die eigene Stimmgruppe verwalten darf, bekommt die Einschränkung aus der
 * Abfrage - nicht aus einem filter() über alle aktiven Mitglieder.
 *
 * Das Ergebnis war vorher dasselbe, der Weg dorthin aber nicht: die Datenbank
 * lieferte erst jedes aktive Mitglied samt Rollen, Stimmgruppen und Projekten aus,
 * und PHP warf den größten Teil davon wieder weg.
 */
class UserListVoiceGroupScopeQueryFeatureTest extends TestCase
{
    private int $sopranId = 0;
    private int $altId = 0;

    /** @var array<string, int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        $this->sopranId = (int) VoiceGroup::where('name', 'Sopran')->firstOrFail()->id;
        $this->altId = (int) VoiceGroup::where('name', 'Alt')->firstOrFail()->id;

        $people = [
            'sopranAktiv' => ['Sonja', 'Sopran', 1, $this->sopranId],
            'sopranArchiviert' => ['Sabine', 'Still', 0, $this->sopranId],
            'altAktiv' => ['Alma', 'Alt', 1, $this->altId],
            'ohneStimmgruppe' => ['Olga', 'Ohne', 1, null],
        ];

        foreach ($people as $key => [$firstName, $lastName, $isActive, $voiceGroupId]) {
            $user = User::create([
                'email' => strtolower($key) . '_' . bin2hex(random_bytes(4)) . '@example.test',
                'password' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => $isActive,
            ]);
            $this->userIds[$key] = (int) $user->id;

            if ($voiceGroupId !== null) {
                Capsule::table('user_voice_groups')->insert([
                    'user_id' => $user->id,
                    'voice_group_id' => $voiceGroupId,
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

    private function query(): UserQuery
    {
        return new UserQuery(new NameFormatterService());
    }

    /**
     * @return list<int>
     */
    private function idsFor(array $voiceGroupIds): array
    {
        return $this->query()->getUsersForVoiceGroups($voiceGroupIds)
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->all();
    }

    public function testOnlyMembersOfTheGivenVoiceGroupsAreReturned(): void
    {
        $ids = $this->idsFor([$this->sopranId]);

        $this->assertContains($this->userIds['sopranAktiv'], $ids);
        $this->assertNotContains($this->userIds['altAktiv'], $ids, 'Fremde Stimmgruppen bleiben draußen.');
        $this->assertNotContains($this->userIds['ohneStimmgruppe'], $ids, 'Ohne Stimmgruppe ist ausserhalb des Rechts.');
    }

    public function testArchivedMembersStayOutOfTheActiveList(): void
    {
        $ids = $this->idsFor([$this->sopranId]);

        $this->assertNotContains(
            $this->userIds['sopranArchiviert'],
            $ids,
            'Die Liste zeigt aktive Mitglieder; das Archiv hat eine eigene Abfrage.'
        );
    }

    public function testSeveralVoiceGroupsAreCombined(): void
    {
        $ids = $this->idsFor([$this->sopranId, $this->altId]);

        $this->assertContains($this->userIds['sopranAktiv'], $ids);
        $this->assertContains($this->userIds['altAktiv'], $ids);
    }

    public function testWithoutVoiceGroupsTheListStaysEmptyAndCostsNoQuery(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        $this->assertNotNull($connection);

        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $users = $this->query()->getUsersForVoiceGroups([]);
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        $this->assertCount(0, $users);
        $this->assertSame([], $queries, 'Ein Ergebnis, das schon feststeht, braucht keine Runde zum Server.');
    }

    /**
     * Das Ergebnis stimmt mit dem überein, was das frühere filter() über alle
     * aktiven Mitglieder geliefert hätte - nur ohne die Mitglieder, die es vorher
     * geladen und wieder verworfen hat.
     */
    public function testMatchesTheResultOfTheFormerInMemoryFilter(): void
    {
        $ownVoiceGroups = [$this->sopranId];

        $filtered = $this->query()->getAllUsers()
            ->filter(function (User $user) use ($ownVoiceGroups): bool {
                return array_intersect($ownVoiceGroups, $user->voiceGroups->pluck('id')->all()) !== [];
            })
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->values()
            ->all();

        $this->assertSame($filtered, $this->idsFor($ownVoiceGroups));
    }

    public function testTheListActionAsksTheQueryInsteadOfFilteringInPhp(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/UserController.php');
        $this->assertIsString($controller);

        $this->assertStringContainsString('$this->userQuery->getUsersForVoiceGroups($myVgs)', $controller);
        $this->assertStringNotContainsString('$uVgIds = $user->voiceGroups->pluck(\'id\')->toArray();', $controller);
    }
}
