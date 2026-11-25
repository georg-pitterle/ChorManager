<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Models\FinanceGroup;
use App\Models\Setting;
use App\Services\FinanceBookingService;
use App\Services\FinanceJournalService;
use App\Services\FinancePeriodLockedException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der Kontoauszug-Import weist einen abgeschlossenen Zeitraum selbst ab, und
 * eine einzige gesperrte Zeile nimmt den ganzen Auszug mit.
 *
 * Befund aus Review-Lauf 85: FinanceBookingService::importRows() schrieb ohne
 * jede Prüfung der Periodensperre, während save(), reverse() und
 * bulkChangeGroup() sie je in ihrer Transaktion halten. Der Wächter saß allein
 * in FinanceController::flagKnownRows(). Damit blieb ein Fenster offen - wird
 * der Abschluss-Stichtag zwischen jener Prüfung und dem Schreiben verschoben,
 * entstehen Buchungen in einem bereits geprüften Jahr (§ 131 BAO) -, und ein
 * zweiter Aufrufer des Dienstes hätte die Regel gar nicht gekannt.
 */
final class FinanceImportPeriodLockFeatureTest extends TestCase
{
    use FinanceAccountFixture;

    private FinanceBookingService $bookings;
    private FinanceAccount $account;
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.01.']);
        $this->bookings = new FinanceBookingService(new FinanceJournalService());
        $this->account = FinanceAccount::findOrFail($this->fixtureAccountId());
        $this->suffix = ' ' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Eine gesperrte Zeile weist den ganzen Auszug ab - genau wie beim
     * Gruppen-Konflikt. Still übersprungen fiele eine fehlende Buchung aus dem
     * Kontoauszug niemandem auf.
     */
    public function testOneRowInAClosedPeriodRejectsTheWholeStatement(): void
    {
        (new FinanceJournalService())->setClosedUntil('2089-06-30');

        try {
            $this->bookings->importRows(
                [$this->row(2, '2089-06-15'), $this->row(5, '2089-07-15')],
                $this->account,
                1
            );
            $this->fail('Eine Zeile im abgeschlossenen Zeitraum muss den Auszug abweisen.');
        } catch (FinancePeriodLockedException $e) {
            $this->assertSame([2], array_keys($e->rowMessages));
            $this->assertStringContainsString('15.06.2089', $e->rowMessages[2]);
            $this->assertStringContainsString('30.06.2089', $e->rowMessages[2]);
        }

        $this->assertSame(0, $this->importedRows()->count());
    }

    /**
     * Abgewiesen wird vor dem Auflösen der Gruppen: Ein Auszug, der ohnehin
     * nicht durchgeht, soll keine Gruppe hinterlassen - Gruppen lassen sich in
     * der Oberfläche nicht wieder löschen.
     */
    public function testARejectedStatementLeavesNoNewGroupBehind(): void
    {
        (new FinanceJournalService())->setClosedUntil('2089-06-30');
        $row = $this->row(0, '2089-06-15');
        $row['group_names'] = ['Reise' . $this->suffix];

        try {
            $this->bookings->importRows([$row], $this->account, 1);
            $this->fail('Eine Zeile im abgeschlossenen Zeitraum muss den Auszug abweisen.');
        } catch (FinancePeriodLockedException) {
            // Erwartet; geprüft wird, was danach in der Datenbank steht.
        }

        $this->assertSame(0, FinanceGroup::where('name', 'Reise' . $this->suffix)->count());
    }

    /**
     * Ein offener Posten ohne Zahldatum fällt in keinen Zeitraum und ist
     * deshalb nie gesperrt - dieselbe Regel wie in isLocked(). Ohne diesen
     * Rand sperrte ein Abschluss-Stichtag jeden offenen Posten mit.
     */
    public function testAnOpenItemWithoutAPaymentDateStaysImportable(): void
    {
        (new FinanceJournalService())->setClosedUntil('2089-12-31');
        $row = $this->row(0, null);

        $imported = $this->bookings->importRows([$row], $this->account, 1);

        $this->assertSame(1, $imported);
        $this->assertSame(1, $this->importedRows()->count());
    }

    public function testRowsOutsideTheClosedPeriodAreImported(): void
    {
        (new FinanceJournalService())->setClosedUntil('2089-06-30');

        $imported = $this->bookings->importRows(
            [$this->row(0, '2089-07-01'), $this->row(1, '2089-08-01')],
            $this->account,
            1
        );

        $this->assertSame(2, $imported);
        $this->assertSame(2, $this->importedRows()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $index, ?string $paymentDate): array
    {
        return [
            'index' => $index,
            'invoice_date' => '2089-06-15',
            'payment_date' => $paymentDate,
            'description' => 'Import' . $this->suffix,
            'type' => Finance::TYPE_EXPENSE,
            'amount' => '12.00',
            'import_hash' => 'hash' . bin2hex(random_bytes(8)),
            'group_names' => [],
        ];
    }

    /** Nur die Zeilen dieses Testfalls, nie vorhandene Seed-Daten. */
    private function importedRows(): Builder
    {
        return Finance::query()->where('description', 'Import' . $this->suffix);
    }
}
