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
use App\Services\SessionAuthService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

/**
 * Terminverwaltung war bis dahin an can_manage_users gekoppelt: wer Termine anlegen
 * durfte, bekam zwangslaeufig auch Mitglieder-, Rollen- und Projektverwaltung.
 * can_manage_events trennt das als eigenstaendiges Recht ohne Admin-Fallback.
 */
final class EventManagementPermissionFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 7];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function passesGate(): int
    {
        $middleware = new RoleMiddleware(requiresEventManagement: true);

        return $middleware->process(
            (new ServerRequestFactory())->createServerRequest('POST', '/events'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        )->getStatusCode();
    }

    public function testEventPermissionPassesEventGate(): void
    {
        $_SESSION['can_manage_events'] = true;
        $_SESSION['can_manage_users'] = false;

        $this->assertSame(200, $this->passesGate());
    }

    public function testUserManagementAloneDoesNotPassEventGate(): void
    {
        $_SESSION['can_manage_events'] = false;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_manage_master_data'] = true;

        $this->assertSame(403, $this->passesGate());
    }

    public function testBuildPermissionFlagsMapsEventPermission(): void
    {
        $flags = RoleController::buildPermissionFlags(['can_manage_events' => '1']);
        $this->assertSame(1, $flags['can_manage_events']);

        $flagsOff = RoleController::buildPermissionFlags([]);
        $this->assertSame(0, $flagsOff['can_manage_events']);
    }

    public function testSessionExposesEventPermissionFromRole(): void
    {
        Bootstrap::setupTestDatabase();

        $role = Role::create([
            'name' => 'Terminplanung ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_events' => 1,
        ]);

        $user = User::create([
            'first_name' => 'Termin',
            'last_name' => 'Planer',
            'email' => 'termin.planer.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);
        $user->load('roles', 'voiceGroups');

        (new SessionAuthService(new \App\Services\NameFormatterService(), new RequestContext()))
            ->setAuthenticatedUser($user);

        $this->assertTrue($_SESSION['can_manage_events']);
        $this->assertFalse($_SESSION['can_manage_users']);

        $user->delete();
        $role->delete();
    }

    public function testRoutesGateEventCrudAndEventTypesOnEventPermission(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');
        $this->assertIsString($routes);

        $eventGroup = '#\$eventGroup->post\(\'/events\', \[EventController::class, \'create\'\]\);'
            . '(?:(?!\)->add\().)*'
            . '\)->add\(new RoleMiddleware\(requiresEventManagement: true\)\);#s';
        $this->assertMatchesRegularExpression($eventGroup, $routes);

        $this->assertStringContainsString(
            "\$eventGroup->get('/event-types', [EventTypeController::class, 'index']);",
            $routes
        );
        $this->assertStringNotContainsString(
            "\$masterGroup->get('/event-types', [EventTypeController::class, 'index']);",
            $routes
        );
    }

    public function testEventTemplatesGateAdminActionsOnEventPermission(): void
    {
        $index = file_get_contents(dirname(__DIR__, 2) . '/templates/events/index.twig');
        $detail = file_get_contents(dirname(__DIR__, 2) . '/templates/events/detail.twig');
        $this->assertIsString($index);
        $this->assertIsString($detail);

        $this->assertStringContainsString('session.can_manage_events', $index);
        $this->assertStringContainsString('session.can_manage_events', $detail);
        $this->assertStringNotContainsString('{% if session.can_manage_users %}', $index);
        $this->assertStringNotContainsString('{% if session.can_manage_users %}', $detail);
    }

    public function testRolesUiOffersEventPermission(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/roles/index.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('id="can_manage_events"', $template);
        $this->assertStringContainsString('id="edit_can_manage_events"', $template);
        $this->assertStringContainsString('name="can_manage_events"', $template);
        $this->assertStringContainsString('data-events="', $template);

        $rowPattern = '#<th scope="row" class="roles-matrix-label">Termine verwalten</th>\s*'
            . '\{% for role in roles %\}\s*'
            . '<td[^>]*>\s*'
            . '\{% if role\.can_manage_events %\}#s';
        $this->assertMatchesRegularExpression($rowPattern, $template);

        $js = file_get_contents(dirname(__DIR__, 2) . '/public/js/roles.js');
        $this->assertIsString($js);
        $this->assertStringContainsString('edit_can_manage_events', $js);
        $this->assertStringContainsString('data-events', $js);
    }

    public function testNavigationShowsEventTypesForEventManagersOnly(): void
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

        $this->assertContains('/event-types', $urlsFor(['can_manage_events' => true]));
        $this->assertNotContains(
            '/event-types',
            $urlsFor(['can_manage_users' => true, 'can_manage_master_data' => true])
        );
    }

    public function testSeedGrantsEventPermissionToPlanningRoles(): void
    {
        $seed = file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');
        $this->assertIsString($seed);
        $this->assertStringContainsString("'can_manage_events' => 1", $seed);
        $this->assertStringContainsString("'can_manage_events' => 0", $seed);
    }
}
