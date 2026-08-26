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
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Gleichrangige Mitgliederverwalter dürfen einander verwalten - das ist gewollt,
 * sonst könnten zwei Vorstände einander nie vertreten. Ohne Absicherung lässt
 * sich damit aber der letzte verbleibende Verwalter archivieren oder entrechten,
 * und niemand kommt mehr an die Mitgliederverwaltung heran.
 */
final class UserLastManagerGuardFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private Role $managerRole;
    private Role $memberRole;
    private User $lastManager;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        // Bestehende Verwalter aus dem Testbestand stilllegen, damit der Fall
        // "letzter Verwalter" unabhängig vom Seed-Stand eintritt.
        Capsule::table('users')->update(['is_active' => 0]);

        $suffix = bin2hex(random_bytes(4));
        $this->managerRole = Role::create([
            'name' => 'Verwaltung ' . $suffix,
            'hierarchy_level' => 90,
            'can_manage_users' => 1,
        ]);
        $this->memberRole = Role::create(['name' => 'Mitglied ' . $suffix, 'hierarchy_level' => 10]);

        $this->lastManager = User::create([
            'first_name' => 'Letzte',
            'last_name' => 'Verwaltung',
            'email' => 'last.manager.' . $suffix . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $this->lastManager->roles()->attach($this->managerRole->id);

        $_SESSION = [
            'user_id' => 999002,
            'can_manage_users' => true,
            'can_edit_users' => true,
            'role_level' => 90,
            'voice_group_ids' => [],
        ];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
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

    public function testTheLastUserManagerCannotBeArchived(): void
    {
        $this->controller()->deactivate(
            $this->makeRequest('POST', '/users/' . $this->lastManager->id . '/deactivate'),
            $this->makeResponse(),
            ['id' => (string) $this->lastManager->id]
        );

        $this->assertSame(1, (int) $this->lastManager->fresh()->is_active);
        $this->assertNotEmpty($_SESSION['error'] ?? '');
    }

    public function testTheLastUserManagerCannotLoseTheManagingRole(): void
    {
        $this->controller()->update(
            $this->makeRequest('POST', '/users/' . $this->lastManager->id, [
                'first_name' => 'Letzte',
                'last_name' => 'Verwaltung',
                'email' => (string) $this->lastManager->email,
                'roles' => [(string) $this->memberRole->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $this->lastManager->id]
        );

        $roleIds = $this->lastManager->fresh()->roles->pluck('id')
            ->map(static fn($id): int => (int) $id)->all();

        $this->assertContains((int) $this->managerRole->id, $roleIds);
        $this->assertNotEmpty(
            $_SESSION['user_edit_' . $this->lastManager->id . '_message'] ?? '',
            'Der Sperrgrund muss im Bearbeiten-Modal sichtbar werden.'
        );
    }

    public function testASecondManagerMakesTheRoleRemovable(): void
    {
        $second = User::create([
            'first_name' => 'Zweite',
            'last_name' => 'Verwaltung',
            'email' => 'second.manager.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $second->roles()->attach($this->managerRole->id);

        $this->controller()->update(
            $this->makeRequest('POST', '/users/' . $this->lastManager->id, [
                'first_name' => 'Letzte',
                'last_name' => 'Verwaltung',
                'email' => (string) $this->lastManager->email,
                'roles' => [(string) $this->memberRole->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $this->lastManager->id]
        );

        $roleIds = $this->lastManager->fresh()->roles->pluck('id')
            ->map(static fn($id): int => (int) $id)->all();

        $this->assertNotContains((int) $this->managerRole->id, $roleIds);
    }
}
