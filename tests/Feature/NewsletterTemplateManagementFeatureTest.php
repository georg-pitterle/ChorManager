<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class NewsletterTemplateManagementFeatureTest extends TestCase
{
    public function testTemplateManagementRoutesAreRegistered(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routes);
        $this->assertStringContainsString('/newsletters/templates', $routes);
        $this->assertStringContainsString('/newsletters/templates/{id:[0-9]+}/edit', $routes);
        $this->assertStringContainsString('/newsletters/templates/{id:[0-9]+}', $routes);
        $this->assertStringContainsString('/newsletters/templates/{id:[0-9]+}/clone', $routes);
    }

    public function testTemplateManagementTemplatesExist(): void
    {
        $base = dirname(__DIR__) . '/../templates/newsletters';

        $this->assertFileExists($base . '/templates_index.twig');
        $this->assertFileExists($base . '/templates_edit.twig');
    }

    public function testNewsletterControllerExposesTemplateManagementActions(): void
    {
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'listTemplates'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'editTemplate'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'updateTemplate'));
        $this->assertTrue(method_exists(\App\Controllers\NewsletterController::class, 'cloneTemplate'));
    }
}
