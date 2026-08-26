<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\Role;
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
 * Grenzen des Rechts "eigene Stimmgruppe verwalten".
 *
 * Wer nur die eigene Stimmgruppe pflegt, darf Namen und Zuordnungen ändern,
 * aber weder die E-Mail-Adresse eines fremden Kontos umbiegen noch eine
 * Einladung darauf auslösen - beides zusammen wäre ein vollständiger
 * Übernahmepfad. Umgekehrt gehört das Wiederherstellen zu demselben Recht wie
 * das Archivieren: wer archivieren darf, muss es auch zurücknehmen können.
 */
final class UserVoiceGroupManagerBoundaryFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private VoiceGroup $ownGroup;
    private VoiceGroup $foreignGroup;
    private Role $memberRole;
    private User $target;
    private string $originalEmail;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $suffix = bin2hex(random_bytes(4));
        $this->ownGroup = VoiceGroup::create(['name' => 'Eigene Gruppe ' . $suffix]);
        $this->foreignGroup = VoiceGroup::create(['name' => 'Fremde Gruppe ' . $suffix]);
        $this->memberRole = Role::create(['name' => 'Mitglied ' . $suffix, 'hierarchy_level' => 10]);

        $this->originalEmail = 'vg.boundary.' . $suffix . '@example.test';
        $this->target = User::create([
            'first_name' => 'Ziel',
            'last_name' => 'Person',
            'email' => $this->originalEmail,
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $this->target->voiceGroups()->attach($this->ownGroup->id);
        $this->target->roles()->attach($this->memberRole->id);

        $this->loginAsVoiceGroupManager();
    }

    protected function tearDown(): void
    {
        $this->target->voiceGroups()->detach();
        $this->target->roles()->detach();
        $this->target->delete();
        $this->memberRole->delete();
        $this->foreignGroup->delete();
        $this->ownGroup->delete();
        $_SESSION = [];

        parent::tearDown();
    }

    private function loginAsVoiceGroupManager(): void
    {
        $_SESSION = [
            'user_id' => 999001,
            'can_manage_users' => false,
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'role_level' => 50,
            'voice_group_ids' => [(int) $this->ownGroup->id],
        ];
    }

    private function controller(): UserController
    {
        $logger = new Logger('test');

        return new UserController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new UserPersistence($logger),
            new ProjectPersistence(),
            $this->createStub(MailQueueService::class),
            $logger,
            new UserEditPolicy()
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function update(array $payload): void
    {
        $this->controller()->update(
            $this->makeRequest('POST', '/users/' . $this->target->id, array_merge([
                'first_name' => 'Ziel',
                'last_name' => 'Person',
                'email' => $this->originalEmail,
                'roles' => [(string) $this->memberRole->id],
                'voice_groups' => [(string) $this->ownGroup->id],
            ], $payload)),
            $this->makeResponse(),
            ['id' => (string) $this->target->id]
        );
    }

    public function testVoiceGroupManagerCannotChangeAForeignEmailAddress(): void
    {
        $this->update(['email' => 'uebernahme@example.test', 'first_name' => 'Geändert']);

        $fresh = $this->target->fresh();
        $this->assertSame($this->originalEmail, (string) $fresh->email, 'Die E-Mail-Adresse darf nicht umgebogen werden.');
        $this->assertSame('Geändert', (string) $fresh->first_name, 'Die übrigen Mitgliedsdaten bleiben pflegbar.');
    }

    public function testVoiceGroupManagerCannotSendAnInvitation(): void
    {
        $response = $this->controller()->invite(
            $this->makeRequest('POST', '/users/' . $this->target->id . '/invite'),
            $this->makeResponse(),
            ['id' => (string) $this->target->id]
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testVoiceGroupManagerMayRestoreAnArchivedMemberOfTheirOwnGroup(): void
    {
        $this->target->is_active = 0;
        $this->target->save();

        $this->controller()->restore(
            $this->makeRequest('POST', '/users/' . $this->target->id . '/restore'),
            $this->makeResponse(),
            ['id' => (string) $this->target->id]
        );

        $this->assertSame(1, (int) $this->target->fresh()->is_active);
    }

    public function testVoiceGroupManagerMayNotRestoreAMemberOfAForeignGroup(): void
    {
        $this->target->voiceGroups()->sync([(int) $this->foreignGroup->id]);
        $this->target->is_active = 0;
        $this->target->save();

        $this->controller()->restore(
            $this->makeRequest('POST', '/users/' . $this->target->id . '/restore'),
            $this->makeResponse(),
            ['id' => (string) $this->target->id]
        );

        $this->assertSame(0, (int) $this->target->fresh()->is_active);
    }
}
