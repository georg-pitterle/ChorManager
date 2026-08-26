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
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Kassabericht und Storno.
 *
 * Das Kassabuch bleibt brutto - § 131 BAO verlangt, dass jede Buchung sichtbar
 * bleibt, und eine Gegenbuchung ist selbst eine Buchung. Die Budgetauswertung
 * rechnet stornierte Paare dagegen heraus. Damit die beiden Summen nicht ohne
 * erkennbaren Grund auseinanderfallen, weist der Bericht den Storno-Anteil aus.
 *
 * Außerdem: Ein Storno aus gesperrter Periode muss auf dem ersten wieder offenen
 * Tag landen, nicht pauschal auf "heute".
 */
final class FinanceReportReversalDisclosureFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use FinanceAccountFixture;

    /** Geschäftsjahr ohne Seed-Buchungen, damit der Bericht nur den Testbestand zeigt. */
    private const EMPTY_FISCAL_YEAR = 2035;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.01.']);
        Setting::where('setting_key', FinanceJournalService::CLOSED_UNTIL_KEY)->delete();

        $_SESSION = ['user_id' => 1, 'can_manage_finances' => true, 'can_read_finances' => true];
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

    private function createBooking(string $paymentDate, string $type, float $amount): Finance
    {
        return Finance::create([
            'running_number' => random_int(800000, 899999),
            'invoice_date' => $paymentDate,
            'payment_date' => $paymentDate,
            'description' => 'Berichtstest',
            'type' => $type,
            'amount' => $amount,
            'payment_method' => 'cash',
            'finance_account_id' => $this->fixtureAccountId(),
        ]);
    }

    public function testTheReportDisclosesTheReversedShare(): void
    {
        // Ein Jahr weit jenseits der Seed-Daten: der Bericht soll genau die hier
        // angelegten Buchungen enthalten.
        $year = self::EMPTY_FISCAL_YEAR;
        $day = $year . '-05-05';

        $this->createBooking($day, 'expense', 40.0);
        $reversed = $this->createBooking($day, 'expense', 100.0);
        $reversal = $this->createBooking($day, 'income', 100.0);
        $reversal->reversal_of_id = $reversed->id;
        $reversal->save();

        $report = $this->invokeBuildReportData($year);

        // Brutto bleibt brutto: beide Seiten des Storno-Paares zählen mit.
        $this->assertSame(140.0, (float) $report['total_expense']);
        $this->assertSame(100.0, (float) $report['total_income']);
        // Und der Anteil, der die Differenz zum Budget-Ist erklärt, wird benannt.
        $this->assertSame(100.0, (float) $report['reversed_expense']);
        $this->assertSame(100.0, (float) $report['reversed_income']);
    }

    public function testAReportWithoutReversalsShowsNoReversedShare(): void
    {
        $year = self::EMPTY_FISCAL_YEAR;
        $this->createBooking($year . '-05-05', 'expense', 40.0);

        $report = $this->invokeBuildReportData($year);

        $this->assertSame(0.0, (float) $report['reversed_expense']);
        $this->assertSame(0.0, (float) $report['reversed_income']);
    }

    public function testReversingALockedBookingLandsOnTheFirstOpenDay(): void
    {
        $journal = new FinanceJournalService();
        // Der Abschluss reicht in die Zukunft - heute ist damit selbst gesperrt.
        $closedUntil = Carbon::now()->addDays(5);
        $journal->setClosedUntil($closedUntil->format('Y-m-d'));

        $original = $this->createBooking(Carbon::now()->subMonth()->format('Y-m-d'), 'expense', 25.0);

        $this->controller()->reverse(
            $this->makeRequest('POST', '/finances/' . $original->id . '/reverse'),
            $this->makeResponse(),
            ['id' => (string) $original->id]
        );

        $reversal = Finance::where('reversal_of_id', $original->id)->first();

        $this->assertNotNull($reversal, 'Die Gegenbuchung muss angelegt werden.');
        $this->assertSame(
            $closedUntil->copy()->addDay()->format('Y-m-d'),
            Carbon::parse($reversal->payment_date)->format('Y-m-d'),
            'Die Gegenbuchung darf nicht im gesperrten Zeitraum landen.'
        );
        $this->assertFalse($journal->isFinanceLocked($reversal->fresh()));
    }

    /** @return array<string, mixed> */
    private function invokeBuildReportData(int $year): array
    {
        $method = new \ReflectionMethod(FinanceController::class, 'buildReportData');

        return $method->invoke($this->controller(), $year);
    }
}
