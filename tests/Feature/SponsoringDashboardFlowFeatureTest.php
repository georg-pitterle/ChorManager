<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsoringContactController;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

class SponsoringDashboardFlowFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testDashboardControllerBuildsExplicitFollowUpViewModelFields(): void
    {
        $controllerPath = dirname(__DIR__, 2) . '/src/Controllers/SponsoringDashboardController.php';
        $controllerContent = file_get_contents($controllerPath);

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("'follow_up_date_display'", $controllerContent);
        $this->assertStringContainsString("'follow_up_date_sort'", $controllerContent);
        $this->assertStringContainsString("'sponsor_name'", $controllerContent);
        $this->assertStringContainsString("'sponsor_url'", $controllerContent);
        $this->assertStringContainsString("'agreement_package_name'", $controllerContent);
        $this->assertStringContainsString("'agreement_amount_display'", $controllerContent);
        $this->assertStringContainsString("'agreement_amount_sort'", $controllerContent);
        $this->assertStringContainsString("'agreement_status_label'", $controllerContent);
        $this->assertStringContainsString("'owner_name'", $controllerContent);
        $this->assertStringContainsString("'owner_name_sort'", $controllerContent);
        $this->assertStringContainsString("'is_overdue'", $controllerContent);
        $this->assertStringContainsString("'mark_done_url'", $controllerContent);
    }

    public function testDashboardControllerSortsRecentContactsByContactDateThenCreatedAtDesc(): void
    {
        $controllerPath = dirname(__DIR__, 2) . '/src/Controllers/SponsoringDashboardController.php';
        $controllerContent = file_get_contents($controllerPath);

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("->orderBy('contact_date', 'desc')", $controllerContent);
        $this->assertStringContainsString("->orderBy('created_at', 'desc')", $controllerContent);
        $this->assertStringContainsString("'contact_date_sort'", $controllerContent);
        $this->assertStringContainsString("'contact_type_sort'", $controllerContent);
        $this->assertStringContainsString("'owner_name_sort'", $controllerContent);
    }

    public function testDashboardTemplateUsesMappedFieldsAndExplicitFollowUpEmptyState(): void
    {
        $templatePath = dirname(__DIR__, 2) . '/templates/sponsoring/dashboard.twig';
        $templateContent = file_get_contents($templatePath);

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('contact.follow_up_date_display', $templateContent);
        $this->assertStringContainsString('contact.agreement_package_name', $templateContent);
        $this->assertStringContainsString('contact.agreement_amount_display', $templateContent);
        $this->assertStringContainsString('contact.agreement_status_label', $templateContent);
        $this->assertStringContainsString('contact.mark_done_url', $templateContent);
        $this->assertStringContainsString('Keine Wiedervorlagen in den nächsten 7 Tagen vorhanden.', $templateContent);
    }

    public function testMarkDoneRedirectsToDashboardWhenDashboardOriginIsProvided(): void
    {
        $controller = new SponsoringContactController(Twig::create(dirname(__DIR__, 2) . '/templates'));
        $request = $this->makeRequest('POST', '/sponsoring/contacts/999999/done', ['redirect_to' => 'dashboard']);
        $response = $this->makeResponse();

        $result = $controller->markDone($request, $response, ['id' => '999999']);

        $this->assertRedirect($result, '/sponsoring');
    }

    public function testMarkDoneKeepsSponsorDetailRedirectWhenNotFromDashboard(): void
    {
        $controller = new SponsoringContactController(Twig::create(dirname(__DIR__, 2) . '/templates'));
        $request = $this->makeRequest('POST', '/sponsoring/contacts/999999/done', ['sponsor_id' => '42']);
        $response = $this->makeResponse();

        $result = $controller->markDone($request, $response, ['id' => '999999']);

        $this->assertRedirect($result, '/sponsoring/sponsors/42');
    }

    public function testMarkDoneDefaultsToDashboardWhenNoSponsorContextExists(): void
    {
        $controller = new SponsoringContactController(Twig::create(dirname(__DIR__, 2) . '/templates'));
        $request = $this->makeRequest('POST', '/sponsoring/contacts/999999/done');
        $response = $this->makeResponse();

        $result = $controller->markDone($request, $response, ['id' => '999999']);

        $this->assertRedirect($result, '/sponsoring');
    }
}
