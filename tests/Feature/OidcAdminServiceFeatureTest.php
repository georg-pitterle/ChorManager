<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Oidc\OidcAdminService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Bootstrap;

/**
 * Die Einrichtungsschritte hinter `bin/oidc_admin.php`.
 *
 * Geprüft wird auf Dienst-Ebene, nicht am Skript: Das Skript ist nur Ein- und
 * Ausgabe.
 */
final class OidcAdminServiceFeatureTest extends TestCase
{
    private OidcAdminService $service;
    private string $roleName;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->service = new OidcAdminService();
        $this->roleName = 'Vorstand ' . bin2hex(random_bytes(4));
        Role::create(['name' => $this->roleName, 'hierarchy_level' => 50]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testRoleGroupCanBeSetAndRemovedAgain(): void
    {
        $this->service->setRoleGroup($this->roleName, 'vorstand');
        $this->assertSame('vorstand', $this->groupOfRole());

        $this->service->setRoleGroup($this->roleName, null);
        $this->assertNull($this->groupOfRole());
    }

    public function testUnmappedRolesStayVisibleInTheListing(): void
    {
        $rows = $this->service->listRoleGroups();
        $mine = array_values(array_filter($rows, fn(array $row): bool => $row['name'] === $this->roleName));

        $this->assertCount(1, $mine, 'Eine Rolle ohne Zuordnung muss sichtbar bleiben.');
        $this->assertNull($mine[0]['group']);
    }

    public function testUnknownRoleIsRefusedWithAReadableMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gibt es nicht');

        $this->service->setRoleGroup('Gibt-Es-Nicht', 'irgendwas');
    }

    public function testUnusableGroupNamesAreRefused(): void
    {
        foreach (['mit leerzeichen', 'schrägstrich/weg', str_repeat('x', 65)] as $group) {
            try {
                $this->service->setRoleGroup($this->roleName, $group);
                $this->fail('Der Gruppenname "' . $group . '" hätte abgewiesen werden müssen.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('unbrauchbar', $exception->getMessage());
            }
        }
    }

    public function testExternalUidIsStoredAndListedAndCanBeRemoved(): void
    {
        $user = $this->makeUser();

        $this->service->setExternalUid((string) $user->email, 'mmusterfrau');
        $this->assertSame('mmusterfrau', (string) $user->fresh()->external_uid);

        $listed = array_column($this->service->listUsersWithExternalUid(), 'uid');
        $this->assertContains('mmusterfrau', $listed);

        $this->service->unsetExternalUid((string) $user->email);
        $this->assertNull($user->fresh()->external_uid);
    }

    public function testAnAlreadyTakenUidIsRefused(): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();

        $this->service->setExternalUid((string) $first->email, 'mmusterfrau');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gehört bereits');

        $this->service->setExternalUid((string) $second->email, 'mmusterfrau');
    }

    public function testSettingTheSameUidOnTheSameMemberAgainIsFine(): void
    {
        $user = $this->makeUser();

        $this->service->setExternalUid((string) $user->email, 'mmusterfrau');
        $this->service->setExternalUid((string) $user->email, 'mmusterfrau');

        $this->assertSame('mmusterfrau', (string) $user->fresh()->external_uid);
    }

    public function testUnknownMemberIsRefusedWithAReadableMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kein Mitglied');

        $this->service->setExternalUid('niemand@example.test', 'niemand');
    }

    private function makeUser(): User
    {
        return User::create([
            'email' => 'admin-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Test',
            'last_name' => 'Mitglied',
            'is_active' => 1,
        ]);
    }

    private function groupOfRole(): ?string
    {
        $value = Role::query()->where('name', $this->roleName)->value('external_group');

        return $value === null ? null : (string) $value;
    }
}
