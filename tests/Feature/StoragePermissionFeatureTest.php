<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Middleware\RoleMiddleware;
use App\Models\Role;
use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

/**
 * Das Recht "Speicherplatz-Verwaltung" an allen Stellen, an denen ein Rollenrecht
 * ankommen muss: Migration, Rollenformular, Gate.
 */
final class StoragePermissionFeatureTest extends TestCase
{
    private const MIGRATION = '/db/migrations/20261007090000_add_can_manage_storage_to_roles.php';

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = ['user_id' => 7];
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        $_SESSION = [];
    }

    /**
     * Die Migration vergibt das Recht an das höchste vorhandene Level, auch wenn es
     * nicht 100 ist - sonst hätte es nach einer umgestellten Rollenordnung niemand.
     */
    public function testMigrationGrantsTheRightToEveryRoleOnTheHighestLevelOnly(): void
    {
        require_once dirname(__DIR__, 2) . self::MIGRATION;

        DB::table('roles')->update(['hierarchy_level' => 1, 'can_manage_storage' => 0]);
        $top = Role::create(['name' => 'Oberste ' . bin2hex(random_bytes(4)), 'hierarchy_level' => 500]);
        $secondTop = Role::create(['name' => 'Auch oben ' . bin2hex(random_bytes(4)), 'hierarchy_level' => 500]);
        $below = Role::create(['name' => 'Darunter ' . bin2hex(random_bytes(4)), 'hierarchy_level' => 499]);

        DB::statement(\AddCanManageStorageToRoles::GRANT_SQL);

        $this->assertSame(1, (int) $top->fresh()?->can_manage_storage);
        $this->assertSame(1, (int) $secondTop->fresh()?->can_manage_storage);
        $this->assertSame(0, (int) $below->fresh()?->can_manage_storage);
        $this->assertSame(2, DB::table('roles')->where('can_manage_storage', 1)->count());
    }

    public function testRoleFormMapsTheRight(): void
    {
        $this->assertSame(1, RoleController::buildPermissionFlags(['can_manage_storage' => '1'])['can_manage_storage']);
        $this->assertSame(0, RoleController::buildPermissionFlags([])['can_manage_storage']);
    }

    public function testRoleTemplateAndScriptCarryTheRight(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/roles/index.twig');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/roles.js');

        $this->assertStringContainsString('role.can_manage_storage', $template);
        $this->assertStringContainsString('name="can_manage_storage"', $template);
        $this->assertStringContainsString('id="edit_can_manage_storage"', $template);
        $this->assertStringContainsString('data-storage=', $template);
        $this->assertStringContainsString('Speicherplatz-Verwaltung', $template);
        $this->assertStringContainsString("'edit_can_manage_storage'", $script);
    }

    public function testSetupAndSeedGiveTheAdminRoleTheRight(): void
    {
        $setup = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/AuthController.php');
        $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertStringContainsString("'can_manage_storage' => 1", $setup);
        $this->assertStringContainsString("'can_manage_storage' => 1", $seed);
    }

    public function testGateLetsTheRightThroughAndStopsOthers(): void
    {
        $_SESSION['can_manage_storage'] = true;
        $this->assertSame(200, $this->pass(new RoleMiddleware(requiresStorageManagement: true)));

        $_SESSION['can_manage_storage'] = false;
        $_SESSION['can_manage_backups'] = true;
        $_SESSION['can_manage_roles'] = true;
        $this->assertSame(403, $this->pass(new RoleMiddleware(requiresStorageManagement: true)));
    }

    private function pass(RoleMiddleware $middleware): int
    {
        return $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/storage'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        )->getStatusCode();
    }
}
