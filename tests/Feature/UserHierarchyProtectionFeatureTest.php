<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\Role;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Queries\ProjectQuery;
use App\Queries\UserQuery;
use App\Services\MailQueueService;
use App\Services\PasswordPolicyService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

class UserHierarchyProtectionFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('password')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->boolean('is_active')->default(true);
        });
        $schema->create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('hierarchy_level')->default(0);
        });

        Capsule::table('users')->insert([
            ['id' => 5, 'email' => 'target@example.test'],
        ]);
        Capsule::table('roles')->insert([
            ['id' => 1, 'hierarchy_level' => 10],
            ['id' => 2, 'hierarchy_level' => 50],
            ['id' => 3, 'hierarchy_level' => 80],
            ['id' => 4, 'hierarchy_level' => 100],
        ]);
    }

    /**
     * @param array<int> $roleLevels
     */
    private function makeTarget(array $roleLevels): User
    {
        $target = new User();
        $target->id = 5;
        $target->first_name = 'Target';
        $target->last_name = 'User';
        $target->email = 'target@example.test';
        $target->is_active = 1;

        $roles = array_map(static function (int $level): Role {
            $role = new Role();
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
            $this->createMock(Twig::class),
            $userQuery,
            $this->createMock(ProjectQuery::class),
            $userPersistence,
            $projectPersistence,
            $this->createMock(PasswordPolicyService::class),
            $this->createMock(MailQueueService::class),
            $this->createMock(LoggerInterface::class)
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

        $userQuery = $this->createMock(UserQuery::class);
        $userQuery->method('findById')->with(5)->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->never())->method('save');
        $userPersistence->expects($this->never())->method('syncRoles');

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->never())->method('setUserProjects');

        $controller = $this->makeController($userQuery, $userPersistence, $projectPersistence);

        $request = $this->makeRequest('POST', '/users/5', [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => 'target@example.test',
            'password' => 'irrelevant-but-present',
            'roles' => [1],
            'voice_groups' => [],
            'sub_voices' => [],
        ]);

        $result = $controller->update($request, $this->makeResponse(), ['id' => '5']);

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

        $userQuery = $this->createMock(UserQuery::class);
        $userQuery->method('findById')->with(5)->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->once())->method('save')->with($target);
        // role id 3 (level 80) and 4 (level 100) outrank the actor (level 50) and must be dropped.
        $userPersistence->expects($this->once())->method('syncRoles')->with($target, [1, 2]);
        $userPersistence->expects($this->once())->method('syncVoiceGroups')->with($target, []);

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->once())->method('setUserProjects')->with(5, []);

        $controller = $this->makeController($userQuery, $userPersistence, $projectPersistence);

        $request = $this->makeRequest('POST', '/users/5', [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => 'target@example.test',
            'password' => '',
            'roles' => [1, 2, 3, 4],
            'voice_groups' => [],
            'sub_voices' => [],
            'projects' => [],
        ]);

        $result = $controller->update($request, $this->makeResponse(), ['id' => '5']);

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

        $userQuery = $this->createMock(UserQuery::class);
        $userQuery->method('findById')->with(5)->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->once())->method('save')->with($target);
        $userPersistence->expects($this->once())->method('syncRoles')->with($target, [3]);
        $userPersistence->expects($this->once())->method('syncVoiceGroups')->with($target, []);

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->once())->method('setUserProjects')->with(5, []);

        $controller = $this->makeController($userQuery, $userPersistence, $projectPersistence);

        $request = $this->makeRequest('POST', '/users/5', [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => 'target@example.test',
            'password' => '',
            'roles' => [3],
            'voice_groups' => [],
            'sub_voices' => [],
            'projects' => [],
        ]);

        $result = $controller->update($request, $this->makeResponse(), ['id' => '5']);

        $this->assertRedirect($result, '/users');
        $this->assertSame('Mitglied erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
    }

    public function testDeactivateDeniesMemberThatOutranksActor(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_edit_users'] = true;
        $_SESSION['role_level'] = 80;
        $_SESSION['voice_group_ids'] = [];

        $target = $this->makeTarget([100]);

        $userQuery = $this->createMock(UserQuery::class);
        $userQuery->method('findById')->with(5)->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->never())->method('save');

        $controller = $this->makeController(
            $userQuery,
            $userPersistence,
            $this->createMock(ProjectPersistence::class)
        );

        $result = $controller->deactivate($this->makeRequest('POST', '/users/deactivate/5'), $this->makeResponse(), ['id' => '5']);

        $this->assertRedirect($result, '/users');
        $this->assertSame(
            'Du hast keine Berechtigung, dieses Mitglied zu deaktivieren.',
            $_SESSION['error'] ?? null
        );
    }
}
