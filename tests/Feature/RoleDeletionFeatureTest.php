<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Rollen loeschen darf nur, wer Rollen verwalten darf. Zusaetzlich muss die Rolle frei
 * sein: solange ihr noch ein Mitglied zugewiesen ist - auch ein archiviertes - bleibt sie
 * bestehen, sonst stuende ein reaktiviertes Mitglied ohne Rolle da. Das Hierarchie-Level
 * schuetzt hoeher eingestufte Rollen wie beim Bearbeiten.
 */
final class RoleDeletionFeatureTest extends TestCase
{
    use TestHttpHelpers;

    /** Haengt an jeden Rollennamen, damit er in der geteilten Datenbank eindeutig bleibt. */
    private string $suffix = '';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
        $this->suffix = bin2hex(random_bytes(4));
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

    private function roleName(string $label): string
    {
        return $label . ' ' . $this->suffix;
    }

    private function controller(): RoleController
    {
        return new RoleController($this->createStub(Twig::class));
    }

    private function delete(int $roleId): int
    {
        $response = $this->controller()->delete(
            $this->makeRequest('POST', '/roles/' . $roleId . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $roleId]
        );

        return $response->getStatusCode();
    }

    private function assignMember(Role $role, bool $active): void
    {
        $userId = (int) Capsule::table('users')->insertGetId([
            'first_name' => 'Zuweisung',
            'last_name' => 'Testperson',
            'email' => 'zuweisung' . bin2hex(random_bytes(3)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => $active,
        ]);

        Capsule::table('user_roles')->insert(['user_id' => $userId, 'role_id' => (int) $role->id]);
    }

    public function testDeleteRemovesUnassignedRoleAtActorLevel(): void
    {
        $_SESSION['role_level'] = 50;
        $role = Role::create(['name' => $this->roleName('Frei'), 'hierarchy_level' => 50]);

        $this->assertSame(302, $this->delete((int) $role->id));
        $this->assertSame('Rolle erfolgreich gelöscht.', $_SESSION['success'] ?? null);
        $this->assertNull(Role::find($role->id));
    }

    public function testDeleteRejectsRoleWithAssignedMember(): void
    {
        $_SESSION['role_level'] = 100;
        $role = Role::create(['name' => $this->roleName('Belegt'), 'hierarchy_level' => 50]);
        $this->assignMember($role, true);

        $this->assertSame(302, $this->delete((int) $role->id));
        $this->assertNull($_SESSION['success'] ?? null);
        $this->assertNotNull($_SESSION['error'] ?? null);
        $this->assertNotNull(Role::find($role->id));
    }

    public function testDeleteRejectsRoleWithArchivedMemberOnly(): void
    {
        $_SESSION['role_level'] = 100;
        $role = Role::create(['name' => $this->roleName('Nur archiviert'), 'hierarchy_level' => 50]);
        $this->assignMember($role, false);

        $this->assertSame(302, $this->delete((int) $role->id));
        $this->assertNotNull($_SESSION['error'] ?? null);
        $this->assertNotNull(Role::find($role->id));
    }

    public function testDeleteRejectsRoleAboveActorLevel(): void
    {
        $_SESSION['role_level'] = 50;
        $role = Role::create(['name' => $this->roleName('Hoeher'), 'hierarchy_level' => 80]);

        $this->assertSame(302, $this->delete((int) $role->id));
        $this->assertNotNull($_SESSION['error'] ?? null);
        $this->assertNotNull(Role::find($role->id));
    }

    public function testDeleteReportsMissingRole(): void
    {
        $_SESSION['role_level'] = 100;

        // Bewusst weit ueber jeder real vergebenen Rollen-Id, damit der Test
        // unabhaengig vom Bestand der geteilten Datenbank eine sicher nicht
        // existierende Id trifft.
        $missingRoleId = (int) (Role::query()->max('id') ?? 0) + 100000;

        $this->assertSame(302, $this->delete($missingRoleId));
        $this->assertSame('Rolle nicht gefunden.', $_SESSION['error'] ?? null);
    }

    public function testDeleteRouteIsGatedOnRoleManagement(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');
        $this->assertIsString($routes);

        $roleGroup = '#\$roleGroup->post\(\'/\{id:\[0-9\]\+\}/delete\', \[RoleController::class, \'delete\'\]\);'
            . '(?:(?!\)->add\().)*'
            . '\)->add\(new RoleMiddleware\(requiresRoleManagement: true\)\);#s';
        $this->assertMatchesRegularExpression($roleGroup, $routes);
    }

    public function testMatrixOffersDeleteOnlyForUnassignedRoles(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/roles/index.twig');
        $this->assertIsString($template);

        $this->assertStringContainsString('/roles/{{ role.id }}/delete', $template);
        $this->assertStringContainsString('role.assigned_users_count == 0', $template);

        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/RoleController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString("'users as assigned_users_count'", $controller);
    }
}
