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
        $this->assertStringContainsString('primary_color', $content);
        $this->assertStringContainsString('logo_src', $content);
        $this->assertStringContainsString('app_name', $content);
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
        $scriptContent = file_get_contents(dirname(__DIR__) . '/../public/js/password-strength.js');
        $this->assertIsString($content);
        $this->assertIsString($scriptContent);
        $this->assertStringContainsString('data-rule', $content);
        $this->assertStringContainsString("document.getElementById('passwordInput')", $scriptContent);
        $this->assertStringContainsString("#passwordStrengthList [data-rule=\"", $scriptContent);
    }

    public function testResetPasswordTemplateIncludesStrengthPartial(): void
    {
        $resetContent = file_get_contents(dirname(__DIR__) . '/../templates/auth/reset_password.twig');
        $setupContent = file_get_contents(dirname(__DIR__) . '/../templates/auth/setup.twig');
        $this->assertIsString($resetContent);
        $this->assertIsString($setupContent);
        $this->assertStringContainsString('password_strength.twig', $resetContent);
        $this->assertStringContainsString('/js/password-strength.js', $resetContent);
        $this->assertStringContainsString('password_strength.twig', $setupContent);
        $this->assertStringContainsString('/js/password-strength.js', $setupContent);
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

    public function testUsersJsTogglesSubVoiceVisibilityClass(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../public/js/users.js');
        $this->assertIsString($content);
        $this->assertStringContainsString("classList.remove('d-none')", $content);
        $this->assertStringContainsString("classList.add('d-none')", $content);
    }

    public function testManageTwigHasSaveAndInviteSubmitButton(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../templates/users/manage.twig');
        $this->assertIsString($content);
        $this->assertStringContainsString('name="submit_action"', $content);
        $this->assertStringContainsString('value="save_and_invite"', $content);
        $this->assertStringContainsString('Speichern und Einladungs-E-Mail senden', $content);
    }

    public function testInviteFetchSendsCsrfHeader(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../public/js/users.js');
        $this->assertIsString($content);
        $this->assertStringContainsString('X-CSRF-Token', $content);
        $this->assertStringContainsString("'Accept': 'application/json'", $content);
        $this->assertStringContainsString("meta[name=\"csrf-token\"]", $content);
        $this->assertStringContainsString("document.addEventListener('click', handleInviteClick)", $content);
        $this->assertStringContainsString('Bereits gesendet - erneut senden', $content);
        $this->assertStringContainsString("btn.dataset.invitePending === '1'", $content);
        $this->assertStringContainsString("btn.dataset.invitePending = '1'", $content);
        $this->assertStringContainsString("btn.dataset.invitePending = '0'", $content);
        $this->assertStringContainsString('btn.disabled = false;', $content);
    }

    public function testCreateFlowSupportsSaveAndInvite(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/UserController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString("submit_action", $controller);
        $this->assertStringContainsString("'save'", $controller);
        $this->assertStringContainsString("submitAction === 'save_and_invite'", $controller);
        $this->assertStringContainsString('sendInvitationEmail', $controller);
        $this->assertStringContainsString('AppUrlResolver::resolveBaseUrl($request)', $controller);
        $this->assertStringContainsString('resolveInvitationBranding', $controller);
        $this->assertStringContainsString('logo_src', $controller);
        $this->assertStringContainsString('base64_encode', $controller);
        $this->assertStringContainsString('data:image/png;base64,', $controller);
        $this->assertStringContainsString('primary_color', $controller);
        $this->assertStringNotContainsString('buildTrustedAppUrl', $controller);
    }
}
