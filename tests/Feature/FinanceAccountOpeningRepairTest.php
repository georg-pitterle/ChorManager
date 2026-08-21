<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Services\FinanceAccountService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use RepairFinanceAccountOpeningData;
use Tests\Unit\Bootstrap;

require_once dirname(__DIR__, 2) . '/db/migrations/20260821120000_repair_finance_account_opening_data.php';

/**
 * Prüft die Reparatur aus Migration 20260821120000 an echten Daten. Der Test
 * führt dieselbe Anweisung aus, die auch die Migration ausführt - sie steht
 * dafür als Konstante in der Migrationsklasse.
 *
 * Hintergrund: Die Kontomigration 20260815140000 hat die Konten mit dem Stichtag
 * des Migrationstages angelegt, ihnen aber alle Bestandsbuchungen zugeordnet. Da
 * der Anfangsbestand laut Konvention alles vor dem Stichtag abdeckt, fielen die
 * älteren Buchungen aus jeder Bewegungsrechnung.
 */
final class FinanceAccountOpeningRepairTest extends TestCase
{
    private FinanceAccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        Finance::query()->whereNotNull('reversal_of_id')->update(['reversal_of_id' => null]);
        Finance::query()->delete();
        FinanceAccount::query()->delete();

        $this->service = new FinanceAccountService();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function account(string $name, string $openingBalance, string $openingDate): FinanceAccount
    {
        return FinanceAccount::create([
            'name' => $name,
            'type' => FinanceAccount::TYPE_CASH,
            'iban' => null,
            'opening_balance' => $openingBalance,
            'opening_date' => $openingDate,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function booking(
        FinanceAccount $account,
        int $number,
        string $paymentDate,
        string $type,
        string $amount
    ): void {
        Finance::create([
            'running_number' => $number,
            'invoice_date' => $paymentDate,
            'payment_date' => $paymentDate,
            'description' => 'Bewegung ' . $number,
            'group_name' => null,
            'finance_group_id' => null,
            'type' => $type,
            'amount' => $amount,
            'payment_method' => $account->paymentMethod(),
            'finance_account_id' => $account->id,
        ]);
    }

    private function runRepair(): void
    {
        Capsule::connection()->statement(RepairFinanceAccountOpeningData::REPAIR_SQL);
    }

    /**
     * Konten ohne Anfangsbestand sind das Migrationsartefakt: Die 0,00 € hat die
     * Kontomigration selbst eingetragen, sie behauptet nichts. Der Stichtag
     * wandert auf die früheste Buchung, der Anfangsbestand bleibt bei null.
     */
    public function testAnAccountWithoutOpeningBalanceOnlyMovesItsOpeningDate(): void
    {
        $account = $this->account('Barkassa', '0.00', '2026-08-15');
        $this->booking($account, 8001, '2025-05-04', 'income', '120.00');
        $this->booking($account, 8002, '2025-06-08', 'expense', '20.00');

        $this->runRepair();

        $repaired = $account->fresh();
        $this->assertSame('2025-05-04', FinanceAccountService::openingDate($repaired));
        $this->assertSame(0.0, (float) $repaired->opening_balance);
    }

    /**
     * Bei einem eingetragenen Anfangsbestand ist die Zahl eine Behauptung über
     * den Bestand zum Stichtag. Sie wird um die Summe der Buchungen gekürzt, die
     * jetzt zusätzlich in die Bewegungsrechnung wandern - sonst zählten diese
     * Beträge doppelt.
     */
    public function testAnAccountWithOpeningBalanceHasItReducedByTheEarlierBookings(): void
    {
        $account = $this->account('Bankkonto', '4200.00', '2026-08-15');
        $this->booking($account, 8003, '2025-05-04', 'income', '120.00');
        $this->booking($account, 8004, '2025-06-08', 'expense', '20.00');

        $this->runRepair();

        $repaired = $account->fresh();
        $this->assertSame('2025-05-04', FinanceAccountService::openingDate($repaired));
        $this->assertSame(4100.0, (float) $repaired->opening_balance);
    }

    /**
     * Der entscheidende Punkt: Der Endbestand ist eine geprüfte Zahl und darf
     * sich durch die Reparatur nicht verschieben.
     */
    public function testTheClosingBalanceSurvivesTheRepairUnchanged(): void
    {
        $account = $this->account('Bankkonto', '4200.00', '2026-08-15');
        $this->booking($account, 8005, '2025-05-04', 'income', '120.00');
        $this->booking($account, 8006, '2025-06-08', 'expense', '20.00');
        $this->booking($account, 8007, '2026-09-01', 'income', '300.00');

        $today = Carbon::parse('2026-12-31');
        $before = $this->service->balanceAt($account, $today);

        $this->runRepair();

        $this->assertSame($before, $this->service->balanceAt($account->fresh(), $today));
    }

    /**
     * Nach der Reparatur zählen die früheren Buchungen in ihrem eigenen
     * Geschäftsjahr mit - genau das war vorher verloren.
     */
    public function testEarlierBookingsAppearInTheirOwnFiscalYear(): void
    {
        $account = $this->account('Bankkonto', '4200.00', '2026-08-15');
        $this->booking($account, 8008, '2025-05-04', 'income', '120.00');
        $this->booking($account, 8009, '2025-06-08', 'expense', '20.00');

        $start = Carbon::parse('2025-01-01');
        $end = Carbon::parse('2025-12-31');

        $this->assertSame(0.0, $this->service->statement($start, $end)['totals']['income']);

        $this->runRepair();

        $statement = $this->service->statement($start, $end);
        $this->assertSame(120.0, $statement['totals']['income']);
        $this->assertSame(20.0, $statement['totals']['expense']);
        $this->assertSame(0, $statement['totals']['before_opening_count']);
    }

    /**
     * Konten, deren Buchungen alle ab dem Stichtag liegen, bleiben unangetastet.
     */
    public function testAnAccountWithoutEarlierBookingsIsLeftAlone(): void
    {
        $account = $this->account('Barkassa', '350.00', '2026-01-01');
        $this->booking($account, 8010, '2026-02-01', 'income', '50.00');

        $this->runRepair();

        $repaired = $account->fresh();
        $this->assertSame('2026-01-01', FinanceAccountService::openingDate($repaired));
        $this->assertSame(350.0, (float) $repaired->opening_balance);
    }

    /**
     * Offene Posten haben kein Zahldatum und gehören in keinen Bestand. Sie
     * dürfen den Stichtag deshalb nicht nach vorne ziehen.
     */
    public function testOpenItemsWithoutPaymentDateDoNotMoveTheOpeningDate(): void
    {
        $account = $this->account('Barkassa', '350.00', '2026-01-01');
        Finance::create([
            'running_number' => 8011,
            'invoice_date' => '2024-03-01',
            'payment_date' => null,
            'description' => 'Offener Posten',
            'group_name' => null,
            'finance_group_id' => null,
            'type' => 'expense',
            'amount' => '75.00',
            'payment_method' => $account->paymentMethod(),
            'finance_account_id' => $account->id,
        ]);

        $this->runRepair();

        $repaired = $account->fresh();
        $this->assertSame('2026-01-01', FinanceAccountService::openingDate($repaired));
        $this->assertSame(350.0, (float) $repaired->opening_balance);
    }
}
