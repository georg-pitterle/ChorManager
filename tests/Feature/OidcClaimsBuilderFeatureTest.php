<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\Oidc\OidcClaimsBuilder;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Was ein Mitglied in der angeschlossenen Anwendung über sich preisgibt.
 *
 * Der `groups`-Anspruch ist die heikle Stelle: Er entscheidet dort über
 * Freigaben. Er speist sich ausschließlich aus `roles.external_group` - eine
 * Rolle ohne Zuordnung geht gar nicht hinaus, und ein Rollenname wird nie
 * ersatzweise verwendet.
 */
final class OidcClaimsBuilderFeatureTest extends TestCase
{
    private const FULL_SCOPE = 'openid profile email groups';

    private OidcClaimsBuilder $builder;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->builder = new OidcClaimsBuilder();
        $this->user = User::create([
            'email' => 'claims-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Jonas',
            'last_name' => 'Öllinger',
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testSubjectFallsBackToTheDerivedFormWithoutAnExternalUid(): void
    {
        $claims = $this->builder->build($this->user->fresh(), self::FULL_SCOPE);

        $this->assertSame('cm-' . (int) $this->user->id, $claims['sub']);
        $this->assertSame($claims['sub'], $claims['preferred_username']);
    }

    public function testOnlyRolesWithAMappedGroupAppearAndEachOnlyOnce(): void
    {
        $this->attachRole('Vorstand', 'vorstand');
        $this->attachRole('Kassier', 'vorstand');
        $this->attachRole('Mitglied', null);

        $claims = $this->builder->build($this->user->fresh(), self::FULL_SCOPE);

        $this->assertSame(['vorstand'], $claims['groups']);
    }

    public function testAnEmptyMappingCountsAsNoMapping(): void
    {
        $this->attachRole('Mitglied', '   ');

        $this->assertSame([], $this->builder->build($this->user->fresh(), self::FULL_SCOPE)['groups']);
    }

    public function testVoiceGroupsAndPermissionFlagsNeverLeaveTheApplication(): void
    {
        $voiceGroup = VoiceGroup::create(['name' => 'Sopran ' . bin2hex(random_bytes(4))]);
        $this->user->voiceGroups()->attach($voiceGroup->id);
        $this->attachRole('Vorstand', 'vorstand', ['can_manage_users' => true]);

        $claims = $this->builder->build($this->user->fresh(), self::FULL_SCOPE);
        $serialized = json_encode($claims, JSON_UNESCAPED_UNICODE);

        $this->assertSame(['vorstand'], $claims['groups']);
        $this->assertStringNotContainsString('Sopran', (string) $serialized);
        $this->assertStringNotContainsString('can_manage_users', (string) $serialized);
        $this->assertArrayNotHasKey('voice_groups', $claims);
    }

    public function testNarrowerScopesLeaveTheCorrespondingClaimsOut(): void
    {
        $this->attachRole('Vorstand', 'vorstand');

        $claims = $this->builder->build($this->user->fresh(), 'openid');

        $this->assertSame(['sub'], array_keys($claims));
    }

    public function testNameAndMailComeThroughWithTheirUmlauts(): void
    {
        $claims = $this->builder->build($this->user->fresh(), self::FULL_SCOPE);

        $this->assertSame('Jonas Öllinger', $claims['name']);
        $this->assertSame((string) $this->user->email, $claims['email']);
        $this->assertTrue($claims['email_verified']);
    }

    /**
     * @param array<string, bool> $permissions
     */
    private function attachRole(string $name, ?string $externalGroup, array $permissions = []): void
    {
        $role = Role::create(array_merge([
            'name' => $name . ' ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'external_group' => $externalGroup,
        ], $permissions));

        $this->user->roles()->attach($role->id);
    }
}
