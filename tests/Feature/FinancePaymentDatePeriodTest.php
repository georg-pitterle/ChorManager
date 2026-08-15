<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Finance;
use App\Models\Setting;
use App\Services\BankStatementImportService;
use App\Services\BudgetService;
use App\Services\FinanceAccountService;
use App\Services\FinanceCsvExportService;
use App\Services\FinanceJournalService;
use App\Services\FinanceReportPdfService;
use App\Services\Pdf\TcLibPdfCanvas;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Das Kassabuch grenzt nach dem Zufluss-Abfluss-Prinzip ab: maßgeblich ist das
 * Zahldatum, nicht das Rechnungsdatum. Buchungen ohne Zahldatum sind offene
 * Posten und gehören in kein Geschäftsjahr.
 */
final class FinancePaymentDatePeriodTest extends TestCase
{
    use TestHttpHelpers;

    private FinanceController $controller;
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        // Selbstreferenz der Stornobuchungen zuerst loesen, sonst blockiert der
        // Fremdschluessel reversal_of_id das Leeren der Tabelle.
        Finance::query()->whereNotNull('reversal_of_id')->update(['reversal_of_id' => null]);
        Finance::query()->delete();
        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.09.']);

        $this->renderCalls = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data = []): ResponseInterface {
                $this->renderCalls[] = [$template, $data];
                return $response;
            }
        );

        $this->controller = new FinanceController(
            $twig,
            new BudgetService(),
            new NullLogger(),
            new FinanceReportPdfService(new TcLibPdfCanvas()),
            new BankStatementImportService(new NullLogger()),
            new FinanceAccountService(),
            new FinanceJournalService(),
            new FinanceCsvExportService()
        );

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    private function booking(int $number, string $invoiceDate, ?string $paymentDate, string $amount): Finance
    {
        return Finance::create([
            'running_number' => $number,
            'invoice_date' => $invoiceDate,
            'payment_date' => $paymentDate,
            'description' => 'Buchung ' . $number,
            'group_name' => null,
            'finance_group_id' => null,
            'type' => 'income',
            'amount' => $amount,
            'payment_method' => 'bank_transfer',
        ]);
    }

    /** @return array<string, mixed> */
    private function render(string $action, int $year): array
    {
        $request = $this->makeRequest('GET', '/finances', [], ['year' => (string) $year]);
        $this->controller->{$action}($request, $this->makeResponse());

        return end($this->renderCalls)[1];
    }

    public function testBookingPaidInTheNextFiscalYearCountsThere(): void
    {
        // Geschäftsjahr beginnt am 01.09. Rechnung noch im alten Jahr (August),
        // bezahlt erst im neuen (September): das Geld fließt im neuen Jahr.
        $this->booking(9001, '2026-08-20', '2026-09-10', '120.00');

        $oldYear = $this->render('index', 2025);
        $newYear = $this->render('index', 2026);

        $this->assertCount(0, $oldYear['finances']);
        $this->assertCount(1, $newYear['finances']);
    }

    public function testBookingsWithoutPaymentDateAreListedAsOpenItems(): void
    {
        $this->booking(9002, '2026-01-15', null, '80.00');

        $data = $this->render('index', 2025);

        $this->assertCount(0, $data['finances']);
        $this->assertCount(1, $data['open_items']);
        $this->assertSame(9002, $data['open_items'][0]->running_number);
    }

    public function testOpenItemsAreIndependentOfTheSelectedYear(): void
    {
        $this->booking(9003, '2021-03-01', null, '50.00');

        $this->assertCount(1, $this->render('index', 2025)['open_items']);
        $this->assertCount(1, $this->render('index', 2026)['open_items']);
    }

    public function testReportAggregatesByPaymentDateAndExcludesOpenItems(): void
    {
        $this->booking(9004, '2026-08-20', '2026-09-10', '120.00');
        $this->booking(9005, '2026-01-05', '2026-01-20', '40.00');
        $this->booking(9006, '2026-01-05', null, '999.00');

        $oldYear = $this->render('report', 2025);
        $newYear = $this->render('report', 2026);

        // Der offene Posten über 999 taucht in keinem der beiden Jahre auf.
        $this->assertSame(40.0, $oldYear['total_income']);
        $this->assertCount(1, $oldYear['finances']);
        $this->assertSame(120.0, $newYear['total_income']);
        $this->assertCount(1, $newYear['finances']);
    }

    public function testAvailableYearsAreDerivedFromPaymentDates(): void
    {
        $this->booking(9007, '2019-12-20', '2020-01-10', '120.00');

        $data = $this->render('index', 2025);

        $this->assertArrayHasKey(2019, $data['available_years']);
    }

    public function testBudgetActualsUseThePaymentDate(): void
    {
        $service = new BudgetService();
        $group = \App\Models\FinanceGroup::firstOrCreate(['name' => 'Zufluss-Testgruppe']);

        Finance::create([
            'running_number' => 9008,
            'invoice_date' => '2026-08-20',
            'payment_date' => '2026-09-10',
            'description' => 'Budgetprüfung',
            'group_name' => $group->name,
            'finance_group_id' => $group->id,
            'type' => 'income',
            'amount' => '200.00',
            'payment_method' => 'bank_transfer',
        ]);

        [$day, $month] = $service->getFiscalConfig();
        [$start2025, $end2025] = $service->datesForYear(2025, $day, $month);
        [$start2026, $end2026] = $service->datesForYear(2026, $day, $month);

        $this->assertSame('0.00', $service->computeActual($group->id, 'income', $start2025, $end2025));
        $this->assertSame('200.00', $service->computeActual($group->id, 'income', $start2026, $end2026));
        $this->assertStringContainsString(
            "whereBetween('payment_date'",
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/BudgetService.php')
        );
    }
}
