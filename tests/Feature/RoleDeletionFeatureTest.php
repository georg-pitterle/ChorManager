<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

/**
 * Rollen loeschen darf nur, wer Rollen verwalten darf. Zusaetzlich muss die Rolle frei
 * sein: solange ihr noch ein Mitglied zugewiesen ist - auch ein archiviertes - bleibt sie
 * bestehen, sonst stuende ein reaktiviertes Mitglied ohne Rolle da. Das Hierarchie-Level
 * schuetzt hoeher eingestufte Rollen wie beim Bearbeiten.
 */
final class RoleDeletionFeatureTest extends TestCase
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

        $capsule->schema()->create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->integer('hierarchy_level')->default(0);
            $table->boolean('can_manage_users')->default(false);
            $table->boolean('can_manage_roles')->default(false);
        });

        $capsule->schema()->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('first_name')->default('Test');
            $table->string('last_name')->default('Person');
            $table->string('email')->default('test@example.test');
            $table->boolean('is_active')->default(true);
        });

        $capsule->schema()->create('user_roles', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('role_id');
        });
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
            'is_active' => $active,
        ]);

        Capsule::table('user_roles')->insert(['user_id' => $userId, 'role_id' => (int) $role->id]);
    }

    public function testDeleteRemovesUnassignedRoleAtActorLevel(): void
    {
        $_SESSION['role_level'] = 50;
        $role = Role::create(['name' => 'Frei', 'hierarchy_level' => 50]);

        $this->assertSame(302, $this->delete((int) $role->id));
        $this->assertSame('Rolle erfolgreich gelöscht.', $_SESSION['success'] ?? null);
        $this->assertNull(Role::find($role->id));
    }

    public function testDeleteRejectsRoleWithAssignedMember(): void
    {
        $_SESSION['role_level'] = 100;
        $role = Role::create(['name' => 'Belegt', 'hierarchy_level' => 50]);
        $this->assignMember($role, true);

        $this->assertSame(302, $this->delete((int) $role->id));
        $this->assertNull($_SESSION['success'] ?? null);
        $this->assertNotNull($_SESSION['error'] ?? null);
        $this->assertNotNull(Role::find($role->id));
    }

    public function testDeleteRejectsRoleWithArchivedMemberOnly(): void
    {
        $_SESSION['role_level'] = 100;
        $role = Role::create(['name' => 'Nur archiviert', 'hierarchy_level' => 50]);
        $this->assignMember($role, false);

        $this->assertSame(302, $this->delete((int) $role->id));
        $this->assertNotNull($_SESSION['error'] ?? null);
        $this->assertNotNull(Role::find($role->id));
    }

    public function testDeleteRejectsRoleAboveActorLevel(): void
    {
        $_SESSION['role_level'] = 50;
        $role = Role::create(['name' => 'Hoeher', 'hierarchy_level' => 80]);

        $this->assertSame(302, $this->delete((int) $role->id));
        $this->assertNotNull($_SESSION['error'] ?? null);
        $this->assertNotNull(Role::find($role->id));
    }

    public function testDeleteReportsMissingRole(): void
    {
        $_SESSION['role_level'] = 100;

        $this->assertSame(302, $this->delete(4711));
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
