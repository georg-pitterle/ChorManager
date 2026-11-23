<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BudgetCategory;
use App\Models\Finance;
use App\Models\FinanceGroup;
use App\Models\Setting;
use App\Services\BudgetCategoryConflictException;
use App\Services\BudgetService;
use App\Services\BudgetWriteService;
use App\Services\FinanceBookingService;
use App\Services\FinanceGroupBudgetRule;
use App\Services\FinanceJournalService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Eine Budget-Kategorie lässt sich nicht anlegen oder auf eine andere Gruppe
 * umhängen, wenn Buchungen des Jahres die Gruppe zusammen mit einer anderen
 * dort budgetierten Gruppe tragen - sie zählten sonst doppelt. Storno-Paare
 * zählen im Budget nicht und blockieren deshalb auch nichts.
 */
final class BudgetCategoryConflictFeatureTest extends TestCase
{
    use FinanceAccountFixture;

    private const YEAR = 2097;

    private BudgetWriteService $writer;
    private FinanceBookingService $bookings;
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.01.']);
        Setting::where('setting_key', FinanceJournalService::CLOSED_UNTIL_KEY)->delete();
        $rule = new FinanceGroupBudgetRule(new BudgetService());
        $this->writer = new BudgetWriteService($rule);
        $this->bookings = new FinanceBookingService(new FinanceJournalService(), $rule);
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

    public function testCreatingACategoryIsBlockedWhenBookingsWouldCountTwice(): void
    {
        $y = $this->group('Y');
        $this->writer->createCategory(self::YEAR, $y, Finance::TYPE_EXPENSE);
        $finance = $this->booking(['X', 'Y']);
        $x = $this->group('X');

        try {
            $this->writer->createCategory(self::YEAR, $x, Finance::TYPE_EXPENSE);
            $this->fail('Die Kategorie hätte abgewiesen werden müssen.');
        } catch (BudgetCategoryConflictException $e) {
            $this->assertSame([(int) $finance->running_number], $e->runningNumbers);
            $this->assertStringContainsString('1 Buchung gehört auch zu einer anderen Budget-Gruppe', $e->getMessage());
            $this->assertStringContainsString('Nr. ' . $finance->running_number, $e->getMessage());
        }

        $this->assertSame(0, BudgetCategory::where('finance_group_id', $x)->count());
    }

    public function testAddingASecondTypeForAnAlreadyBudgetedGroupIsAllowed(): void
    {
        $x = $this->group('X');
        $this->writer->createCategory(self::YEAR, $x, Finance::TYPE_INCOME);
        $this->booking(['X', 'Z']);

        $category = $this->writer->createCategory(self::YEAR, $x, Finance::TYPE_EXPENSE);

        $this->assertSame(Finance::TYPE_EXPENSE, $category->type);
    }

    public function testReversalPairsDoNotBlockACategory(): void
    {
        $y = $this->group('Y');
        $this->writer->createCategory(self::YEAR, $y, Finance::TYPE_EXPENSE);
        // Vor der Kategorie für X gebucht: damals war nur Y budgetiert.
        $finance = $this->booking(['X', 'Y']);
        $this->bookings->reverse($finance->fresh(), 1);

        $category = $this->writer->createCategory(self::YEAR, $this->group('X'), Finance::TYPE_EXPENSE);

        $this->assertNotNull($category->id);
    }

    public function testChangingTheGroupOfACategoryIsBlockedTheSameWay(): void
    {
        $y = $this->group('Y');
        $this->writer->createCategory(self::YEAR, $y, Finance::TYPE_EXPENSE);
        $other = $this->writer->createCategory(self::YEAR, $this->group('W'), Finance::TYPE_EXPENSE);
        $this->booking(['X', 'Y']);

        $this->expectException(BudgetCategoryConflictException::class);
        $this->writer->changeCategoryGroup($other, $this->group('X'));
    }

    public function testChangingAGroupIgnoresTheCategoryBeingChanged(): void
    {
        // Die Buchung trägt X und W. W ist budgetiert, aber genau diese Kategorie
        // wechselt auf X - danach zählt die Buchung nur noch einmal.
        $category = $this->writer->createCategory(self::YEAR, $this->group('W'), Finance::TYPE_EXPENSE);
        $this->booking(['X', 'W']);

        $this->writer->changeCategoryGroup($category, $this->group('X'));

        $this->assertSame($this->group('X'), (int) $category->fresh()->finance_group_id);
    }

    public function testCategoriesOfOtherYearsAreUnaffected(): void
    {
        $this->writer->createCategory(self::YEAR, $this->group('Y'), Finance::TYPE_EXPENSE);
        $this->booking(['X', 'Y']);

        $category = $this->writer->createCategory(self::YEAR + 1, $this->group('X'), Finance::TYPE_EXPENSE);

        $this->assertSame(self::YEAR + 1, (int) $category->fiscal_year_start);
    }

    public function testADuplicateCategoryIsStillRejected(): void
    {
        $x = $this->group('X');
        $this->writer->createCategory(self::YEAR, $x, Finance::TYPE_EXPENSE);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Diese Kategorie existiert bereits für das gewählte Haushaltsjahr.');
        $this->writer->createCategory(self::YEAR, $x, Finance::TYPE_EXPENSE);
    }

    public function testANewGroupNameWithASemicolonIsNotCreated(): void
    {
        $this->assertNull($this->writer->resolveFinanceGroupId(['new_group_name' => 'A; B' . $this->suffix]));
        $this->assertSame(0, FinanceGroup::where('name', 'A; B' . $this->suffix)->count());
    }

    private function group(string $name): int
    {
        return (int) FinanceGroup::firstOrCreate(['name' => $name . $this->suffix])->id;
    }

    /**
     * @param list<string> $groups Namen ohne Anhängsel
     */
    private function booking(array $groups): Finance
    {
        $finance = $this->bookings->save(null, [
            'invoice_date' => self::YEAR . '-04-01',
            'payment_date' => self::YEAR . '-04-01',
            'description' => 'Kategorietest',
            'type' => Finance::TYPE_EXPENSE,
            'amount' => '30.00',
            'finance_account_id' => $this->fixtureAccountId(),
            'payment_method' => Finance::PAYMENT_METHOD_BANK_TRANSFER,
        ], array_map(fn(string $name): string => $name . $this->suffix, $groups), 1);
        $this->assertNotNull($finance);

        return $finance;
    }
}
