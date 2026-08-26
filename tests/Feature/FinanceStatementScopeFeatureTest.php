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

/**
 * Der Kassabericht weist je Konto aus, wie viele Buchungen vor dem Stichtag des
 * Anfangsbestands liegen und deshalb in keiner Bewegungssumme auftauchen. Der
 * Zähler zählte bisher jede solche Buchung - auch die aus längst vergangenen
 * Geschäftsjahren, die in der Buchungsliste dieses Berichts gar nicht steht.
 * Erklärt werden soll aber genau die Differenz innerhalb dieses Zeitraums.
 */
final class FinanceStatementScopeFeatureTest extends TestCase
{
    private FinanceAccountService $service;
    private FinanceAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->service = new FinanceAccountService();
        $this->account = FinanceAccount::create([
            'name' => 'Stichtagskonto ' . bin2hex(random_bytes(3)),
            'type' => FinanceAccount::TYPE_CASH,
            'opening_balance' => 100.0,
            'opening_date' => '2026-03-01',
            'is_active' => true,
            'sort_order' => 99,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function booking(string $paymentDate, float $amount = 10.0): void
    {
        Finance::create([
            'running_number' => random_int(900000, 999999),
            'invoice_date' => $paymentDate,
            'payment_date' => $paymentDate,
            'description' => 'Testbuchung ' . $paymentDate,
            'type' => 'expense',
            'amount' => $amount,
            'payment_method' => 'cash',
            'finance_account_id' => $this->account->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function statementFor(string $from, string $to): array
    {
        $statement = $this->service->statement(Carbon::parse($from), Carbon::parse($to));

        foreach ($statement['accounts'] as $row) {
            if ((int) $row['account']->id === (int) $this->account->id) {
                return $row;
            }
        }

        self::fail('Das Konto fehlt im Kassabericht.');
    }

    public function testOnlyBookingsInsideTheReportedPeriodAreCounted(): void
    {
        // Vor dem Stichtag, aber ausserhalb des Berichtszeitraums.
        $this->booking('2024-05-10');
        // Vor dem Stichtag und innerhalb des Berichtszeitraums.
        $this->booking('2026-02-10');

        $row = $this->statementFor('2026-01-01', '2026-12-31');

        $this->assertSame(1, (int) $row['before_opening_count']);
        $this->assertSame('2026-02-10', (string) $row['before_opening_first']);
    }

    public function testAPeriodWithoutSuchBookingsReportsNothing(): void
    {
        $this->booking('2024-05-10');

        $row = $this->statementFor('2026-01-01', '2026-12-31');

        $this->assertSame(0, (int) $row['before_opening_count']);
        $this->assertNull($row['before_opening_first']);
    }
}
