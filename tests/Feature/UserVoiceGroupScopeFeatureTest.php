<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\Role;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Policies\UserEditPolicy;
use App\Queries\UserQuery;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Ein Stimmgruppenvertreter darf nur seine eigenen Stimmgruppen umhängen und nur
 * Rollen unterhalb seines Levels vergeben. Alles andere am Zielkonto muss den
 * Vorgang unverändert überstehen - sonst entzieht das Speichern einer
 * Kleinigkeit stillschweigend eine fremde Zuordnung.
 *
 * Hält die Zusammenlegung der doppelten Stimmgruppen-Berechnung in update() fest.
 */
class UserVoiceGroupScopeFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private VoiceGroup $ownGroup;
    private VoiceGroup $foreignGroup;
    private SubVoice $foreignSubVoice;
    private Role $actorLevelRole;
    private Role $lowerRole;
    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        Bootstrap::setupTestDatabase();

        $suffix = bin2hex(random_bytes(4));
        $this->ownGroup = VoiceGroup::create(['name' => 'Eigene Gruppe ' . $suffix]);
        $this->foreignGroup = VoiceGroup::create(['name' => 'Fremde Gruppe ' . $suffix]);
        $this->foreignSubVoice = SubVoice::create([
            'name' => 'Fremde Untergruppe ' . $suffix,
            'voice_group_id' => $this->foreignGroup->id,
        ]);

        $this->actorLevelRole = Role::create(['name' => 'Gleiches Level ' . $suffix, 'hierarchy_level' => 50]);
        $this->lowerRole = Role::create(['name' => 'Tieferes Level ' . $suffix, 'hierarchy_level' => 10]);

        $this->target = User::create([
            'first_name' => 'Ziel',
            'last_name' => 'Person',
            'email' => 'vg.target.' . $suffix . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        // Ausgangslage: eine fremde Stimmgruppe mit Untergruppe und eine Rolle auf
        // dem Level des Bearbeiters - beides kann der Vertreter nicht auswählen.
        $this->target->voiceGroups()->attach($this->foreignGroup->id, [
            'sub_voice_id' => $this->foreignSubVoice->id,
        ]);
        $this->target->roles()->attach($this->actorLevelRole->id);

        $_SESSION = [
            'user_id' => 999000,
            'can_manage_users' => false,
            'can_edit_users' => true,
            'can_manage_own_voice_group' => true,
            'role_level' => 50,
            'voice_group_ids' => [(int) $this->ownGroup->id],
        ];
    }

    protected function tearDown(): void
    {
        $this->target->voiceGroups()->detach();
        $this->target->roles()->detach();
        $this->target->delete();
        $this->actorLevelRole->delete();
        $this->lowerRole->delete();
        $this->foreignSubVoice->delete();
        $this->foreignGroup->delete();
        $this->ownGroup->delete();
        $_SESSION = [];

        parent::tearDown();
    }

    public function testRepresentativeKeepsForeignVoiceGroupIncludingItsSubVoice(): void
    {
        $this->update([
            'voice_groups' => [(string) $this->ownGroup->id],
            'roles' => [(string) $this->lowerRole->id],
        ]);

        $groups = $this->target->fresh()->voiceGroups;
        $groupIds = $groups->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        sort($groupIds);
        $expected = [(int) $this->ownGroup->id, (int) $this->foreignGroup->id];
        sort($expected);

        $this->assertSame($expected, $groupIds, 'Die fremde Stimmgruppe darf nicht verschwinden');
        $this->assertSame(
            (int) $this->foreignSubVoice->id,
            (int) $groups->firstWhere('id', $this->foreignGroup->id)->pivot->sub_voice_id,
            'Die Untergruppe der fremden Stimmgruppe muss erhalten bleiben'
        );
    }

    public function testRepresentativeCannotDropAForeignVoiceGroupByOmittingIt(): void
    {
        // Die fremde Gruppe wird gar nicht mitgeschickt - sie muss trotzdem bleiben.
        $this->update([
            'voice_groups' => [],
            'roles' => [(string) $this->lowerRole->id],
        ]);

        $groupIds = $this->target->fresh()->voiceGroups->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();

        $this->assertContains((int) $this->foreignGroup->id, $groupIds);
        $this->assertNotContains((int) $this->ownGroup->id, $groupIds);
    }

    public function testRepresentativeCannotSmuggleInAForeignVoiceGroup(): void
    {
        $stranger = VoiceGroup::create(['name' => 'Nicht zugeteilt ' . bin2hex(random_bytes(4))]);

        try {
            $this->update([
                'voice_groups' => [(string) $stranger->id],
                'roles' => [(string) $this->lowerRole->id],
            ]);

            $groupIds = $this->target->fresh()->voiceGroups->pluck('id')
                ->map(static fn ($id): int => (int) $id)->all();

            $this->assertNotContains((int) $stranger->id, $groupIds);
        } finally {
            $stranger->delete();
        }
    }

    /**
     * Begründet, warum der Rollen-Block in update() bleiben muss: Rollen auf dem
     * eigenen Level kann der Vertreter nicht auswählen - würden sie nicht eigens
     * übernommen, entzöge jedes Speichern sie stillschweigend.
     */
    public function testRoleOnTheActorsOwnLevelSurvivesAnEdit(): void
    {
        $this->update([
            'voice_groups' => [(string) $this->ownGroup->id],
            'roles' => [(string) $this->lowerRole->id],
        ]);

        $roleIds = $this->target->fresh()->roles->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();

        $this->assertContains((int) $this->actorLevelRole->id, $roleIds);
        $this->assertContains((int) $this->lowerRole->id, $roleIds);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function update(array $payload): void
    {
        $logger = new Logger('test');

        $controller = new UserController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new UserPersistence($logger),
            new ProjectPersistence(),
            $this->createStub(MailQueueService::class),
            $logger,
            new UserEditPolicy()
        );

        $controller->update(
            $this->makeRequest('POST', '/users/' . $this->target->id, array_merge([
                'first_name' => 'Ziel',
                'last_name' => 'Person',
                'email' => (string) $this->target->email,
            ], $payload)),
            $this->makeResponse(),
            ['id' => (string) $this->target->id]
        );
    }
}
