<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
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

class UserProjectScopeFeatureTest extends TestCase
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
        $schema->create('projects', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('project_users', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('project_id');
        });

        Capsule::table('users')->insert([
            ['id' => 5, 'email' => 'target@example.test'],
            ['id' => 100, 'email' => 'manager@example.test'],
        ]);
        Capsule::table('roles')->insert([
            ['id' => 1, 'hierarchy_level' => 10],
        ]);
        Capsule::table('projects')->insert([
            ['id' => 2, 'name' => 'Alpha'],
            ['id' => 3, 'name' => 'Beta'],
            ['id' => 4, 'name' => 'Gamma'],
        ]);
        Capsule::table('project_users')->insert([
            ['user_id' => 100, 'project_id' => 2],
            ['user_id' => 100, 'project_id' => 4],
        ]);
    }

    public function testUpdateFiltersProjectsToAccessibleScopeForProjectMemberManagers(): void
    {
        $_SESSION['user_id'] = 100;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_manage_project_members'] = true;
        $_SESSION['role_level'] = 50;
        $_SESSION['voice_group_ids'] = [];

        $targetUser = new User();
        $targetUser->id = 5;
        $targetUser->first_name = 'Target';
        $targetUser->last_name = 'User';
        $targetUser->email = 'target@example.test';
        $targetUser->setRelation('roles', new Collection([]));
        $targetUser->setRelation('voiceGroups', new Collection([]));

        $twig = $this->createMock(Twig::class);

        $userQuery = $this->createMock(UserQuery::class);
        $userQuery->expects($this->once())
            ->method('findById')
            ->with(5)
            ->willReturn($targetUser);

        $projectQuery = $this->createMock(ProjectQuery::class);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->once())
            ->method('save')
            ->with($targetUser);
        $userPersistence->expects($this->once())
            ->method('syncRoles')
            ->with($targetUser, [1]);
        $userPersistence->expects($this->once())
            ->method('syncVoiceGroups')
            ->with($targetUser, []);

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->once())
            ->method('setUserProjects')
            ->with(5, [2, 4]);

        $passwordPolicyService = $this->createMock(PasswordPolicyService::class);
        $mailQueueService = $this->createMock(MailQueueService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $controller = new UserController(
            $twig,
            $userQuery,
            $projectQuery,
            $userPersistence,
            $projectPersistence,
            $passwordPolicyService,
            $mailQueueService,
            $logger
        );

        $request = $this->makeRequest('POST', '/users/5', [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => 'target@example.test',
            'password' => '',
            'roles' => [1],
            'voice_groups' => [],
            'sub_voices' => [],
            'projects' => [2, 3, 4],
        ]);
        $response = $this->makeResponse();

        $result = $controller->update($request, $response, ['id' => '5']);

        $this->assertRedirect($result, '/users');
        $this->assertSame('Mitglied erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
    }

    public function testUpdateDoesNotFilterProjectsForGlobalEditors(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_edit_users'] = true;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_manage_project_members'] = true;
        $_SESSION['role_level'] = 100;
        $_SESSION['voice_group_ids'] = [];

        $targetUser = new User();
        $targetUser->id = 5;
        $targetUser->first_name = 'Target';
        $targetUser->last_name = 'User';
        $targetUser->email = 'target@example.test';
        $targetUser->setRelation('roles', new Collection([]));
        $targetUser->setRelation('voiceGroups', new Collection([]));

        $twig = $this->createMock(Twig::class);

        $userQuery = $this->createMock(UserQuery::class);
        $userQuery->expects($this->once())
            ->method('findById')
            ->with(5)
            ->willReturn($targetUser);

        $projectQuery = $this->createMock(ProjectQuery::class);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->once())
            ->method('save')
            ->with($targetUser);
        $userPersistence->expects($this->once())
            ->method('syncRoles')
            ->with($targetUser, [1]);
        $userPersistence->expects($this->once())
            ->method('syncVoiceGroups')
            ->with($targetUser, []);

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->once())
            ->method('setUserProjects')
            ->with(5, [2, 3, 4]);

        $passwordPolicyService = $this->createMock(PasswordPolicyService::class);
        $mailQueueService = $this->createMock(MailQueueService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $controller = new UserController(
            $twig,
            $userQuery,
            $projectQuery,
            $userPersistence,
            $projectPersistence,
            $passwordPolicyService,
            $mailQueueService,
            $logger
        );

        $request = $this->makeRequest('POST', '/users/5', [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => 'target@example.test',
            'password' => '',
            'roles' => [1],
            'voice_groups' => [],
            'sub_voices' => [],
            'projects' => [2, 3, 4],
        ]);
        $response = $this->makeResponse();

        $result = $controller->update($request, $response, ['id' => '5']);

        $this->assertRedirect($result, '/users');
        $this->assertSame('Mitglied erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
    }
}
