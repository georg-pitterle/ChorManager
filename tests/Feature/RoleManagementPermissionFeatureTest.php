<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Logging\RequestContext;
use App\Middleware\RoleMiddleware;
use App\Models\Role;
use App\Models\User;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\NameFormatterService;
use App\Services\SessionAuthService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

/**
 * Rollenverwaltung war an can_manage_users gekoppelt: wer Mitglieder verwalten durfte,
 * konnte sich ueber die Rollen jedes beliebige Recht selbst geben. can_manage_roles trennt
 * das Vergeben von Rechten (Rollen anlegen/bearbeiten) vom Zuweisen von Rollen an Mitglieder.
 */
final class RoleManagementPermissionFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 7];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function roleGateStatus(): int
    {
        $middleware = new RoleMiddleware(requiresRoleManagement: true);

        return $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/roles'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        )->getStatusCode();
    }

    public function testRolePermissionPassesRoleGate(): void
    {
        $_SESSION['can_manage_roles'] = true;
        $_SESSION['can_manage_users'] = false;

        $this->assertSame(200, $this->roleGateStatus());
    }

    public function testUserManagementAloneDoesNotPassRoleGate(): void
    {
        $_SESSION['can_manage_roles'] = false;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_edit_users'] = true;

        $this->assertSame(403, $this->roleGateStatus());
    }

    public function testHighHierarchyLevelDoesNotPassRoleGate(): void
    {
        $_SESSION['can_manage_roles'] = false;
        $_SESSION['role_level'] = 100;

        $this->assertSame(403, $this->roleGateStatus());
    }

    public function testBuildPermissionFlagsMapsRolePermission(): void
    {
        $flags = RoleController::buildPermissionFlags(['can_manage_roles' => '1']);
        $this->assertSame(1, $flags['can_manage_roles']);

        $flagsOff = RoleController::buildPermissionFlags([]);
        $this->assertSame(0, $flagsOff['can_manage_roles']);
    }

    public function testSessionExposesRolePermissionFromRole(): void
    {
        Bootstrap::setupTestDatabase();

        $role = Role::create([
            'name' => 'Rollenpflege ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_roles' => 1,
        ]);

        $user = User::create([
            'first_name' => 'Rollen',
            'last_name' => 'Pfleger',
            'email' => 'rollen.pfleger.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);
        $user->load('roles', 'voiceGroups');

        (new SessionAuthService(new NameFormatterService(), new RequestContext()))->setAuthenticatedUser($user);

        $this->assertTrue($_SESSION['can_manage_roles']);
        $this->assertFalse($_SESSION['can_manage_users']);

        $user->roles()->detach();
        $user->delete();
        $role->delete();
    }

    public function testRoutesGateRoleCrudOnRolePermission(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');
        $this->assertIsString($routes);

        $roleGroup = '#\$roleGroup->get\(\'\', \[RoleController::class, \'index\'\]\);'
            . '(?:(?!\)->add\().)*'
            . '\)->add\(new RoleMiddleware\(requiresRoleManagement: true\)\);#s';
        $this->assertMatchesRegularExpression($roleGroup, $routes);
    }

    public function testRolesUiOffersRolePermission(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/roles/index.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('id="can_manage_roles"', $template);
        $this->assertStringContainsString('id="edit_can_manage_roles"', $template);
        $this->assertStringContainsString('name="can_manage_roles"', $template);
        $this->assertStringContainsString('data-roles="', $template);

        $rowPattern = '#<th scope="row" class="roles-matrix-label">Rollen verwalten</th>\s*'
            . '\{% for role in roles %\}\s*'
            . '<td[^>]*>\s*'
            . '\{% if role\.can_manage_roles %\}#s';
        $this->assertMatchesRegularExpression($rowPattern, $template);

        $js = file_get_contents(dirname(__DIR__, 2) . '/public/js/roles.js');
        $this->assertIsString($js);
        $this->assertStringContainsString('edit_can_manage_roles', $js);
        $this->assertStringContainsString('data-roles', $js);
    }

    public function testNavigationShowsRolesForRoleManagersOnly(): void
    {
        $builder = new NavigationBuilder();

        $urlsFor = static function (array $permissions) use ($builder): array {
            $tree = $builder->build(new NavigationContext($permissions, [], '/dashboard'));
            $urls = [];
            foreach ($tree as $node) {
                if ($node['type'] === 'link') {
                    $urls[] = $node['url'];
                    continue;
                }
                foreach ($node['items'] as $item) {
                    $urls[] = $item['url'];
                }
            }

            return $urls;
        };

        $this->assertContains('/roles', $urlsFor(['can_manage_roles' => true]));
        $this->assertNotContains('/roles', $urlsFor(['can_manage_users' => true]));
    }

    public function testSeedGrantsRolePermissionToAdministrativeRolesOnly(): void
    {
        $seed = file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');
        $this->assertIsString($seed);
        $this->assertStringContainsString("'can_manage_roles' => 1", $seed);
        $this->assertStringContainsString("'can_manage_roles' => 0", $seed);
    }

    public function testSetupCreatesAdminRoleWithRolePermission(): void
    {
        $auth = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/AuthController.php');
        $this->assertIsString($auth);
        $this->assertStringContainsString("'can_manage_roles' => 1", $auth);
    }

    public function testRoleColumnExistsAndIsFillable(): void
    {
        Bootstrap::setupTestDatabase();

        $this->assertContains('can_manage_roles', (new Role())->getFillable());

        $columns = \Illuminate\Database\Capsule\Manager::select("SHOW COLUMNS FROM roles LIKE 'can_manage_roles'");
        $this->assertCount(1, $columns);
    }
}
