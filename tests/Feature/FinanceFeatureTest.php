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
        $this->assertSame('12.50.75', FinanceController::normalizeAmountInput('12,50,75'));
    }
}
