<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Models\FinanceRevision;
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

final class FinanceCsvExportTest extends TestCase
{
    use TestHttpHelpers;

    private FinanceController $controller;
    private FinanceAccount $account;
    private ResponseInterface $lastResponse;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        FinanceRevision::query()->delete();
        Finance::query()->whereNotNull('reversal_of_id')->update(['reversal_of_id' => null]);
        Finance::query()->delete();
        FinanceAccount::query()->delete();
        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.09.']);

        $this->account = FinanceAccount::create([
            'name' => 'Bankkonto',
            'type' => FinanceAccount::TYPE_BANK,
            'iban' => null,
            'opening_balance' => '0.00',
            'opening_date' => '2025-09-01',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->controller = new FinanceController(
            $this->createStub(Twig::class),
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

    private function booking(int $number, string $paymentDate, string $type, string $amount): Finance
    {
        return Finance::create([
            'running_number' => $number,
            'invoice_date' => $paymentDate,
            'payment_date' => $paymentDate,
            'description' => 'Buchung ' . $number,
            'group_name' => 'Konzert',
            'finance_group_id' => null,
            'type' => $type,
            'amount' => $amount,
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $this->account->id,
        ]);
    }

    private function export(int $year = 2025): string
    {
        $request = $this->makeRequest('GET', '/finances/export', [], ['year' => (string) $year]);
        $response = $this->controller->exportCsv($request, $this->makeResponse());
        $this->lastResponse = $response;

        return (string) $response->getBody();
    }

    public function testExportIsOfferedAsADownloadWithBomAndSemicolons(): void
    {
        $this->booking(6001, '2025-10-05', 'income', '250.00');

        $csv = $this->export();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Excel braucht das BOM für Umlaute.');
        $this->assertStringContainsString('text/csv', $this->lastResponse->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment;', $this->lastResponse->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('Kassabuch_2025-09-01_2026-08-31.csv', $this->lastResponse->getHeaderLine('Content-Disposition'));

        // fputcsv setzt Felder mit Leerzeichen in Anfuehrungszeichen - gueltiges CSV.
        $header = explode("\n", $csv)[0];
        $this->assertStringContainsString('"Lfd. Nr.";Rechnungsdatum;Zahldatum;Beschreibung', $header);
        $this->assertStringContainsString('Konto;Zahlungsart;"Storno zu";Anhänge', $header);
    }

    public function testRowsCarryGermanDatesAndSignedAmountsWithDecimalComma(): void
    {
        $this->booking(6002, '2025-10-05', 'income', '250.00');
        $this->booking(6003, '2025-11-06', 'expense', '1234.50');

        $csv = $this->export();

        $this->assertStringContainsString('05.10.2025', $csv);
        $this->assertStringContainsString('250,00', $csv);
        // Ausgänge bekommen ein Minus, damit die Spalte direkt summierbar ist.
        $this->assertStringContainsString('-1234,50', $csv);
        $this->assertStringContainsString('Eingang', $csv);
        $this->assertStringContainsString('Ausgang', $csv);
        $this->assertStringContainsString('Bankkonto', $csv);
        $this->assertStringContainsString('Überweisung', $csv);
    }

    public function testExportCoversTheSelectedFiscalYearOnly(): void
    {
        $this->booking(6004, '2025-10-05', 'income', '10.00');
        $this->booking(6005, '2026-10-05', 'income', '20.00');

        $csv = $this->export(2025);

        $this->assertStringContainsString('Buchung 6004', $csv);
        $this->assertStringNotContainsString('Buchung 6005', $csv);
    }

    public function testOpenItemsAreNotPartOfTheExport(): void
    {
        Finance::create([
            'running_number' => 6006,
            'invoice_date' => '2025-10-05',
            'payment_date' => null,
            'description' => 'Offener Posten',
            'group_name' => null,
            'finance_group_id' => null,
            'type' => 'expense',
            'amount' => '99.00',
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $this->account->id,
        ]);

        $this->assertStringNotContainsString('Offener Posten', $this->export());
    }

    public function testReversalReferencesTheOriginalRunningNumber(): void
    {
        $original = $this->booking(6007, '2025-10-05', 'expense', '50.00');
        Finance::create([
            'running_number' => 6008,
            'invoice_date' => '2025-10-05',
            'payment_date' => '2025-10-05',
            'description' => 'Storno zu Nr. 6007: Buchung 6007',
            'group_name' => null,
            'finance_group_id' => null,
            'type' => 'income',
            'amount' => '50.00',
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $this->account->id,
            'reversal_of_id' => $original->id,
        ]);

        $lines = array_values(array_filter(explode("\n", $this->export())));
        $reversalLine = '';
        foreach ($lines as $line) {
            if (str_contains($line, '6008')) {
                $reversalLine = $line;
            }
        }

        $this->assertStringContainsString('Storno zu Nr. 6007', $reversalLine);
        $this->assertStringContainsString(';6007;', $reversalLine);
    }

    public function testSeparatorsInsideTextAreQuoted(): void
    {
        Finance::create([
            'running_number' => 6009,
            'invoice_date' => '2025-10-05',
            'payment_date' => '2025-10-05',
            'description' => 'Miete; Strom; Wasser',
            'group_name' => null,
            'finance_group_id' => null,
            'type' => 'expense',
            'amount' => '10.00',
            'payment_method' => 'cash',
            'finance_account_id' => $this->account->id,
        ]);

        $this->assertStringContainsString('"Miete; Strom; Wasser"', $this->export());
    }

    public function testExportRouteIsReadableWithoutWritePermission(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');
        $this->assertStringContainsString("'/finances/export'", $routes);

        // Die Export-Route muss in der Lesegruppe stehen, damit Rechnungspruefer
        // ohne Schreibrecht an die Rohdaten kommen.
        $readGroup = substr($routes, (int) strpos($routes, 'financeReadGroup'));
        $readGroup = substr($readGroup, 0, (int) strpos($readGroup, 'requiresFinanceRead'));
        $this->assertStringContainsString("'/finances/export'", $readGroup);
    }
}
