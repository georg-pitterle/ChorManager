<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Middleware\RoleMiddleware;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Queries\ProjectQuery;
use App\Queries\UserQuery;
use App\Services\AttendanceScopeService;
use App\Services\MailQueueService;
use App\Services\PasswordPolicyService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

class OwnVoiceGroupCallSitesFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
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
        $schema->create('invitation_tokens', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('selector');
            $table->string('token_hash');
            $table->dateTime('expires_at');
            $table->dateTime('created_at');
        });

        // Single role at hierarchy_level 0, used by the update()-flow tests below:
        // an actor with role_level 0 can only keep roles the target already holds
        // at/below that level, so the target must already carry this role for the
        // "allowed" scenario to reach the persistence layer instead of bailing out
        // earlier on "no roles selected".
        Capsule::table('roles')->insert(['id' => 1, 'hierarchy_level' => 0]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testCanManageOthersUsesFlagNotLevel(): void
    {
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 0;
        $_SESSION['can_manage_own_voice_group'] = true;

        $this->assertTrue((new AttendanceScopeService())->canManageOthers());
    }

    public function testCanManageOthersFalseWithoutFlagOrAdmin(): void
    {
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 30;
        $_SESSION['can_manage_own_voice_group'] = false;

        $this->assertFalse((new AttendanceScopeService())->canManageOthers());
    }

    public function testAdminStillManagesOthers(): void
    {
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_manage_own_voice_group'] = false;

        $this->assertTrue((new AttendanceScopeService())->canManageOthers());
    }

    /**
     * Cheap regression guard in addition to the behavioral tests above/below:
     * the magic-number comparisons must be gone from the three migrated files.
     */
    public function testCallSitesReferenceFlagNotMagicNumber(): void
    {
        $scope = file_get_contents(dirname(__DIR__) . '/../src/Services/AttendanceScopeService.php');
        $middleware = file_get_contents(dirname(__DIR__) . '/../src/Middleware/RoleMiddleware.php');
        $userCtrl = file_get_contents(dirname(__DIR__) . '/../src/Controllers/UserController.php');

        $this->assertStringContainsString('can_manage_own_voice_group', $scope);
        $this->assertStringNotContainsString('>= 40', $scope);
        $this->assertStringContainsString('can_manage_own_voice_group', $middleware);
        $this->assertStringNotContainsString('< 40', $middleware);
        $this->assertStringContainsString('can_manage_own_voice_group', $userCtrl);
        $this->assertStringNotContainsString('$userLevel >= 40', $userCtrl);
        $this->assertStringNotContainsString('$userLevel < 40', $userCtrl);
    }

    // --- RoleMiddleware::allowVoiceGroupReps behavioral coverage ---

    public function testRoleMiddlewareAllowsVoiceGroupRepWithOnlyCapabilityFlag(): void
    {
        $_SESSION = ['user_id' => 7];
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 0;
        $_SESSION['can_manage_own_voice_group'] = true;

        $middleware = new RoleMiddleware(allowVoiceGroupReps: true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/attendance/manage'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRoleMiddlewareRejectsUserWithoutFlagOrManageUsers(): void
    {
        $_SESSION = ['user_id' => 7];
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 0;
        $_SESSION['can_manage_own_voice_group'] = false;

        $middleware = new RoleMiddleware(allowVoiceGroupReps: true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/attendance/manage'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- UserController::canDeactivateTargetUser() behavioral coverage,
    //     exercised through bulkDeactivate(), the public route method that
    //     actually invokes the private canDeactivateTargetUser(). ---

    private function makeVoiceGroupRepController(
        UserQuery $userQuery,
        UserPersistence $userPersistence
    ): UserController {
        return new UserController(
            $this->createStub(Twig::class),
            $userQuery,
            $this->createStub(ProjectQuery::class),
            $userPersistence,
            $this->createStub(ProjectPersistence::class),
            $this->createStub(PasswordPolicyService::class),
            $this->createStub(MailQueueService::class),
            $this->createStub(LoggerInterface::class)
        );
    }

    private function makeUserWithVoiceGroups(int $id, array $voiceGroupIds): User
    {
        $user = new User();
        $user->id = $id;
        $user->first_name = 'Target';
        $user->last_name = 'User';
        $user->email = sprintf('target-%d@example.test', $id);
        $user->is_active = 1;

        $user->setRelation('roles', new Collection([]));
        $user->setRelation('voiceGroups', new Collection(array_map(
            static function (int $vgId): VoiceGroup {
                $vg = new VoiceGroup();
                $vg->id = $vgId;
                return $vg;
            },
            $voiceGroupIds
        )));

        return $user;
    }

    public function testVoiceGroupRepWithOnlyFlagCanBulkDeactivateOwnGroupMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [1];

        $target = $this->makeUserWithVoiceGroups(5, [1]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->once())->method('save')->with($target);

        $controller = $this->makeVoiceGroupRepController($userQuery, $userPersistence);

        $result = $controller->bulkDeactivate(
            $this->makeRequest('POST', '/users/bulk-deactivate', ['user_ids' => [5]]),
            $this->makeResponse()
        );

        $this->assertRedirect($result, '/users');
        $this->assertSame(
            'Bulk-Aktion abgeschlossen: 1 deaktiviert, 0 fehlgeschlagen.',
            $_SESSION['success'] ?? null
        );
    }

    public function testVoiceGroupRepWithOnlyFlagCannotBulkDeactivateOutsideGroupMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [1];

        $target = $this->makeUserWithVoiceGroups(6, [2]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->never())->method('save');

        $controller = $this->makeVoiceGroupRepController($userQuery, $userPersistence);

        $result = $controller->bulkDeactivate(
            $this->makeRequest('POST', '/users/bulk-deactivate', ['user_ids' => [6]]),
            $this->makeResponse()
        );

        $this->assertRedirect($result, '/users');
        $this->assertSame(
            'Bulk-Aktion abgeschlossen: 0 deaktiviert, 1 fehlgeschlagen.',
            $_SESSION['success'] ?? null
        );
    }

    // --- UserController::update() behavioral coverage (line ~282: capability
    //     check gating the non-admin, non-can_edit_users edit path). ---

    private function makeTargetForUpdate(int $id, array $voiceGroupIds, array $roleIds): User
    {
        $user = new User();
        $user->id = $id;
        $user->first_name = 'Target';
        $user->last_name = 'User';
        $user->email = sprintf('target-%d@example.test', $id);
        $user->is_active = 1;

        $user->setRelation('roles', new Collection(array_map(
            static function (int $roleId): Role {
                $role = new Role();
                $role->id = $roleId;
                $role->hierarchy_level = 0;
                return $role;
            },
            $roleIds
        )));
        $user->setRelation('voiceGroups', new Collection(array_map(
            static function (int $vgId): VoiceGroup {
                $vg = new VoiceGroup();
                $vg->id = $vgId;
                return $vg;
            },
            $voiceGroupIds
        )));

        return $user;
    }

    private function makeUpdateController(
        UserQuery $userQuery,
        UserPersistence $userPersistence,
        ProjectPersistence $projectPersistence
    ): UserController {
        return new UserController(
            $this->createStub(Twig::class),
            $userQuery,
            $this->createStub(ProjectQuery::class),
            $userPersistence,
            $projectPersistence,
            $this->createStub(PasswordPolicyService::class),
            $this->createStub(MailQueueService::class),
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testUpdateAllowsVoiceGroupRepWithOnlyFlagForOwnGroupMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [1];

        // Target already holds role id 1 (hierarchy_level 0) so the actor's
        // own-level role restriction keeps it assigned and the request reaches
        // the persistence layer instead of bailing out on "no roles selected".
        $target = $this->makeTargetForUpdate(5, [1], [1]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->once())->method('save')->with($target);
        $userPersistence->expects($this->once())->method('syncRoles')->with($target, [1]);
        $userPersistence->expects($this->once())->method('syncVoiceGroups');

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->never())->method('setUserProjects');

        $controller = $this->makeUpdateController($userQuery, $userPersistence, $projectPersistence);

        $request = $this->makeRequest('POST', '/users/5', [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => 'target-5@example.test',
            'password' => '',
            'roles' => [1],
            'voice_groups' => [1],
            'sub_voices' => [],
        ]);

        $result = $controller->update($request, $this->makeResponse(), ['id' => '5']);

        $this->assertRedirect($result, '/users');
        $this->assertSame('Mitglied erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
    }

    public function testUpdateDeniesVoiceGroupRepWithOnlyFlagForOutsideGroupMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [1];

        $target = $this->makeTargetForUpdate(6, [2], [1]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->never())->method('save');

        $controller = $this->makeUpdateController(
            $userQuery,
            $userPersistence,
            $this->createStub(ProjectPersistence::class)
        );

        $request = $this->makeRequest('POST', '/users/6', [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => 'target-6@example.test',
            'password' => '',
            'roles' => [1],
            'voice_groups' => [2],
            'sub_voices' => [],
        ]);

        $result = $controller->update($request, $this->makeResponse(), ['id' => '6']);

        $this->assertRedirect($result, '/users');
        $this->assertSame(
            'Du hast keine Berechtigung, dieses Mitglied zu bearbeiten.',
            $_SESSION['error'] ?? null
        );
    }

    public function testUpdateDeniesWithoutEitherFlagEvenForOwnGroupMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_manage_own_voice_group'] = false;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [1];

        $target = $this->makeTargetForUpdate(7, [1], [1]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->never())->method('save');

        $controller = $this->makeUpdateController(
            $userQuery,
            $userPersistence,
            $this->createStub(ProjectPersistence::class)
        );

        $request = $this->makeRequest('POST', '/users/7', [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => 'target-7@example.test',
            'password' => '',
            'roles' => [1],
            'voice_groups' => [1],
            'sub_voices' => [],
        ]);

        $result = $controller->update($request, $this->makeResponse(), ['id' => '7']);

        $this->assertRedirect($result, '/users');
        $this->assertSame(
            'Du hast keine Berechtigung, dieses Mitglied zu bearbeiten.',
            $_SESSION['error'] ?? null
        );
    }

    // --- UserController::deactivate() single-user route behavioral coverage
    //     (line ~450: the inlined capability check duplicate). ---

    public function testDeactivateAllowsVoiceGroupRepWithOnlyFlagForOwnGroupMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [1];

        $target = $this->makeUserWithVoiceGroups(5, [1]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->once())->method('save')->with($target);

        $controller = $this->makeVoiceGroupRepController($userQuery, $userPersistence);

        $result = $controller->deactivate(
            $this->makeRequest('POST', '/users/deactivate/5'),
            $this->makeResponse(),
            ['id' => '5']
        );

        $this->assertRedirect($result, '/users');
        $this->assertSame('Mitglied wurde archiviert (deaktiviert).', $_SESSION['success'] ?? null);
    }

    public function testDeactivateDeniesVoiceGroupRepWithOnlyFlagForOutsideGroupMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [1];

        $target = $this->makeUserWithVoiceGroups(6, [2]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->never())->method('save');

        $controller = $this->makeVoiceGroupRepController($userQuery, $userPersistence);

        $result = $controller->deactivate(
            $this->makeRequest('POST', '/users/deactivate/6'),
            $this->makeResponse(),
            ['id' => '6']
        );

        $this->assertRedirect($result, '/users');
        $this->assertSame(
            'Du hast keine Berechtigung, dieses Mitglied zu deaktivieren.',
            $_SESSION['error'] ?? null
        );
    }

    public function testDeactivateDeniesWithoutEitherFlagEvenForOwnGroupMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_own_voice_group'] = false;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [1];

        $target = $this->makeUserWithVoiceGroups(7, [1]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->never())->method('save');

        $controller = $this->makeVoiceGroupRepController($userQuery, $userPersistence);

        $result = $controller->deactivate(
            $this->makeRequest('POST', '/users/deactivate/7'),
            $this->makeResponse(),
            ['id' => '7']
        );

        $this->assertRedirect($result, '/users');
        $this->assertSame(
            'Du hast keine Berechtigung, dieses Mitglied zu deaktivieren.',
            $_SESSION['error'] ?? null
        );
    }

    // --- UserController::invite() behavioral coverage (line ~643). ---

    private function makeInviteController(UserQuery $userQuery): UserController
    {
        $view = $this->createStub(Twig::class);
        $view->method('fetch')->willReturn('<html>invite</html>');

        return new UserController(
            $view,
            $userQuery,
            $this->createStub(ProjectQuery::class),
            $this->createStub(UserPersistence::class),
            $this->createStub(ProjectPersistence::class),
            $this->createStub(PasswordPolicyService::class),
            $this->createStub(MailQueueService::class),
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testInviteAllowsVoiceGroupRepWithOnlyFlagForOwnGroupMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [1];

        $target = $this->makeUserWithVoiceGroups(5, [1]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $controller = $this->makeInviteController($userQuery);

        $result = $controller->invite(
            $this->makeRequest('POST', '/users/5/invite'),
            $this->makeResponse(),
            ['id' => '5']
        );

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertTrue($body['success']);
    }

    public function testInviteDeniesVoiceGroupRepWithOnlyFlagForOutsideGroupMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [1];

        $target = $this->makeUserWithVoiceGroups(6, [2]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $controller = $this->makeInviteController($userQuery);

        $result = $controller->invite(
            $this->makeRequest('POST', '/users/6/invite'),
            $this->makeResponse(),
            ['id' => '6']
        );

        $this->assertSame(403, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Keine Berechtigung.', $body['message']);
    }

    public function testInviteDeniesWithoutEitherFlagEvenForOwnGroupMember(): void
    {
        $_SESSION['user_id'] = 10;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_manage_own_voice_group'] = false;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [1];

        $target = $this->makeUserWithVoiceGroups(7, [1]);

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('findById')->willReturn($target);

        $controller = $this->makeInviteController($userQuery);

        $result = $controller->invite(
            $this->makeRequest('POST', '/users/7/invite'),
            $this->makeResponse(),
            ['id' => '7']
        );

        $this->assertSame(403, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Keine Berechtigung.', $body['message']);
    }
}
