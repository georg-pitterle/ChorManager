<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ProfileFeatureTest extends TestCase
{
    public function testProfileStructureExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\ProfileController::class));
        $this->assertTrue(method_exists(\App\Controllers\ProfileController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\ProfileController::class, 'updateProfile'));
        $this->assertTrue(method_exists(\App\Controllers\ProfileController::class, 'updatePassword'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/profile'", $routesContent);
        $this->assertStringContainsString("'/profile/password'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/profile/index.twig'));
    }
}
