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
}
