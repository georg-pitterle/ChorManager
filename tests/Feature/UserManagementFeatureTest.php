<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class UserManagementFeatureTest extends TestCase
{
    public function testUserManagementStructureExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\UserController::class));
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'deactivate'));
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'bulkDeactivate'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/users'", $routesContent);
        $this->assertStringContainsString("'/deactivate/{id:[0-9]+}'", $routesContent);
        $this->assertStringContainsString("'/bulk-deactivate'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/users/manage.twig'));
    }
}
