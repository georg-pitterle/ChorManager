<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BudgetCategory;
use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Models\FinanceGroup;
use App\Models\FinanceRevision;
use App\Models\Setting;
use App\Services\BudgetService;
use App\Services\FinanceBookingService;
use App\Services\FinanceGroupBudgetRule;
use App\Services\FinanceGroupConflictException;
use App\Services\FinanceJournalService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Eine Buchung trägt in ihrem Geschäftsjahr höchstens eine Gruppe, die dort im
 * Budget steht - sonst zählte sie in zwei Kategorien. Budgetrelevant ist eine
 * Gruppe nur im Jahr ihrer Kategorie, gleich ob Einnahme oder Ausgabe. Storno-
 * Paare zählen im Budget nicht und sind ausgenommen.
 */
final class FinanceGroupBudgetRuleFeatureTest extends TestCase
{
    use FinanceAccountFixture;

    private const YEAR = 2093;

    private FinanceBookingService $bookings;
    private FinanceGroupBudgetRule $rule;
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.01.']);
        Setting::where('setting_key', FinanceJournalService::CLOSED_UNTIL_KEY)->delete();
        $this->rule = new FinanceGroupBudgetRule(new BudgetService());
        $this->bookings = new FinanceBookingService(new FinanceJournalService(), $this->rule);
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

    public function testTwoBudgetedGroupsInTheSameFiscalYearConflict(): void
    {
        $x = $this->budgeted('X', self::YEAR, Finance::TYPE_EXPENSE);
        $y = $this->budgeted('Y', self::YEAR, Finance::TYPE_EXPENSE);

        $message = $this->rule->conflictMessage([(int) $y->id, (int) $x->id], self::YEAR);

        $this->assertSame(
            'Die Gruppen „X' . $this->suffix . '“ und „Y' . $this->suffix . '“ sind im Geschäftsjahr '
                . self::YEAR . ' beide im Budget. Eine Buchung darf nur zu einer davon gehören.',
            $message
        );
    }

    public function testABudgetedAndAnUnbudgetedGroupDoNotConflict(): void
    {
        $x = $this->budgeted('X', self::YEAR, Finance::TYPE_EXPENSE);
        $y = FinanceGroup::create(['name' => 'Y' . $this->suffix]);

        $this->assertNull($this->rule->conflictMessage([(int) $x->id, (int) $y->id], self::YEAR));
    }

    public function testAGroupBudgetedOnlyInAnotherYearIsNotRelevant(): void
    {
        $x = $this->budgeted('X', self::YEAR - 1, Finance::TYPE_EXPENSE);
        $y = $this->budgeted('Y', self::YEAR, Finance::TYPE_EXPENSE);

        $this->assertNull($this->rule->conflictMessage([(int) $x->id, (int) $y->id], self::YEAR));
    }

    public function testTheTypeOfTheCategoryDoesNotMatter(): void
    {
        $x = $this->budgeted('X', self::YEAR, Finance::TYPE_INCOME);
        $y = $this->budgeted('Y', self::YEAR, Finance::TYPE_EXPENSE);

        $this->assertNotNull($this->rule->conflictMessage([(int) $x->id, (int) $y->id], self::YEAR));
    }

    public function testAFiscalYearNotStartingInJanuaryIsLabelledWithBothYears(): void
    {
        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.07.']);
        $x = $this->budgeted('X', self::YEAR, Finance::TYPE_EXPENSE);
        $y = $this->budgeted('Y', self::YEAR, Finance::TYPE_EXPENSE);

        $message = (string) $this->rule->conflictMessage([(int) $x->id, (int) $y->id], self::YEAR);

        $this->assertStringContainsString('im Geschäftsjahr ' . self::YEAR . '/' . substr((string) (self::YEAR + 1), 2), $message);
    }

    public function testOpenItemsUseTheInvoiceDate(): void
    {
        $this->assertSame(2026, $this->rule->fiscalYearOf(null, '2026-11-03'));
        $this->assertSame(2027, $this->rule->fiscalYearOf('2027-01-10', '2026-11-03'));

        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.07.']);
        $this->assertSame(2026, $this->rule->fiscalYearOf('2027-03-15', '2027-03-15'));
        $this->assertSame(2027, $this->rule->fiscalYearOf('2027-07-01', '2027-03-15'));
    }

    public function testSavingAConflictingBookingThrowsAndWritesNothing(): void
    {
        $this->budgeted('X', self::YEAR, Finance::TYPE_EXPENSE);
        $this->budgeted('Y', self::YEAR, Finance::TYPE_EXPENSE);
        $before = Finance::count();
        $revisions = FinanceRevision::count();

        try {
            $this->save(null, ['X' . $this->suffix, 'Y' . $this->suffix], self::YEAR . '-05-01');
            $this->fail('Zwei Budget-Gruppen im selben Jahr müssen abgewiesen werden.');
        } catch (FinanceGroupConflictException $e) {
            $this->assertStringContainsString('X' . $this->suffix, $e->getMessage());
            $this->assertSame([], $e->rowMessages);
        }

        $this->assertSame($before, Finance::count());
        $this->assertSame($revisions, FinanceRevision::count());
    }

