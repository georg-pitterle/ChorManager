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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

final class UserEmailValidationFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private Role $role;

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
            'name' => 'Email Validation Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        User::where('email', 'like', 'email-validation-%@example.test')->delete();
        // The invalid-email inputs would slip past the '@example.test' cleanup above.
        User::where('email', 'not-an-email')->delete();
        User::where('email', 'like', 'aaaa%')->delete();
        $this->role->delete();
        $_SESSION = [];

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'malformed format' => ['not-an-email'],
            'too long for column' => [str_repeat('a', 400) . '@example.test'],
        ];
    }

    #[DataProvider('invalidEmailProvider')]
    public function testCreateRejectsInvalidEmailWithoutDatabaseError(string $invalidEmail): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $controller->create(
            $this->makeRequest('POST', '/users', [
                'first_name' => 'Valid',
                'last_name' => 'User',
                'email' => $invalidEmail,
                'roles' => [(string) $this->role->id],
            ]),
            $this->makeResponse()
        );

        // Neither a persisted row nor a swallowed QueryException: validation
        // short-circuits before the DB write.
        $this->assertNull($this->recordFor($handler, 'user.create.failed'));
        $this->assertSame(0, User::where('email', $invalidEmail)->count());
    }

    public function testUpdateRejectsInvalidEmailAndKeepsStoredValue(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $oldEmail = 'email-validation-old-' . bin2hex(random_bytes(4)) . '@example.test';
        $user = User::create([
            'first_name' => 'Valid',
            'last_name' => 'User',
            'email' => $oldEmail,
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($this->role->id);

        $controller->update(
            $this->makeRequest('POST', '/users/' . $user->id, [
                'first_name' => 'Valid',
                'last_name' => 'User',
                'email' => 'not-an-email',
                'roles' => [(string) $this->role->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $user->id]
        );

        $this->assertNull($this->recordFor($handler, 'user.update.failed'));
        $this->assertSame($oldEmail, User::find($user->id)->email);
    }

    private function controller(\Monolog\Logger $logger): UserController
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
}
