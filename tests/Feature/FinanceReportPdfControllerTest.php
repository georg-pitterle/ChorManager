<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Finance;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

final class FinanceReportPdfControllerTest extends TestCase
{
    use TestHttpHelpers;
    use FinanceAccountFixture;
    use BuildsControllers;

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
        return $this->financeController(['view' => $this->createStub(Twig::class)]);
    }

    public function testReportPdfReturnsPdfDownload(): void
    {
        Finance::create([
            'running_number' => 8001, 'invoice_date' => '2025-10-05', 'payment_date' => '2025-10-05',
            'description' => 'Prüf-Einnahme',
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
