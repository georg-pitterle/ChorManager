<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AppSettingController;
use PHPUnit\Framework\TestCase;

class AppSettingFeatureTest extends TestCase
{
    public function testSettingsStructureExists(): void
    {
        $this->assertTrue(class_exists(AppSettingController::class));
        $this->assertTrue(method_exists(AppSettingController::class, 'index'));
        $this->assertTrue(method_exists(AppSettingController::class, 'save'));
        $this->assertTrue(method_exists(AppSettingController::class, 'logo'));
        $this->assertTrue(method_exists(AppSettingController::class, 'themeCss'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/settings'", $routesContent);
        $this->assertStringContainsString("'/logo'", $routesContent);
        $this->assertStringContainsString("'/theme.css'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/settings/index.twig'));
    }

    public function testNormalizePrimaryColorAcceptsValidHexAndAddsHash(): void
    {
        $this->assertSame('#E8A817', AppSettingController::normalizePrimaryColor('E8A817'));
        $this->assertSame('#112233', AppSettingController::normalizePrimaryColor('#112233'));
        $this->assertSame('#AABBCC', AppSettingController::normalizePrimaryColor('abc'));
    }

    public function testNormalizePrimaryColorFallsBackForInvalidValues(): void
    {
        $this->assertSame('#E8A817', AppSettingController::normalizePrimaryColor(''));
        $this->assertSame('#E8A817', AppSettingController::normalizePrimaryColor('not-a-color'));
        $this->assertSame('#E8A817', AppSettingController::normalizePrimaryColor('#12345'));
    }
}