    public function testSavingUnchangedGroupsAndYearSkipsTheCheck(): void
    {
        $x = $this->budgeted('X', self::YEAR, Finance::TYPE_EXPENSE);
        $y = $this->budgeted('Y', self::YEAR, Finance::TYPE_EXPENSE);
        $finance = $this->save(null, [], self::YEAR . '-05-01');
        // Der Konflikt entstand ohne Zutun dieser Buchung, etwa durch eine
        // Budget-Kategorie aus der Zeit vor der Regel.
        $finance->groups()->sync([(int) $x->id, (int) $y->id]);

        $saved = $this->save((int) $finance->id, ['Y' . $this->suffix, 'X' . $this->suffix], self::YEAR . '-06-01', 'Neu');

        $this->assertSame('Neu', $saved->fresh()->description);
    }

    public function testMovingABookingIntoAYearWithAConflictIsRejected(): void
    {
        $x = $this->budgeted('X', self::YEAR, Finance::TYPE_EXPENSE);
        $y = $this->budgeted('Y', self::YEAR, Finance::TYPE_EXPENSE);
        $finance = $this->save(null, ['X' . $this->suffix, 'Y' . $this->suffix], (self::YEAR - 1) . '-05-01');
        $this->assertCount(2, $finance->fresh()->groups);

        $this->expectException(FinanceGroupConflictException::class);
        $this->save((int) $finance->id, ['X' . $this->suffix, 'Y' . $this->suffix], self::YEAR . '-05-01');
        unset($x, $y);
    }

    public function testAReversalIsExemptFromTheRule(): void
    {
        $original = $this->save(null, ['X' . $this->suffix, 'Y' . $this->suffix], (self::YEAR - 1) . '-12-01');
        $this->budgeted('X', self::YEAR, Finance::TYPE_EXPENSE);
        $this->budgeted('Y', self::YEAR, Finance::TYPE_EXPENSE);
        // Der Zeitraum des Originals ist abgeschlossen: Das Storno wandert in
        // das Jahr, in dem beide Gruppen budgetiert sind.
        Setting::updateOrCreate(
            ['setting_key' => FinanceJournalService::CLOSED_UNTIL_KEY],
            ['setting_value' => (self::YEAR - 1) . '-12-31']
        );

        $reversal = $this->bookings->reverse($original->fresh(), 1);

        $this->assertNotNull($reversal);
        $this->assertCount(2, $reversal->fresh()->groups);
    }

    public function testImportRejectsTheWholeStatementAndNamesTheConflictingRows(): void
    {
        $this->budgeted('X', self::YEAR, Finance::TYPE_EXPENSE);
        $this->budgeted('Y', self::YEAR, Finance::TYPE_EXPENSE);
        $before = Finance::count();

        try {
            $this->bookings->importRows([
                $this->importRow(4, ['X' . $this->suffix]),
                $this->importRow(7, ['X' . $this->suffix, 'Y' . $this->suffix]),
            ], FinanceAccount::findOrFail($this->fixtureAccountId()), 1);
            $this->fail('Eine kollidierende Zeile muss den ganzen Import abweisen.');
        } catch (FinanceGroupConflictException $e) {
            $this->assertSame([7], array_keys($e->rowMessages));
        }

        $this->assertSame($before, Finance::count());
    }

    public function testConflictingBookingIdsIgnoresReversalPairs(): void
    {
        $x = FinanceGroup::create(['name' => 'X' . $this->suffix]);
        $this->budgeted('Y', self::YEAR, Finance::TYPE_EXPENSE);
        $plain = $this->save(null, ['X' . $this->suffix, 'Y' . $this->suffix], self::YEAR . '-03-01');
        $open = $this->save(null, ['X' . $this->suffix, 'Y' . $this->suffix], null, 'Offen', self::YEAR . '-03-01');
        $reversed = $this->save(null, ['X' . $this->suffix, 'Y' . $this->suffix], self::YEAR . '-03-02');
        $reversal = $this->bookings->reverse($reversed->fresh(), 1);
        $this->save(null, ['X' . $this->suffix], self::YEAR . '-03-03');

        $ids = $this->rule->conflictingBookingIds((int) $x->id, self::YEAR);

        sort($ids);
        $expected = [(int) $plain->id, (int) $open->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotNull($reversal);
    }

    private function budgeted(string $name, int $year, string $type): FinanceGroup
    {
        $group = FinanceGroup::firstOrCreate(['name' => $name . $this->suffix]);
        BudgetCategory::create(['fiscal_year_start' => $year, 'finance_group_id' => $group->id, 'type' => $type]);

        return $group;
    }

    /**
     * @param list<string> $groupNames
     */
    private function save(
        ?int $id,
        array $groupNames,
        ?string $paymentDate,
        string $description = 'Regeltest',
        ?string $invoiceDate = null
    ): Finance {
        $finance = $this->bookings->save($id, [
            'invoice_date' => $invoiceDate ?? $paymentDate,
            'payment_date' => $paymentDate,
            'description' => $description,
            'type' => Finance::TYPE_EXPENSE,
            'amount' => '25.00',
            'finance_account_id' => $this->fixtureAccountId(),
            'payment_method' => Finance::PAYMENT_METHOD_BANK_TRANSFER,
        ], $groupNames, 1);
        $this->assertNotNull($finance);

        return $finance;
    }

    /**
     * @param list<string> $groupNames
     * @return array<string, mixed>
     */
    private function importRow(int $index, array $groupNames): array
    {
        return [
            'index' => $index,
            'invoice_date' => self::YEAR . '-04-01',
            'payment_date' => self::YEAR . '-04-01',
            'description' => 'Import ' . $index,
            'type' => Finance::TYPE_EXPENSE,
            'amount' => '5.00',
            'import_hash' => 'hash' . bin2hex(random_bytes(8)),
            'group_names' => $groupNames,
        ];
    }
}
