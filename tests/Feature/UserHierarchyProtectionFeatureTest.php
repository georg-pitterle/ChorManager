<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\Role;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Policies\UserEditPolicy;
use App\Queries\UserQuery;
use App\Services\MailQueueService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class UserHierarchyProtectionFeatureTest extends TestCase
{
    use TestHttpHelpers;

    /** Haengt an jeden Rollennamen, damit er in der geteilten Datenbank eindeutig bleibt. */
    private string $suffix = '';

    /** Id des Ziel-Mitglieds als reines Stellvertreter-Objekt, nie geladen aus der DB. */
    private int $targetUserId = 0;
    private string $targetEmail = '';

    /** @var array<int,int> hierarchy_level => reale Rollen-Id */
    private array $roleIdByLevel = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
        $this->suffix = bin2hex(random_bytes(4));

        // Rein synthetischer Stellvertreter: UserQuery ist gestubbt und liefert
        // das in makeTarget() gebaute User-Objekt, ohne die reale users-Tabelle
        // anzufassen. Ein Bereich weit oberhalb realer Auto-Increment-Werte
        // schliesst jede Kollision mit Bestandsdaten aus.
        $this->targetUserId = random_int(500000, 999999);
        $this->targetEmail = 'target' . $this->suffix . '@example.test';

        foreach ([10, 50, 80, 100] as $level) {
            $this->roleIdByLevel[$level] = (int) Role::create([
                'name' => 'Rolle Level ' . $level . ' ' . $this->suffix,
                'hierarchy_level' => $level,
            ])->id;
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

    /**
     * @param array<int> $roleLevels
     */
    private function makeTarget(array $roleLevels): User
    {
        $target = new User();
        $target->id = $this->targetUserId;
        $target->first_name = 'Target';
        $target->last_name = 'User';
        $target->email = $this->targetEmail;
        $target->is_active = 1;

        $roles = array_map(function (int $level): Role {
            $role = new Role();
            $role->id = $this->roleIdByLevel[$level];
            $role->hierarchy_level = $level;
            return $role;
        }, $roleLevels);

        $target->setRelation('roles', new Collection($roles));
        $target->setRelation('voiceGroups', new Collection([]));

        return $target;
    }

    private function makeController(
        UserQuery $userQuery,
        UserPersistence $userPersistence,
        ProjectPersistence $projectPersistence
    ): UserController {
        return new UserController(
            $this->createStub(Twig::class),
            $userQuery,
            $userPersistence,
            $projectPersistence,
            $this->createStub(MailQueueService::class),
            $this->createStub(LoggerInterface::class),
            new UserEditPolicy()
        );
    }

    public function testUpdateDeniesEditingMemberThatOutranksActor(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_edit_users'] = true;
        $_SESSION['can_manage_project_members'] = true;
        $_SESSION['role_level'] = 80;
        $_SESSION['voice_group_ids'] = [];

        $target = $this->makeTarget([100]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->never())->method('save');
        $userPersistence->expects($this->never())->method('syncRoles');

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->never())->method('setUserProjects');

        $controller = $this->makeController($userQuery, $userPersistence, $projectPersistence);

        $request = $this->makeRequest('POST', '/users/' . $this->targetUserId, [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => $this->targetEmail,
            'password' => 'irrelevant-but-present',
            'roles' => [$this->roleIdByLevel[10]],
            'voice_groups' => [],
            'sub_voices' => [],
        ]);

        $result = $controller->update($request, $this->makeResponse(), ['id' => (string) $this->targetUserId]);

        $this->assertRedirect($result, '/users');
        $this->assertArrayNotHasKey('success', $_SESSION);
        $this->assertSame(
            'Du hast keine Berechtigung, dieses Mitglied zu bearbeiten.',
            $_SESSION['error'] ?? null
        );
    }

    public function testUpdateCapsAssignedRolesToActorLevel(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_edit_users'] = true;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['role_level'] = 50;
        $_SESSION['voice_group_ids'] = [];

        $target = $this->makeTarget([]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->once())->method('save')->with($target);
        // Rolle bei Level 80 und Level 100 liegen oberhalb des Akteurs (Level 50)
        // und muessen deshalb aus der Zuweisung fallen.
        $userPersistence->expects($this->once())->method('syncRoles')->with(
            $target,
            [$this->roleIdByLevel[10], $this->roleIdByLevel[50]]
        );
        $userPersistence->expects($this->once())->method('syncVoiceGroups')->with($target, []);

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->once())->method('setUserProjects')->with($this->targetUserId, []);

        $controller = $this->makeController($userQuery, $userPersistence, $projectPersistence);

        $request = $this->makeRequest('POST', '/users/' . $this->targetUserId, [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => $this->targetEmail,
            'password' => '',
            'roles' => [
                $this->roleIdByLevel[10],
                $this->roleIdByLevel[50],
                $this->roleIdByLevel[80],
                $this->roleIdByLevel[100],
            ],
            'voice_groups' => [],
            'sub_voices' => [],
            'projects' => [],
        ]);

        $result = $controller->update($request, $this->makeResponse(), ['id' => (string) $this->targetUserId]);

        $this->assertRedirect($result, '/users');
        $this->assertSame('Mitglied erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
    }

    public function testUpdateAllowsEditingSameRankedMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_edit_users'] = true;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['role_level'] = 80;
        $_SESSION['voice_group_ids'] = [];

        $target = $this->makeTarget([80]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->once())->method('save')->with($target);
        $userPersistence->expects($this->once())->method('syncRoles')->with($target, [$this->roleIdByLevel[80]]);
        $userPersistence->expects($this->once())->method('syncVoiceGroups')->with($target, []);

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->once())->method('setUserProjects')->with($this->targetUserId, []);

        $controller = $this->makeController($userQuery, $userPersistence, $projectPersistence);

        $request = $this->makeRequest('POST', '/users/' . $this->targetUserId, [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => $this->targetEmail,
            'password' => '',
            'roles' => [$this->roleIdByLevel[80]],
            'voice_groups' => [],
            'sub_voices' => [],
            'projects' => [],
        ]);

        $result = $controller->update($request, $this->makeResponse(), ['id' => (string) $this->targetUserId]);

        $this->assertRedirect($result, '/users');
        $this->assertSame('Mitglied erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
    }

    public function testUpdateIgnoresSubmittedPassword(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_edit_users'] = true;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['role_level'] = 80;
        $_SESSION['voice_group_ids'] = [];

        $target = $this->makeTarget([80]);
        $existingHash = password_hash('original-secret', PASSWORD_DEFAULT);
        $target->password = $existingHash;

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->once())->method('save')->with($target);

        $controller = $this->makeController(
            $userQuery,
            $userPersistence,
            $this->createStub(ProjectPersistence::class)
        );

        // Passwords are only ever set by the member via the invitation or reset link.
        $request = $this->makeRequest('POST', '/users/' . $this->targetUserId, [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => $this->targetEmail,
            'password' => 'Injected-Secret-123!',
            'roles' => [$this->roleIdByLevel[80]],
            'voice_groups' => [],
            'sub_voices' => [],
            'projects' => [],
        ]);

        $result = $controller->update($request, $this->makeResponse(), ['id' => (string) $this->targetUserId]);

        $this->assertRedirect($result, '/users');
        $this->assertSame('Mitglied erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
        $this->assertSame($existingHash, $target->password);
    }

    public function testDeactivateDeniesMemberThatOutranksActor(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_edit_users'] = true;
        $_SESSION['role_level'] = 80;
        $_SESSION['voice_group_ids'] = [];

        $target = $this->makeTarget([100]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->never())->method('save');

        $controller = $this->makeController(
            $userQuery,
            $userPersistence,
            $this->createStub(ProjectPersistence::class)
        );

        $result = $controller->deactivate(
            $this->makeRequest('POST', '/users/deactivate/' . $this->targetUserId),
            $this->makeResponse(),
            ['id' => (string) $this->targetUserId]
        );

        $this->assertRedirect($result, '/users');
        $this->assertSame(
            'Du hast keine Berechtigung, dieses Mitglied zu deaktivieren.',
            $_SESSION['error'] ?? null
        );
    }
}
