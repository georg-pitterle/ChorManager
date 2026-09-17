<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AuthController;
use App\Controllers\PasswordResetController;
use App\Logging\RequestContext;
use App\Models\Role;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use App\Services\PasswordPolicyService;
use App\Services\RateLimiterService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Util\InputValidator;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die offenen Endpunkte nehmen Formularfelder entgegen, die ein Browser als
 * Text schickt. `email[]=x` statt `email=x` macht daraus ein Array, und wo der
 * Wert ungeprüft in eine Funktion mit Typangabe lief, endete das in einem
 * TypeError: Statusseite 500 samt Stapelverlauf im Protokoll, auslösbar ohne
 * Anmeldung und beliebig oft.
 */
class ControllerArrayInputRobustnessFeatureTest extends TestCase
{
    private string $rateLimiterStoreDir;
    private ?User $user = null;
    private ?Role $role = null;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->rateLimiterStoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'chormanager_array_input_test_' . bin2hex(random_bytes(4));

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->user?->delete();
        $this->role?->delete();

        if (is_dir($this->rateLimiterStoreDir)) {
            foreach (glob($this->rateLimiterStoreDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->rateLimiterStoreDir);
        }

        parent::tearDown();
    }

    private function makeAuthController(): AuthController
    {
        return new AuthController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new RememberLoginService(),
            new SessionAuthService(new NameFormatterService(), new RequestContext()),
            new RateLimiterService($this->rateLimiterStoreDir),
            new PasswordPolicyService(),
            new NullLogger()
        );
    }

    private function makePasswordResetController(): PasswordResetController
    {
        return new PasswordResetController(
            $this->createStub(Twig::class),
            null,
            new RateLimiterService($this->rateLimiterStoreDir),
            new PasswordPolicyService(),
            null,
            new NullLogger()
        );
    }

    public function testInputValidatorAcceptsNonScalarWithoutCrashing(): void
    {
        $this->assertNull(InputValidator::validateEmail(['a@example.test']));
        $this->assertNull(InputValidator::validateRequired(['Anna'], 255));
        $this->assertNull(InputValidator::validateEmail(new \stdClass()));
        $this->assertNull(InputValidator::validateRequired(new \stdClass()));

        // Die bisherigen Aufrufe müssen unverändert weiterlaufen.
        $this->assertSame('a@example.test', InputValidator::validateEmail(' A@Example.Test '));
        $this->assertSame('Anna', InputValidator::validateRequired(' Anna ', 255));
        $this->assertNull(InputValidator::validateEmail(null));
        $this->assertNull(InputValidator::validateRequired(null));
    }

    public function testLoginRejectsArrayEmailAndPasswordInsteadOfCrashing(): void
    {
        $controller = $this->makeAuthController();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login')
            ->withParsedBody(['email' => ['a@example.test'], 'password' => ['secret']]);

        $response = $controller->processLogin($request, new SlimResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertSame('Ungültige E-Mail-Adresse oder Passwort.', $_SESSION['error'] ?? null);
    }

    public function testLoginRejectsArrayPasswordForAnExistingAccountInsteadOfCrashing(): void
    {
        $this->role = Role::create([
            'name' => 'Array Input Test Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $this->user = User::create([
            'first_name' => 'Array',
            'last_name' => 'Tester',
            'email' => 'array.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('Correct-Horse-1'),
            'is_active' => 1,
        ]);
        $this->user->roles()->attach($this->role->id);

        $controller = $this->makeAuthController();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login')
            ->withParsedBody(['email' => $this->user->email, 'password' => ['Correct-Horse-1']]);

        $response = $controller->processLogin($request, new SlimResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertSame('Ungültige E-Mail-Adresse oder Passwort.', $_SESSION['error'] ?? null);
    }

    public function testSetupRejectsArrayFieldsInsteadOfCrashing(): void
    {
        if (User::count() > 0) {
            $this->markTestSkipped('Setup läuft nur auf einem Bestand ohne Mitglieder.');
        }

        $controller = $this->makeAuthController();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/setup')
            ->withParsedBody([
                'first_name' => ['Anna'],
                'last_name' => ['Beispiel'],
                'email' => ['anna@example.test'],
                'password' => ['Correct-Horse-1'],
            ]);

        $response = $controller->processSetup($request, new SlimResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/setup', $response->getHeaderLine('Location'));
        $this->assertSame(0, User::count());
    }

    public function testForgotPasswordRejectsArrayEmailInsteadOfCrashing(): void
    {
        $controller = $this->makePasswordResetController();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/forgot-password')
            ->withParsedBody(['email' => ['a@example.test']]);

        $response = $controller->sendResetLink($request, new SlimResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/forgot-password', $response->getHeaderLine('Location'));
        $this->assertSame('Bitte gib eine gültige E-Mail-Adresse ein.', $_SESSION['error'] ?? null);
    }

    public function testResetPasswordRejectsArrayFieldsInsteadOfCrashing(): void
    {
        $controller = $this->makePasswordResetController();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/reset-password')
            ->withParsedBody([
                'token' => ['abc'],
                'email' => ['a@example.test'],
                'password' => ['Correct-Horse-1'],
                'password_confirm' => ['Correct-Horse-1'],
            ]);

        $response = $controller->processReset($request, new SlimResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('/reset-password?', $response->getHeaderLine('Location'));
    }
}
