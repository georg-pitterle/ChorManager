<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Services\FinanceAccountService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

final class FinanceAccountBalanceTest extends TestCase
{
    private FinanceAccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        // Selbstreferenz der Stornobuchungen zuerst loesen, sonst blockiert der
        // Fremdschluessel reversal_of_id das Leeren der Tabelle.
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

    private function account(string $name, string $type, string $opening, string $openingDate): FinanceAccount
    {
        return FinanceAccount::create([
            'name' => $name,
            'type' => $type,
            'iban' => $type === FinanceAccount::TYPE_BANK ? 'AT91 1600 0001 0062 9615' : null,
            'opening_balance' => $opening,
            'opening_date' => $openingDate,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function booking(
        FinanceAccount $account,
        int $number,
        ?string $paymentDate,
        string $type,
        string $amount
    ): Finance {
        return Finance::create([
            'running_number' => $number,
            'invoice_date' => $paymentDate ?? '2026-01-01',
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

    public function testBalanceStartsAtTheOpeningBalance(): void
    {
        $cash = $this->account('Barkassa', FinanceAccount::TYPE_CASH, '250.00', '2026-01-01');

        $this->assertSame(250.0, $this->service->balanceAt($cash, Carbon::parse('2026-01-01')));
    }

    public function testMovementsOnTheOpeningDayAreCounted(): void
    {
        $cash = $this->account('Barkassa', FinanceAccount::TYPE_CASH, '250.00', '2026-01-01');
        $this->booking($cash, 7001, '2026-01-01', 'income', '50.00');

        $this->assertSame(300.0, $this->service->balanceAt($cash, Carbon::parse('2026-01-01')));
    }

    public function testBalanceIsOpeningPlusIncomeMinusExpense(): void
    {
        $cash = $this->account('Barkassa', FinanceAccount::TYPE_CASH, '250.00', '2026-01-01');
        $this->booking($cash, 7002, '2026-02-10', 'income', '120.50');
        $this->booking($cash, 7003, '2026-02-20', 'expense', '30.50');

        $this->assertSame(340.0, $this->service->balanceAt($cash, Carbon::parse('2026-12-31')));
    }

    public function testBookingsBeforeTheOpeningDateAreIgnored(): void
    {
        $cash = $this->account('Barkassa', FinanceAccount::TYPE_CASH, '250.00', '2026-01-01');
        $this->booking($cash, 7004, '2025-06-01', 'income', '900.00');

        $this->assertSame(250.0, $this->service->balanceAt($cash, Carbon::parse('2026-12-31')));
    }

    public function testOpenItemsDoNotChangeTheBalance(): void
    {
        $cash = $this->account('Barkassa', FinanceAccount::TYPE_CASH, '250.00', '2026-01-01');
        $this->booking($cash, 7005, null, 'expense', '999.00');

        $this->assertSame(250.0, $this->service->balanceAt($cash, Carbon::parse('2026-12-31')));
    }

    public function testAccountsAreKeptApart(): void
    {
        $cash = $this->account('Barkassa', FinanceAccount::TYPE_CASH, '100.00', '2026-01-01');
        $bank = $this->account('Bankkonto', FinanceAccount::TYPE_BANK, '1000.00', '2026-01-01');
        $this->booking($cash, 7006, '2026-03-01', 'income', '25.00');
        $this->booking($bank, 7007, '2026-03-01', 'expense', '400.00');

        $this->assertSame(125.0, $this->service->balanceAt($cash, Carbon::parse('2026-12-31')));
        $this->assertSame(600.0, $this->service->balanceAt($bank, Carbon::parse('2026-12-31')));
    }

    public function testStatementCarriesTheClosingBalanceIntoTheNextYear(): void
    {
        $cash = $this->account('Barkassa', FinanceAccount::TYPE_CASH, '100.00', '2025-09-01');
        $this->booking($cash, 7008, '2025-10-01', 'income', '60.00');
        $this->booking($cash, 7009, '2026-10-01', 'expense', '20.00');

        $first = $this->service->statement(Carbon::parse('2025-09-01'), Carbon::parse('2026-08-31'));
        $second = $this->service->statement(Carbon::parse('2026-09-01'), Carbon::parse('2027-08-31'));

        $this->assertSame(100.0, $first['accounts'][0]['opening']);
        $this->assertSame(60.0, $first['accounts'][0]['income']);
        $this->assertSame(160.0, $first['accounts'][0]['closing']);

        // Der Endbestand des Vorjahres ist der Anfangsbestand des Folgejahres.
        $this->assertSame(160.0, $second['accounts'][0]['opening']);
        $this->assertSame(20.0, $second['accounts'][0]['expense']);
        $this->assertSame(140.0, $second['accounts'][0]['closing']);
    }

    public function testStatementIgnoresBookingsBeforeTheOpeningDate(): void
    {
        // Der Stichtag liegt mitten im Geschäftsjahr, davor stehen noch Altbuchungen.
        $cash = $this->account('Barkassa', FinanceAccount::TYPE_CASH, '100.00', '2026-03-01');
        $this->booking($cash, 7012, '2026-01-15', 'income', '500.00');
        $this->booking($cash, 7013, '2026-04-01', 'income', '40.00');
        $this->booking($cash, 7014, '2026-05-01', 'expense', '15.00');

        $row = $this->service->statement(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'))['accounts'][0];

        $this->assertSame(40.0, $row['income']);
        $this->assertSame(15.0, $row['expense']);
        // Kassabericht muss aufgehen: Anfangsbestand + Einnahmen - Ausgaben = Endbestand.
        $this->assertSame(
            $row['closing'],
            $row['opening'] + $row['income'] - $row['expense']
        );
    }

    public function testStatementTotalsAddUpAcrossAccounts(): void
    {
        $cash = $this->account('Barkassa', FinanceAccount::TYPE_CASH, '100.00', '2026-01-01');
        $bank = $this->account('Bankkonto', FinanceAccount::TYPE_BANK, '900.00', '2026-01-01');
        $this->booking($cash, 7010, '2026-03-01', 'income', '25.00');
        $this->booking($bank, 7011, '2026-03-01', 'expense', '100.00');

        $totals = $this->service->statement(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'))['totals'];

        $this->assertSame(1000.0, $totals['opening']);
        $this->assertSame(25.0, $totals['income']);
        $this->assertSame(100.0, $totals['expense']);
        $this->assertSame(925.0, $totals['closing']);
    }

    public function testFindsAccountByIbanRegardlessOfSpacingAndCase(): void
    {
        $bank = $this->account('Bankkonto', FinanceAccount::TYPE_BANK, '0.00', '2026-01-01');

        $this->assertSame($bank->id, $this->service->findByIban('AT911600000100629615')?->id);
        $this->assertSame($bank->id, $this->service->findByIban('at91 1600 0001 0062 9615')?->id);
        $this->assertNull($this->service->findByIban('DE44701600000000142108'));
        $this->assertNull($this->service->findByIban(null));
    }

    public function testFallsBackToTheDefaultAccountOfAPaymentMethod(): void
    {
        $cash = $this->account('Barkassa', FinanceAccount::TYPE_CASH, '0.00', '2026-01-01');
        $bank = $this->account('Bankkonto', FinanceAccount::TYPE_BANK, '0.00', '2026-01-01');

        $this->assertSame($cash->id, $this->service->defaultAccountForPaymentMethod('cash')?->id);
        $this->assertSame($bank->id, $this->service->defaultAccountForPaymentMethod('bank_transfer')?->id);
    }
}
