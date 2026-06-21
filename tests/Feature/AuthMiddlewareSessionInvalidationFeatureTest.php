<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\AuthMiddleware;
use App\Models\AppSetting;
use App\Models\Role;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class AuthMiddlewareSessionInvalidationFeatureTest extends TestCase
{
    private static ?Capsule $capsule = null;
    private AuthMiddleware $middleware;
    private User $user;
    private Role $role;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->role = Role::create([
            'name' => 'Invalidation Test Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $this->user = User::create([
            'first_name' => 'Invalidation',
            'last_name' => 'Tester',
            'email' => 'invalidation.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $this->user->roles()->attach($this->role->id);

        $this->middleware = new AuthMiddleware(
            new UserQuery(),
            new RememberLoginService(),
            new SessionAuthService()
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        AppSetting::query()->where('setting_key', 'session_valid_after')->delete();
        $_SESSION = ['user_id' => $this->user->id, 'auth_epoch' => time()];
    }

    protected function tearDown(): void
    {
        AppSetting::query()->where('setting_key', 'session_valid_after')->delete();
        $this->user->delete();
        $this->role->delete();

        parent::tearDown();
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    public function testRequestPassesWhenNoSessionInvalidationIsSet(): void
    {
        $response = $this->middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->handler()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRequestIsRedirectedToLoginWhenSessionPredatesInvalidation(): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => 'session_valid_after'],
            [
                'setting_value' => (string) (time() + 3600),
                'binary_content' => '',
                'mime_type' => 'text/plain',
            ]
        );

        $response = $this->middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->handler()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }
}
