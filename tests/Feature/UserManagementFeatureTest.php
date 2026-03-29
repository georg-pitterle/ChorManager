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
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'restore'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/users'", $routesContent);
        $this->assertStringContainsString("'/deactivate/{id:[0-9]+}'", $routesContent);
        $this->assertStringContainsString("'/bulk-deactivate'", $routesContent);
        $this->assertStringContainsString("'/restore/{id:[0-9]+}'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/users/manage.twig'));
    }

    public function testArchivedUsersQueryMethodExists(): void
    {
        $this->assertTrue(method_exists(\App\Queries\UserQuery::class, 'getArchivedUsers'));
    }

    public function testManageTemplateContainsArchivedToggle(): void
    {
        $twig = file_get_contents(dirname(__DIR__) . '/../templates/users/manage.twig');
        $this->assertIsString($twig);
        $this->assertStringContainsString('show_archived', $twig);
        $this->assertStringContainsString('/users?archived=1', $twig);
        $this->assertStringContainsString('/users/restore/', $twig);
    }
}
