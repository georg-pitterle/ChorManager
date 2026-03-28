<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SponsoringFeatureTest extends TestCase
{
    public function testSponsoringControllersAndMethodsExist(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\SponsoringDashboardController::class));
        $this->assertTrue(class_exists(\App\Controllers\SponsorController::class));
        $this->assertTrue(class_exists(\App\Controllers\SponsorshipController::class));
        $this->assertTrue(class_exists(\App\Controllers\SponsoringContactController::class));
        $this->assertTrue(class_exists(\App\Controllers\SponsorPackageController::class));

        $this->assertTrue(method_exists(\App\Controllers\SponsoringDashboardController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorController::class, 'detail'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorController::class, 'delete'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorshipController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorshipController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorshipController::class, 'delete'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorshipController::class, 'downloadAttachment'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorshipController::class, 'deleteAttachment'));
        $this->assertTrue(method_exists(\App\Controllers\SponsoringContactController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\SponsoringContactController::class, 'markDone'));
        $this->assertTrue(method_exists(\App\Controllers\SponsoringContactController::class, 'delete'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorPackageController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorPackageController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorPackageController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\SponsorPackageController::class, 'delete'));
    }

    public function testSponsoringRoutesAndTemplatesExist(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/sponsoring'", $routesContent);
        $this->assertStringContainsString("'/sponsors'", $routesContent);
        $this->assertStringContainsString("'/sponsorships'", $routesContent);
        $this->assertStringContainsString("'/contacts'", $routesContent);
        $this->assertStringContainsString("'/packages'", $routesContent);

        $this->assertTrue(is_dir(dirname(__DIR__) . '/../templates/sponsoring'));
        $this->assertTrue(is_dir(dirname(__DIR__) . '/../templates/sponsoring/sponsors'));
    }

    public function testSponsorsIndexTemplateUsesResponsiveTableEngine(): void
    {
        $templatePath = dirname(__DIR__) . '/../templates/sponsoring/sponsors/index.twig';
        $templateContent = file_get_contents($templatePath);

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('data-table-engine="true"', $templateContent);
        $this->assertStringContainsString("'partials/table_toolbar.twig'", $templateContent);
        $this->assertStringContainsString('table-responsive-cards', $templateContent);
        $this->assertStringContainsString('data-label="Name"', $templateContent);
        $this->assertStringContainsString('data-label="Aktionen"', $templateContent);
    }
}
