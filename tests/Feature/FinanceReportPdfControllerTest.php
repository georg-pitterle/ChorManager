<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Finance;
use App\Services\BankStatementImportService;
use App\Services\BudgetService;
use App\Services\FinanceAccountService;
use App\Services\FinanceCsvExportService;
use App\Services\FinanceJournalService;
use App\Services\FinanceReportPdfService;
use App\Services\Pdf\TcLibPdfCanvas;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

final class FinanceReportPdfControllerTest extends TestCase
{
    use TestHttpHelpers;
    use FinanceAccountFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    private function controller(): FinanceController
    {
        return new FinanceController(
            $this->createStub(Twig::class),
            new BudgetService(),
            new NullLogger(),
            new FinanceReportPdfService(new TcLibPdfCanvas()),
            new BankStatementImportService(new NullLogger()),
            new FinanceAccountService(),
            new FinanceJournalService(),
            new FinanceCsvExportService()
        );
    }

    public function testReportPdfReturnsPdfDownload(): void
    {
        Finance::create([
            'running_number' => 8001, 'invoice_date' => '2025-10-05', 'payment_date' => '2025-10-05',
            'description' => 'Prüf-Einnahme', 'group_name' => null, 'finance_group_id' => null,
            'type' => 'income', 'amount' => '250.00', 'payment_method' => 'cash',
            'finance_account_id' => $this->fixtureAccountId(),
        ]);

        $response = $this->controller()->reportPdf(
            $this->makeRequest('GET', '/finances/report/pdf', [], ['year' => '2025']),
            $this->makeResponse()
        );

        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $disposition = $response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('Kassabuch', $disposition);

        $body = (string) $response->getBody();
        $this->assertStringStartsWith('%PDF', $body);
    }
}
