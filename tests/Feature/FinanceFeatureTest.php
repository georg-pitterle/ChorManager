<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use PHPUnit\Framework\TestCase;

class FinanceFeatureTest extends TestCase
{
    public function testFinanceStructureExists(): void
    {
        $this->assertTrue(class_exists(FinanceController::class));
        $this->assertTrue(method_exists(FinanceController::class, 'index'));
        $this->assertTrue(method_exists(FinanceController::class, 'save'));
        $this->assertTrue(method_exists(FinanceController::class, 'delete'));
        $this->assertTrue(method_exists(FinanceController::class, 'report'));
        $this->assertTrue(method_exists(FinanceController::class, 'updateSettings'));
        $this->assertTrue(method_exists(FinanceController::class, 'viewAttachment'));
        $this->assertTrue(method_exists(FinanceController::class, 'deleteAttachment'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/finances'", $routesContent);
        $this->assertStringContainsString("'/finances/report'", $routesContent);
        $this->assertStringContainsString("'/finances/settings'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/finances/index.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/finances/report.twig'));
    }

    public function testComputeDefaultStartYearBeforeAndAfterBoundary(): void
    {
        $afterBoundary = FinanceController::computeDefaultStartYear(2026, 9, 1, 1, 9);
        $beforeBoundary = FinanceController::computeDefaultStartYear(2026, 8, 31, 1, 9);

        $this->assertSame(2026, $afterBoundary);
        $this->assertSame(2025, $beforeBoundary);
    }

    public function testNormalizeAmountInputConvertsCommaToDot(): void
    {
        $this->assertSame('12.50', FinanceController::normalizeAmountInput('12,50'));
        $this->assertSame('12.50', FinanceController::normalizeAmountInput(' 12,50 '));
        $this->assertSame('1234.56', FinanceController::normalizeAmountInput('1.234,56'));
        $this->assertSame('1234.56', FinanceController::normalizeAmountInput('1,234.56'));
        $this->assertSame('1234567', FinanceController::normalizeAmountInput('1.234.567'));
    }

    public function testFinanceDeleteAlsoRemovesAttachments(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/FinanceController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("Attachment::where('entity_type', 'finance')", $controllerContent);
        $this->assertStringContainsString("->where('entity_id', " . '$' . "financeId)", $controllerContent);
        $this->assertStringContainsString("->delete();", $controllerContent);
    }

    public function testFinanceAttachmentViewOnlyUsesInlineDispositionForSafeMimeTypes(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/FinanceController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString('private static function isInlineViewableMimeType', $controllerContent);
        $this->assertStringContainsString("'application/pdf'", $controllerContent);
        $this->assertStringContainsString("'text/plain'", $controllerContent);
        $this->assertStringContainsString("'attachment'", $controllerContent);
        $this->assertStringContainsString("'inline'", $controllerContent);
        $this->assertStringContainsString("'file_size' => " . '$' . "size", $controllerContent);
    }

    public function testFinanceTemplateUsesSeparateReadAndWriteVisibilityFlags(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/finances/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString(
            '{% set can_write_finances = session.can_manage_finances or session.can_manage_users %}',
            $template
        );
        $this->assertStringContainsString('{% if can_write_finances %}', $template);
    }

    public function testFinanceReportTimelineUsesTableEngine(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/finances/report.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('data-table-engine="true"', $template);
        $this->assertStringContainsString('data-table-id="finances.report.timeline"', $template);
        $this->assertStringContainsString('partials/table_toolbar.twig', $template);
        $this->assertStringContainsString('table-responsive-cards', $template);
        $this->assertStringContainsString('data-sort-key="invoice_date"', $template);
    }

    public function testFinanceNavigationAndDashboardUseFinanceReadPermission(): void
    {
        $areas = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/areas.twig');
        $dashboard = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($areas);
        $this->assertIsString($dashboard);
        $this->assertStringContainsString('session.can_read_finances or session.can_manage_users', $areas);
        $this->assertStringContainsString('session.can_read_finances or session.can_manage_users', $dashboard);
    }
}
