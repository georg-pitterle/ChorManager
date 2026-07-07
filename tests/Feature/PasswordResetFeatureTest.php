<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PasswordResetController;
use App\Services\MailQueueService;
use App\Services\PasswordPolicyService;
use App\Services\RateLimiterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

class PasswordResetFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private string $rateLimitStoreDir;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->rateLimitStoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm_password_reset_test_' . uniqid('', true);
        @mkdir($this->rateLimitStoreDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->rateLimitStoreDir . DIRECTORY_SEPARATOR . '*') as $file) {
            @unlink($file);
        }
        @rmdir($this->rateLimitStoreDir);
    }

    private function makeController(Twig $twig): PasswordResetController
    {
        return new PasswordResetController(
            $twig,
            null,
            new RateLimiterService($this->rateLimitStoreDir),
            new PasswordPolicyService()
        );
    }

    public function testPasswordResetStructureExists(): void
    {
        $this->assertTrue(class_exists(PasswordResetController::class));
        $this->assertTrue(method_exists(PasswordResetController::class, 'showForgotForm'));
        $this->assertTrue(method_exists(PasswordResetController::class, 'sendResetLink'));
        $this->assertTrue(method_exists(PasswordResetController::class, 'showResetForm'));
        $this->assertTrue(method_exists(PasswordResetController::class, 'processReset'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString('/forgot-password', $routesContent);
        $this->assertStringContainsString('/reset-password', $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/auth/forgot_password.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/auth/reset_password.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/emails/password_reset.twig'));
    }

    public function testSendResetLinkRejectsInvalidEmail(): void
    {
        $twig = $this->createStub(Twig::class);
        $controller = $this->makeController($twig);

        $request = $this->makeRequest('POST', '/forgot-password', ['email' => 'invalid-email']);
        $response = $this->makeResponse();

        $result = $controller->sendResetLink($request, $response);

        $this->assertRedirect($result, '/forgot-password');
        $this->assertSame('Bitte gib eine gueltige E-Mail-Adresse ein.', str_replace('ü', 'ue', (string) $_SESSION['error']));
    }

    public function testShowResetFormRejectsMissingTokenOrEmail(): void
    {
        $twig = $this->createStub(Twig::class);
        $controller = $this->makeController($twig);

        $request = $this->makeRequest('GET', '/reset-password');
        $response = $this->makeResponse();

        $result = $controller->showResetForm($request, $response);

        $this->assertRedirect($result, '/login');
        $this->assertSame('Ungültiger oder fehlender Token.', $_SESSION['error']);
    }

    public function testProcessResetRejectsMissingRequiredFields(): void
    {
        $twig = $this->createStub(Twig::class);
        $controller = $this->makeController($twig);

        $request = $this->makeRequest('POST', '/reset-password', [
            'token' => '',
            'email' => '',
            'password' => '',
            'password_confirm' => '',
        ]);
        $response = $this->makeResponse();

        $result = $controller->processReset($request, $response);

        $this->assertRedirect($result, '/reset-password?token=&email=');
        $this->assertSame('Bitte fülle alle Pflichtfelder aus.', $_SESSION['error']);
    }

    public function testProcessResetRejectsPasswordMismatch(): void
    {
        $twig = $this->createStub(Twig::class);
        $controller = $this->makeController($twig);

        $request = $this->makeRequest('POST', '/reset-password', [
            'token' => 'abc',
            'email' => 'a@b.com',
            'password' => 'secret1',
            'password_confirm' => 'secret2',
        ]);
        $response = $this->makeResponse();

        $result = $controller->processReset($request, $response);

        $this->assertRedirect($result, '/reset-password?token=abc&email=a%40b.com');
        $this->assertSame('Die Passwörter stimmen nicht überein.', $_SESSION['error']);
    }

    public function testProcessResetRejectsTooShortPassword(): void
    {
        $twig = $this->createStub(Twig::class);
        $controller = $this->makeController($twig);

        $request = $this->makeRequest('POST', '/reset-password', [
            'token' => 'abc',
            'email' => 'a@b.com',
            'password' => '12345',
            'password_confirm' => '12345',
        ]);
        $response = $this->makeResponse();

        $result = $controller->processReset($request, $response);

        $this->assertRedirect($result, '/reset-password?token=abc&email=a%40b.com');
        $this->assertSame('Das Passwort muss mindestens 12 Zeichen lang sein.', $_SESSION['error']);
    }

    public function testSendResetLinkUsesRateLimiter(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });

        $twig = $this->createStub(Twig::class);
        $rateLimiter = $this->createMock(RateLimiterService::class);
        $rateLimiter->expects($this->once())
            ->method('hit')
            ->with('password_reset:test@example.com', 3, 900)
            ->willReturn(['allowed' => true, 'retry_after' => 0, 'remaining' => 2]);

        $mailQueueService = $this->createMock(MailQueueService::class);
        $mailQueueService->expects($this->never())->method('enqueuePasswordResetMail');

        $controller = new PasswordResetController(
            $twig,
            null,
            $rateLimiter,
            null,
            $mailQueueService
        );

        $request = $this->makeRequest('POST', '/forgot-password', ['email' => 'test@example.com']);
        $response = $this->makeResponse();

        $result = $controller->sendResetLink($request, $response);

        $this->assertRedirect($result, '/forgot-password');
        $this->assertSame('Existiert die E-Mail-Adresse, wurde ein Link zum Zurücksetzen des Passworts gesendet.', $_SESSION['success']);
    }

    public function testProcessResetRejectsWeakPassword(): void
    {
        $twig = $this->createStub(Twig::class);
        $controller = $this->makeController($twig);

        $request = $this->makeRequest('POST', '/reset-password', [
            'token' => 'abc',
            'email' => 'a@b.com',
            'password' => 'tooshort',
            'password_confirm' => 'tooshort',
        ]);
        $response = $this->makeResponse();

        $result = $controller->processReset($request, $response);

        $this->assertRedirect($result, '/reset-password?token=abc&email=a%40b.com');
        $this->assertStringContainsString('12', $_SESSION['error']);
    }

    public function testPasswordResetControllerHasInvitationTokensSupport(): void
    {
        $controllerSource = file_get_contents(dirname(__DIR__) . '/../src/Controllers/PasswordResetController.php');
        $this->assertIsString($controllerSource);
        $this->assertStringContainsString('InvitationToken', $controllerSource);
        $this->assertStringContainsString('token_hash', $controllerSource);
    }
}
