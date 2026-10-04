<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Legt Termin, Projekt, Rolle, Stimmgruppe und Mitglieder selbst an, damit die
 * Erwartungen nicht vom aktuellen Dev-Seed-Stand abhaengen.
 */
class EventEligibleUsersScopeFeatureTest extends TestCase
{
    use AudienceFixtures;
    use EventScopeFixtures;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $this->beginFixtureTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackFixtureTransaction();
        parent::tearDown();
    }

    public function testEveryoneRowMeansAllActiveUsers(): void
    {
        $this->createUser();
        $event = $this->openToEveryone($this->createEvent());

        $this->assertSame(
            (int) User::where('is_active', 1)->count(),
            (int) $event->eligibleUsersQuery()->count()
        );
    }

    public function testProjectMembersSource(): void
    {
        $project = Project::create([
            'name' => 'Scope-Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => Carbon::now()->subMonth()->toDateString(),
            'end_date' => Carbon::now()->addMonth()->toDateString(),
        ]);
        $members = [$this->createUser(), $this->createUser()];
        foreach ($members as $member) {
            $project->users()->attach($member->id);
        }
        $this->createUser();

        $event = $this->createEvent();
        $this->giveAudience('event_id', (int) $event->id, ['project' => [(int) $project->id]]);

        $this->assertSame($this->sortedIds($members), $this->eligibleIds($event));
    }

    public function testRoleSource(): void
    {
        $role = Role::create([
            'name' => 'Scope-Rolle ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 5,
        ]);
        $members = [$this->createUser(), $this->createUser()];
        foreach ($members as $member) {
            $member->roles()->attach($role->id);
        }
        $this->createUser();

        $event = $this->createEvent();
        $this->giveAudience('event_id', (int) $event->id, ['role' => [(int) $role->id]]);

        $this->assertSame($this->sortedIds($members), $this->eligibleIds($event));
    }

    public function testVoiceGroupSource(): void
    {
        $voiceGroup = VoiceGroup::create(['name' => 'Scope-Stimmgruppe ' . bin2hex(random_bytes(4))]);
        $members = [$this->createUser(), $this->createUser()];
        foreach ($members as $member) {
            $member->voiceGroups()->attach($voiceGroup->id);
        }
        $this->createUser();

        $event = $this->createEvent();
        $this->giveAudience('event_id', (int) $event->id, ['voice_group' => [(int) $voiceGroup->id]]);

        $this->assertSame($this->sortedIds($members), $this->eligibleIds($event));
    }

    public function testUserSource(): void
    {
        $user = $this->createUser();
        $this->createUser();

        $event = $this->createEvent();
        $this->giveAudience('event_id', (int) $event->id, ['user' => [(int) $user->id]]);

        $this->assertSame([(int) $user->id], $this->eligibleIds($event));
    }

    public function testMultipleSourcesUnionWithoutDuplicates(): void
    {
        $role = Role::create([
            'name' => 'Union-Rolle ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 5,
        ]);
        $roleMember = $this->createUser();
        $roleMember->roles()->attach($role->id);

        // Dieses Mitglied haengt an beiden Quellen und darf trotzdem nur einmal auftauchen.
        $bothMember = $this->createUser();
        $bothMember->roles()->attach($role->id);

        $event = $this->createEvent();
        $this->giveAudience('event_id', (int) $event->id, ['role' => [(int) $role->id]]);
        $this->giveAudience('event_id', (int) $event->id, ['user' => [(int) $bothMember->id]]);

        $ids = $this->eligibleIds($event);

        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertSame($this->sortedIds([$roleMember, $bothMember]), $ids);
    }

    /**
     * Früher galt ein Termin ohne Zielgruppe für alle. Seit den Zielgruppen-
     * Filtern ist "alle" eine eigene Zeile; ein Termin ganz ohne Zeile trifft
     * niemanden, statt sich stillschweigend für alle zu öffnen.
     */
    public function testAnEventWithoutAnyRowDoesNotOpenForEveryone(): void
    {
        $this->createUser();
        $event = $this->createEvent();

        $this->assertSame([], $this->eligibleIds($event));
    }

    /**
     * Die Aufrufer reichen die Mitglieder unverändert an Templates und
     * Mail-Erzeugung weiter. Geladen werden deshalb nur die Listenspalten - der
     * Passwort-Hash bleibt draußen.
     */
    public function testOnlyTheListColumnsAreLoaded(): void
    {
        $this->createUser();
        $event = $this->openToEveryone($this->createEvent());

        $user = $event->eligibleUsersQuery()->first();

        $this->assertNotNull($user);
        $this->assertSame(User::LIST_COLUMNS, array_keys($user->getAttributes()));
        $this->assertArrayNotHasKey('password', $user->getAttributes());
    }

    /**
     * @return array<int, int>
     */
    private function eligibleIds(Event $event): array
    {
        return $event->fresh()->eligibleUsersQuery()
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /**
     * @param array<int, User> $users
     * @return array<int, int>
     */
    private function sortedIds(array $users): array
    {
        $ids = array_map(static fn (User $user): int => (int) $user->id, $users);
        sort($ids);

        return $ids;
    }

    private function createEvent(): Event
    {
        return Event::create([
            'title' => 'Scope-Termin ' . bin2hex(random_bytes(4)),
            'starts_at' => Carbon::now()->addDays(4)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(4)->setTime(21, 0),
            'type' => 'Probe',
        ]);
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Scope',
            'last_name' => 'Testperson',
            'email' => 'scope-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }
}
