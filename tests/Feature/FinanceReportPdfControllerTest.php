<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Finance;
use App\Services\BudgetService;
use App\Services\FinanceReportPdfService;
use App\Services\Pdf\TcLibPdfCanvas;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;

final class FinanceReportPdfControllerTest extends TestCase
{
    use TestHttpHelpers;

    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$capsule !== null) {
            return;
        }
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$capsule?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $c = self::$capsule?->connection();
        if ($c !== null && $c->transactionLevel() > 0) {
            $c->rollBack();
        }
        parent::tearDown();
    }

    private function controller(): FinanceController
    {
        return new FinanceController(
            $this->createStub(Twig::class),
            new BudgetService(),
            new NullLogger(),
            new FinanceReportPdfService(new TcLibPdfCanvas())
        );
    }

    public function testReportPdfReturnsPdfDownload(): void
    {
        Finance::create([
            'running_number' => 8001, 'invoice_date' => '2025-10-05', 'payment_date' => null,
            'description' => 'Prüf-Einnahme', 'group_name' => null, 'finance_group_id' => null,
            'type' => 'income', 'amount' => '250.00', 'payment_method' => 'cash',
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
