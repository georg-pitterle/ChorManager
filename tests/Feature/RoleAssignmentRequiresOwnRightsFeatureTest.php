<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\Role;
use App\Models\User;
use App\Persistence\UserPersistence;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Rollen vergibt nur, wer deren Rechte selbst besitzt; die Adresse eines
 * Gleichrangigen ändert niemand.
 *
 * Beide Wege führten bisher zur Übernahme fremder Rechte: eine gleichrangige
 * Rolle mit mehr Rechten an sich selbst oder einen Helfer vergeben, oder die
 * Adresse eines gleichrangigen Kontos ändern und danach dessen Passwort
 * zurücksetzen.
 */
final class RoleAssignmentRequiresOwnRightsFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    private Role $memberRole;
    private Role $eventsRole;
    private Role $financeRole;
    private User $actor;
    private User $target;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $suffix = bin2hex(random_bytes(4));

        $this->memberRole = Role::create(['name' => 'Mitglied ' . $suffix, 'hierarchy_level' => 0]);
        // Zwei Vorstandsrollen auf derselben Stufe mit verschiedenen Rechten.
        $this->eventsRole = Role::create([
            'name' => 'Vorstand Termine ' . $suffix,
            'hierarchy_level' => 50,
            'can_manage_users' => 1,
            'can_edit_users' => 1,
            'can_manage_events' => 1,
        ]);
        $this->financeRole = Role::create([
            'name' => 'Vorstand Kassa ' . $suffix,
            'hierarchy_level' => 50,
            'can_manage_users' => 1,
            'can_edit_users' => 1,
            'can_manage_finances' => 1,
        ]);

        $this->actor = $this->createUser('handelnd', $suffix, [$this->eventsRole->id]);
        $this->target = $this->createUser('ziel', $suffix, [$this->memberRole->id]);

        $_SESSION = [
            'user_id' => (int) $this->actor->id,
            'can_manage_users' => true,
            'can_edit_users' => true,
            'can_manage_events' => true,
            'role_level' => 50,
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

    public function testRoleWithRightsTheActorLacksIsNotAssigned(): void
    {
        $this->update($this->target, [$this->memberRole->id, $this->financeRole->id]);

        $this->assertSame([$this->memberRole->id], $this->roleIdsOf($this->target));
    }

    public function testRoleWithinTheActorsRightsIsAssigned(): void
    {
        $this->update($this->target, [$this->memberRole->id, $this->eventsRole->id]);

        $this->assertSame(
            [$this->memberRole->id, $this->eventsRole->id],
            $this->roleIdsOf($this->target)
        );
    }

    /**
     * Die Gegenrichtung: Eine Rolle, die der Handelnde nicht vergeben darf, darf
     * er auch nicht wegnehmen. Fehlt sie im Formular - weil es sie gar nicht
     * zum Ankreuzen anbietet -, bleibt sie dem Mitglied erhalten.
     */
    public function testRoleTheActorCannotAssignStaysWithThePeer(): void
    {
        $peer = $this->createUser('kassa', bin2hex(random_bytes(4)), [$this->financeRole->id]);

        $this->update($peer, [$this->memberRole->id]);

        $this->assertSame(
            [$this->memberRole->id, $this->financeRole->id],
            $this->roleIdsOf($peer)
        );
    }

    public function testEmailOfAPeerStaysUnchanged(): void
    {
        $peer = $this->createUser('kassa', bin2hex(random_bytes(4)), [$this->financeRole->id]);
        $originalEmail = (string) $peer->email;

        $this->update($peer, [$this->financeRole->id], 'uebernahme@example.test'); // naming:ascii

        $peer->refresh();
        $this->assertSame($originalEmail, $peer->email);
    }

    public function testEmailOfALowerRankedMemberCanStillBeChanged(): void
    {
        $this->update($this->target, [$this->memberRole->id], 'neue.adresse@example.test');

        $this->target->refresh();
        $this->assertSame('neue.adresse@example.test', $this->target->email);
    }

    /**
     * Das Formular bietet nur an, was beim Speichern auch durchgeht, und zeigt
     * eine nicht vergebbare Rolle des Mitglieds gesperrt statt gar nicht.
     */
    public function testEditFormOffersOnlyAssignableRolesAndLocksTheOthers(): void
    {
        $peer = $this->createUser('kassa', bin2hex(random_bytes(4)), [$this->financeRole->id]);

        $this->controller()->editForm(
            $this->makeRequest('GET', '/users/' . $peer->id . '/edit-form'),
            $this->makeResponse(),
            ['id' => (string) $peer->id]
        );

        [, $data] = end($this->renderCalls);
        $offered = array_map(static fn ($role): int => (int) $role->id, $data['roles']->all());

        $this->assertContains($this->eventsRole->id, $offered);
        $this->assertContains($this->financeRole->id, $offered, 'Die vorhandene Rolle bleibt sichtbar');
        $this->assertSame([$this->financeRole->id], $data['locked_role_ids']);
    }

    public function testCreateFormOffersOnlyAssignableRoles(): void
    {
        $this->controller()->index($this->makeRequest('GET', '/users'), $this->makeResponse());

        [, $data] = end($this->renderCalls);

        $this->assertContains($this->eventsRole->id, $data['assignable_role_ids']);
        $this->assertNotContains($this->financeRole->id, $data['assignable_role_ids']);
    }

    public function testTemplatesUseTheAssignableAndLockedRoles(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertStringContainsString(
            'locked_role_ids',
            (string) file_get_contents($root . '/templates/partials/user_edit_form.twig')
        );
        $this->assertStringContainsString(
            'assignable_role_ids',
            (string) file_get_contents($root . '/templates/users/manage.twig')
        );
    }

    /**
     * @param list<int> $roleIds
     */
    private function update(User $target, array $roleIds, ?string $email = null): void
    {
        $this->controller()->update(
            $this->makeRequest('POST', '/users/' . $target->id, [
                'first_name' => (string) $target->first_name,
                'last_name' => (string) $target->last_name,
                'email' => $email ?? (string) $target->email,
                'roles' => array_map('strval', $roleIds),
            ]),
            $this->makeResponse(),
            ['id' => (string) $target->id]
        );
    }

    /**
     * @return list<int>
     */
    private function roleIdsOf(User $user): array
    {
        $ids = Capsule::table('user_roles')->where('user_id', $user->id)->pluck('role_id')
            ->map(static fn ($id): int => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    /**
     * @param list<int> $roleIds
     */
    private function createUser(string $prefix, string $suffix, array $roleIds): User
    {
        $user = User::create([
            'first_name' => 'Rolle',
            'last_name' => ucfirst($prefix),
            'email' => $prefix . '.' . $suffix . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
        $user->roles()->attach($roleIds);

        return $user;
    }

    private function controller(): UserController
    {
        $logger = new Logger('test');

        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data = []): ResponseInterface {
                $this->renderCalls[] = [$template, $data];
                return $response;
            }
        );

        return $this->userController([
            'view' => $twig,
            'userPersistence' => new UserPersistence($logger),
            'logger' => $logger,
        ]);
    }
}
