<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\Role;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Policies\UserEditPolicy;
use App\Queries\ProjectQuery;
use App\Queries\UserQuery;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use Illuminate\Database\QueryException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

final class UserChangeLoggingTest extends TestCase
{
    use TestHttpHelpers;

    private Role $role;
    private Role $secondRole;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $_SESSION = [
            'can_manage_users' => true,
            'can_edit_users' => true,
            'role_level' => 100,
            'voice_group_ids' => [],
            'can_manage_project_members' => false,
            'can_manage_own_voice_group' => false,
        ];

        $this->role = Role::create([
            'name' => 'User Logging Role A ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $this->secondRole = Role::create([
            'name' => 'User Logging Role B ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        User::where('email', 'like', 'user-logging-%@example.test')->delete();
        $this->role->delete();
        $this->secondRole->delete();
        $_SESSION = [];

        parent::tearDown();
    }

    public function testCreateUserLogsUserCreatedAndRoleAssigned(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $email = 'user-logging-create-' . bin2hex(random_bytes(4)) . '@example.test';

        $controller->create(
            $this->makeRequest('POST', '/users', [
                'first_name' => 'Log',
                'last_name' => 'Test',
                'email' => $email,
                'roles' => [(string) $this->role->id],
            ]),
            $this->makeResponse()
        );

        $created = User::where('email', $email)->first();
        $this->assertNotNull($created);

        $createdRecord = $this->recordFor($handler, 'user.created');
        $this->assertNotNull($createdRecord);
        $this->assertSame((int) $created->id, $createdRecord->context['user_id']);

        $assignedRecord = $this->recordFor($handler, 'user.role.assigned');
        $this->assertNotNull($assignedRecord);
        $this->assertSame((int) $created->id, $assignedRecord->context['user_id']);
        $this->assertSame((int) $this->role->id, $assignedRecord->context['role_id']);
    }

    public function testUpdateUserLogsEmailChanged(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $oldEmail = 'user-logging-old-' . bin2hex(random_bytes(4)) . '@example.test';
        $newEmail = 'user-logging-new-' . bin2hex(random_bytes(4)) . '@example.test';

        $user = $this->makeActiveUser($oldEmail);
        $user->roles()->attach($this->role->id);

        $controller->update(
            $this->makeRequest('POST', '/users/' . $user->id, [
                'first_name' => 'Log',
                'last_name' => 'Test',
                'email' => $newEmail,
                'roles' => [(string) $this->role->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $user->id]
        );

        $record = $this->recordFor($handler, 'user.email.changed');
        $this->assertNotNull($record);
        $this->assertSame((int) $user->id, $record->context['user_id']);
        $this->assertSame($oldEmail, $record->context['old_email']);
        $this->assertSame($newEmail, $record->context['new_email']);
    }

    public function testUpdateUserLogsRoleAssignedAndRevoked(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $email = 'user-logging-roles-' . bin2hex(random_bytes(4)) . '@example.test';
        $user = $this->makeActiveUser($email);
        $user->roles()->attach($this->role->id);

        $controller->update(
            $this->makeRequest('POST', '/users/' . $user->id, [
                'first_name' => 'Log',
                'last_name' => 'Test',
                'email' => $email,
                'roles' => [(string) $this->secondRole->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $user->id]
        );

        $assignedRecord = $this->recordFor($handler, 'user.role.assigned');
        $this->assertNotNull($assignedRecord);
        $this->assertSame((int) $user->id, $assignedRecord->context['user_id']);
        $this->assertSame((int) $this->secondRole->id, $assignedRecord->context['role_id']);

        $revokedRecord = $this->recordFor($handler, 'user.role.revoked');
        $this->assertNotNull($revokedRecord);
        $this->assertSame((int) $user->id, $revokedRecord->context['user_id']);
        $this->assertSame((int) $this->role->id, $revokedRecord->context['role_id']);
    }

    public function testDeactivateUserIsLogged(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $email = 'user-logging-deactivate-' . bin2hex(random_bytes(4)) . '@example.test';
        $user = $this->makeActiveUser($email);

        $controller->deactivate(
            $this->makeRequest('POST', '/users/deactivate/' . $user->id),
            $this->makeResponse(),
            ['id' => (string) $user->id]
        );

        $record = $this->recordFor($handler, 'user.deactivated');
        $this->assertNotNull($record);
        $this->assertSame((int) $user->id, $record->context['user_id']);
    }

    public function testBulkDeactivateLogsEachDeactivatedUser(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $emailA = 'user-logging-bulk-a-' . bin2hex(random_bytes(4)) . '@example.test';
        $emailB = 'user-logging-bulk-b-' . bin2hex(random_bytes(4)) . '@example.test';
        $userA = $this->makeActiveUser($emailA);
        $userB = $this->makeActiveUser($emailB);

        $controller->bulkDeactivate(
            $this->makeRequest('POST', '/users/bulk-deactivate', [
                'user_ids' => [(string) $userA->id, (string) $userB->id],
            ]),
            $this->makeResponse()
        );

        $records = $this->recordsFor($handler, 'user.deactivated');
        $ids = array_map(static fn ($record) => $record->context['user_id'], $records);

        $this->assertContains((int) $userA->id, $ids);
        $this->assertContains((int) $userB->id, $ids);
    }

    public function testRestoreUserIsLogged(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $email = 'user-logging-restore-' . bin2hex(random_bytes(4)) . '@example.test';
        $user = $this->makeActiveUser($email);
        $user->is_active = 0;
        $user->save();

        $controller->restore(
            $this->makeRequest('POST', '/users/restore/' . $user->id),
            $this->makeResponse(),
            ['id' => (string) $user->id]
        );

        $record = $this->recordFor($handler, 'user.activated');
        $this->assertNotNull($record);
        $this->assertSame((int) $user->id, $record->context['user_id']);
    }

    public function testUserPersistenceDeleteIsLogged(): void
    {
        [$logger, $handler] = $this->logger();
        $persistence = new UserPersistence($logger);

        $email = 'user-logging-delete-' . bin2hex(random_bytes(4)) . '@example.test';
        $user = $this->makeActiveUser($email);
        $userId = (int) $user->id;

        $persistence->delete($user);

        $record = $this->recordFor($handler, 'user.deleted');
        $this->assertNotNull($record);
        $this->assertSame($userId, $record->context['user_id']);
    }

    public function testInviteLogsInvitationCreatedWithUserIdNeverToken(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $email = 'user-logging-invite-' . bin2hex(random_bytes(4)) . '@example.test';
        $user = $this->makeActiveUser($email);

        $controller->invite(
            $this->makeRequest('POST', '/users/' . $user->id . '/invite'),
            $this->makeResponse(),
            ['id' => (string) $user->id]
        );

        $record = $this->recordFor($handler, 'invitation.created');
        $this->assertNotNull($record);
        $this->assertSame((int) $user->id, $record->context['user_id']);
        $this->assertArrayNotHasKey('token', $record->context);

        foreach ($handler->getRecords() as $rec) {
            $this->assertArrayNotHasKey('token', $rec->context);
        }
    }

    /**
     * user.create.failed carries the bcrypt password hash in the failing INSERT's
     * bindings. Illuminate\Database\QueryException::getMessage() appends those
     * bindings to the SQL - a plain 'exception' => $e log call would put the
     * hash into the log. A double that throws a fully-controlled QueryException
     * from save() lets this test embed a recognizable secret in the bindings
     * deterministically, without racing UserController::create()'s own
     * pre-insert uniqueness check.
     */
    public function testCreateUserFailureLogsSanitizedQueryExceptionWithoutBindings(): void
    {
        [$logger, $handler] = $this->logger();

        $recognizableSecretHash = '$2y$10$RecognizableSecretBcryptHashValueXXXXXXXXXXXXXXXXXXXXX';
        $email = 'user-logging-queryexception-' . bin2hex(random_bytes(4)) . '@example.test';

        $queryException = new QueryException(
            'mysql',
            'insert into `users` (`email`, `password`) values (?, ?)',
            [$email, $recognizableSecretHash],
            new PDOException('SQLSTATE[23000]: Integrity constraint violation')
        );

        $throwingPersistence = new class ($queryException) extends UserPersistence {
            public function __construct(private readonly QueryException $exception)
            {
                parent::__construct(new NullLogger());
            }

            public function save(User $user): bool
            {
                throw $this->exception;
            }
        };

        $controller = new UserController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            $this->createStub(ProjectQuery::class),
            $throwingPersistence,
            new ProjectPersistence(),
            $this->createStub(MailQueueService::class),
            $logger,
            new UserEditPolicy()
        );

        $controller->create(
            $this->makeRequest('POST', '/users', [
                'first_name' => 'Log',
                'last_name' => 'Test',
                'email' => $email,
                'roles' => [(string) $this->role->id],
            ]),
            $this->makeResponse()
        );

        $record = $this->recordFor($handler, 'user.create.failed');
        $this->assertNotNull($record);
        $this->assertArrayNotHasKey('exception', $record->context);
        $this->assertSame(QueryException::class, $record->context['exception_class']);
        $this->assertArrayHasKey('sql', $record->context);
        $this->assertArrayHasKey('driver_error', $record->context);

        $encoded = (string) json_encode($record->context);
        $this->assertStringNotContainsString($recognizableSecretHash, $encoded);
        $this->assertStringNotContainsString('bindings', $encoded);
    }

    private function makeActiveUser(string $email): User
    {
        return User::create([
            'first_name' => 'Log',
            'last_name' => 'Test',
            'email' => $email,
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }

    private function controller(Logger $logger): UserController
    {
        return new UserController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            $this->createStub(ProjectQuery::class),
            new UserPersistence($logger),
            new ProjectPersistence(),
            $this->createStub(MailQueueService::class),
            $logger,
            new UserEditPolicy()
        );
    }

    /**
     * @return list<\Monolog\LogRecord>
     */
    private function recordsFor(TestHandler $handler, string $event): array
    {
        return array_values(array_filter(
            $handler->getRecords(),
            static fn ($record): bool => ($record->context['event'] ?? null) === $event
        ));
    }
}
