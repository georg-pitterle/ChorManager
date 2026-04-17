<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use PHPUnit\Framework\TestCase;

class InviteUserTest extends TestCase
{
    public function testInviteMethodExists(): void
    {
        $this->assertTrue(method_exists(UserController::class, 'invite'));
    }

    public function testCreateNoLongerRequiresPassword(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/UserController.php');
        $this->assertIsString($controller);
        $this->assertStringNotContainsString('!$password ||', $controller);
    }

    public function testInviteRouteRegistered(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routes);
        $this->assertStringContainsString('/invite', $routes);
        $this->assertStringContainsString("'invite'", $routes);
    }

    public function testInvitationEmailTemplateExists(): void
    {
        $this->assertTrue(
            file_exists(dirname(__DIR__) . '/../templates/emails/invitation.twig'),
            'templates/emails/invitation.twig must exist'
        );
        $content = file_get_contents(dirname(__DIR__) . '/../templates/emails/invitation.twig');
        $this->assertIsString($content);
        $this->assertStringContainsString('invite_link', $content);
        $this->assertStringContainsString('7', $content);
    }

    public function testInvitationTokenModelExists(): void
    {
        $this->assertTrue(class_exists(\App\Models\InvitationToken::class));
    }

    public function testPasswordStrengthPartialExists(): void
    {
        $this->assertTrue(
            file_exists(dirname(__DIR__) . '/../templates/partials/password_strength.twig'),
            'templates/partials/password_strength.twig must exist'
        );
        $content = file_get_contents(dirname(__DIR__) . '/../templates/partials/password_strength.twig');
        $this->assertIsString($content);
        $this->assertStringContainsString('passwordInput', $content);
        $this->assertStringContainsString('data-rule', $content);
    }

    public function testResetPasswordTemplateIncludesStrengthPartial(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../templates/auth/reset_password.twig');
        $this->assertIsString($content);
        $this->assertStringContainsString('password_strength.twig', $content);
    }

    public function testManageTwigHasInviteButton(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../templates/users/manage.twig');
        $this->assertIsString($content);
        $this->assertStringContainsString('js-invite-btn', $content);
        $this->assertStringContainsString('data-invite-url', $content);
    }

    public function testManageTwigRemovedPasswordField(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../templates/users/manage.twig');
        $this->assertIsString($content);
        $this->assertStringNotContainsString('Initiales Passwort', $content);
    }
}