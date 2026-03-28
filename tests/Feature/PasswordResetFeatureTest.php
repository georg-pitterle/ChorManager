<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PasswordResetController;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

class PasswordResetFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        $_SESSION = [];
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
        $twig = $this->createMock(Twig::class);
        $controller = new PasswordResetController($twig);

        $request = $this->makeRequest('POST', '/forgot-password', ['email' => 'invalid-email']);
        $response = $this->makeResponse();

        $result = $controller->sendResetLink($request, $response);

        $this->assertRedirect($result, '/forgot-password');
        $this->assertSame('Bitte gib eine gueltige E-Mail-Adresse ein.', str_replace('ü', 'ue', (string) $_SESSION['error']));
    }

    public function testShowResetFormRejectsMissingTokenOrEmail(): void
    {
        $twig = $this->createMock(Twig::class);
        $controller = new PasswordResetController($twig);

        $request = $this->makeRequest('GET', '/reset-password');
        $response = $this->makeResponse();

        $result = $controller->showResetForm($request, $response);

        $this->assertRedirect($result, '/login');
        $this->assertSame('Ungültiger oder fehlender Token.', $_SESSION['error']);
    }

    public function testProcessResetRejectsMissingRequiredFields(): void
    {
        $twig = $this->createMock(Twig::class);
        $controller = new PasswordResetController($twig);

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
        $twig = $this->createMock(Twig::class);
        $controller = new PasswordResetController($twig);

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
        $twig = $this->createMock(Twig::class);
        $controller = new PasswordResetController($twig);

        $request = $this->makeRequest('POST', '/reset-password', [
            'token' => 'abc',
            'email' => 'a@b.com',
            'password' => '12345',
            'password_confirm' => '12345',
        ]);
        $response = $this->makeResponse();

        $result = $controller->processReset($request, $response);

        $this->assertRedirect($result, '/reset-password?token=abc&email=a%40b.com');
        $this->assertSame('Das Passwort muss mindestens 6 Zeichen lang sein.', $_SESSION['error']);
    }
}
