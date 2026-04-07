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
        $this->assertTrue(method_exists(\App\Controllers\SponsoringContactController::class, 'update'));
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
        $this->assertStringContainsString("'/contacts/{id:[0-9]+}'", $routesContent);
        $this->assertStringContainsString("SponsoringContactController::class, 'update'", $routesContent);
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
        $this->assertStringContainsString('partials/table_toolbar.twig', $templateContent);
        $this->assertStringContainsString('table-responsive-cards', $templateContent);
        $this->assertStringContainsString('data-label="Name"', $templateContent);
        $this->assertStringContainsString('data-label="Aktionen"', $templateContent);
    }

    public function testSponsoringDashboardTemplateUsesResponsiveTableEngine(): void
    {
        $templatePath = dirname(__DIR__) . '/../templates/sponsoring/dashboard.twig';
        $templateContent = file_get_contents($templatePath);

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('data-table-id="sponsoring.dashboard.followups"', $templateContent);
        $this->assertStringContainsString('data-table-id="sponsoring.dashboard.recent_contacts"', $templateContent);
        $this->assertStringContainsString('data-default-view="auto"', $templateContent);
        $this->assertStringContainsString('partials/table_toolbar.twig', $templateContent);
        $this->assertStringContainsString('table-responsive-cards', $templateContent);
        $this->assertStringContainsString('data-sort-key="follow_up_date"', $templateContent);
        $this->assertStringContainsString('data-sort-key="agreement_amount"', $templateContent);
        $this->assertStringContainsString('data-sort-key="contact_type"', $templateContent);
        $this->assertStringContainsString('data-sort-key="owner_name"', $templateContent);
        $this->assertStringContainsString('data-sort-follow_up_date="{{ contact.follow_up_date_sort }}"', $templateContent);
        $this->assertStringContainsString('data-sort-agreement_amount="{{ contact.agreement_amount_sort }}"', $templateContent);
        $this->assertStringContainsString('data-sort-contact_date="{{ contact.contact_date_sort }}"', $templateContent);
        $this->assertStringContainsString('data-sort-contact_type="{{ contact.contact_type_sort }}"', $templateContent);
        $this->assertStringContainsString('data-label="Datum"', $templateContent);
        $this->assertStringContainsString('data-label="Zusammenfassung"', $templateContent);
        $this->assertStringContainsString('Keine Wiedervorlagen in den nächsten 7 Tagen vorhanden.', $templateContent);
        $this->assertStringContainsString('class="table-summary-cell"', $templateContent);
        $this->assertStringContainsString('class="table-summary-content"', $templateContent);
    }

    public function testSponsorDetailTemplateProvidesContactEditControls(): void
    {
        $templatePath = dirname(__DIR__) . '/../templates/sponsoring/sponsors/detail.twig';
        $templateContent = file_get_contents($templatePath);

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('data-bs-target="#editContactModal{{ contact.id }}"', $templateContent);
        $this->assertStringContainsString('id="editContactModal{{ contact.id }}"', $templateContent);
        $this->assertStringContainsString('action="/sponsoring/contacts/{{ contact.id }}"', $templateContent);
    }
}
