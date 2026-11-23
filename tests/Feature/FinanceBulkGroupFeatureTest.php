<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BudgetCategory;
use App\Models\Finance;
use App\Models\FinanceGroup;
use App\Models\FinanceRevision;
use App\Models\Setting;
use App\Services\BudgetService;
use App\Services\FinanceBookingService;
use App\Services\FinanceGroupBudgetRule;
use App\Services\FinanceJournalService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Stapelbearbeitung fügt den markierten Buchungen eine Gruppe hinzu oder
 * nimmt sie weg und lässt die übrigen Gruppen stehen. Buchungen im
 * abgeschlossenen Zeitraum, Storno-Paare und Buchungen, die eine zweite
 * Budget-Gruppe bekämen, bleiben unverändert und werden genannt.
 */
final class FinanceBulkGroupFeatureTest extends TestCase
{
    use BuildsControllers;
    use FinanceAccountFixture;
    use TestHttpHelpers;

    private const YEAR = 2095;

    private FinanceBookingService $bookings;
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.01.']);
        Setting::where('setting_key', FinanceJournalService::CLOSED_UNTIL_KEY)->delete();
        $_SESSION = ['user_id' => 1, 'can_manage_finances' => true, 'can_read_finances' => true];
        $this->bookings = new FinanceBookingService(
            new FinanceJournalService(),
            new FinanceGroupBudgetRule(new BudgetService())
        );
        $this->suffix = ' ' . bin2hex(random_bytes(4));
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

    public function testAddingAGroupKeepsTheOtherGroupsAndJournalsEachBooking(): void
    {
        $a = $this->booking(['Konzert']);
        $b = $this->booking([]);

        $result = $this->bookings->bulkChangeGroup(
            [(int) $a->id, (int) $b->id],
            'Herbstkonzert' . $this->suffix,
            FinanceBookingService::BULK_ADD,
            1
        );

        $this->assertSame(2, $result->changed);
        $this->assertSame(['Herbstkonzert' . $this->suffix, 'Konzert' . $this->suffix], $a->fresh()->groupNames());
        $this->assertSame(['Herbstkonzert' . $this->suffix], $b->fresh()->groupNames());
        foreach ([$a, $b] as $finance) {
            $this->assertSame(1, FinanceRevision::where('finance_id', $finance->id)
                ->where('action', FinanceRevision::ACTION_UPDATE)->count());
        }
    }

    public function testRemovingAGroupLeavesBookingsWithoutItUntouched(): void
    {
        $with = $this->booking(['Konzert', 'Reise']);
        $without = $this->booking(['Reise']);

        $result = $this->bookings->bulkChangeGroup(
            [(int) $with->id, (int) $without->id],
            'Konzert' . $this->suffix,
            FinanceBookingService::BULK_REMOVE,
            1
        );

        $this->assertSame(1, $result->changed);
        $this->assertSame(1, $result->unchanged);
        $this->assertSame(['Reise' . $this->suffix], $with->fresh()->groupNames());
        $this->assertSame(0, FinanceRevision::where('finance_id', $without->id)
            ->where('action', FinanceRevision::ACTION_UPDATE)->count());
    }

    public function testLockedReversedAndConflictingBookingsAreSkippedAndNamed(): void
    {
        $locked = $this->booking([], (self::YEAR - 1) . '-06-01');
        $reversed = $this->booking([]);
        $reversal = $this->bookings->reverse($reversed->fresh(), 1);
        $this->budgeted('Konzert');
        $this->budgeted('Reise');
        $conflicting = $this->booking(['Konzert']);
        $fine = $this->booking([]);
        Setting::updateOrCreate(
            ['setting_key' => FinanceJournalService::CLOSED_UNTIL_KEY],
            ['setting_value' => (self::YEAR - 1) . '-12-31']
        );

        $result = $this->bookings->bulkChangeGroup(
            [(int) $locked->id, (int) $reversed->id, (int) $reversal->id, (int) $conflicting->id, (int) $fine->id],
            'Reise' . $this->suffix,
            FinanceBookingService::BULK_ADD,
            1
        );

        $this->assertSame(1, $result->changed);
        $this->assertSame([(int) $locked->running_number], $result->skippedLocked);
        $this->assertSame(
            [(int) $reversed->running_number, (int) $reversal->running_number],
            $result->skippedReversal
        );
        $this->assertSame([(int) $conflicting->running_number], $result->skippedBudgetConflict);
        $this->assertSame([], $locked->fresh()->groupNames());
        $this->assertSame(['Konzert' . $this->suffix], $conflicting->fresh()->groupNames());
    }

    /**
     * Gruppen lassen sich in der Oberfläche nicht löschen: Ein Tippfehler, der
     * keine Buchung erreicht, darf deshalb keine Gruppe hinterlassen.
     */
    public function testANewGroupThatReachesNoBookingIsNotKept(): void
    {
        $locked = $this->booking([], (self::YEAR - 1) . '-06-01');
        Setting::updateOrCreate(
            ['setting_key' => FinanceJournalService::CLOSED_UNTIL_KEY],
            ['setting_value' => (self::YEAR - 1) . '-12-31']
        );

        $result = $this->bookings->bulkChangeGroup(
            [(int) $locked->id],
            'Konezrt' . $this->suffix,
            FinanceBookingService::BULK_ADD,
            1
        );

        $this->assertSame(0, $result->changed);
        $this->assertSame(0, FinanceGroup::where('name', 'Konezrt' . $this->suffix)->count());
    }

    /**
     * "Alle sichtbaren" kann ein ganzes Jahr umfassen: Die budgetierten Gruppen
     * eines Geschäftsjahres werden einmal gelesen, nicht je Buchung.
     */
    public function testBudgetedGroupsAreReadOncePerFiscalYear(): void
    {
        $this->budgeted('Konzert');
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = (int) $this->booking([])->id;
        }
        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->bookings->bulkChangeGroup($ids, 'Anlass' . $this->suffix, FinanceBookingService::BULK_ADD, 1);

        $budgetQueries = array_filter(
            $connection->getQueryLog(),
            static fn(array $entry): bool => str_contains($entry['query'], 'budget_categories')
        );
        $connection->disableQueryLog();
        $this->assertCount(1, $budgetQueries);
    }

    public function testUnknownAndDuplicateIdsAreIgnored(): void
    {
        $finance = $this->booking([]);

        $result = $this->bookings->bulkChangeGroup(
            [(int) $finance->id, (int) $finance->id, 999999999],
            'Konzert' . $this->suffix,
            FinanceBookingService::BULK_ADD,
            1
        );

        $this->assertSame(1, $result->changed);
        $this->assertSame(1, FinanceRevision::where('finance_id', $finance->id)
            ->where('action', FinanceRevision::ACTION_UPDATE)->count());
    }

    public function testRemovingAnUnknownGroupChangesNothingAndCreatesNoGroup(): void
    {
        $finance = $this->booking(['Konzert']);

        $result = $this->bookings->bulkChangeGroup(
            [(int) $finance->id],
            'Gibt es nicht' . $this->suffix,
            FinanceBookingService::BULK_REMOVE,
            1
        );

        $this->assertSame(0, $result->changed);
        $this->assertSame(1, $result->unchanged);
        $this->assertSame(0, FinanceGroup::where('name', 'Gibt es nicht' . $this->suffix)->count());
    }

    public function testTheResultMessageCountsChangesAndSkips(): void
    {
        $result = new \App\Services\FinanceBulkGroupResult(3, 2, [12], [15, 16], range(20, 31));

        $this->assertSame(
            '3 Buchungen geändert, 2 waren schon so. Übersprungen: 1 im abgeschlossenen Zeitraum (Nr. 12), '
                . '2 storniert (Nr. 15, 16), 12 mit zweiter Budget-Gruppe '
                . '(Nr. 20, 21, 22, 23, 24, 25, 26, 27, 28, 29 und 2 weitere).',
            $result->message()
        );
        $this->assertSame(
            '1 Buchung geändert.',
            (new \App\Services\FinanceBulkGroupResult(1, 0, [], [], []))->message()
        );
    }

    public function testTheControllerChangesTheSelectedBookingsAndReportsTheResult(): void
    {
        $finance = $this->booking([]);
        $controller = $this->financeController(['view' => $this->createStub(Twig::class), 'bookings' => $this->bookings]);

        $response = $controller->bulkGroups($this->makeRequest('POST', '/finances/bulk-groups', [
            'finance_ids' => [(string) $finance->id],
            'group_name' => ' Konzert' . $this->suffix . ' ',
            'mode' => 'add',
            'year' => (string) self::YEAR,
        ]), $this->makeResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/finances?year=' . self::YEAR, $response->getHeaderLine('Location'));
        $this->assertSame('1 Buchung geändert.', $_SESSION['success']);
        $this->assertSame(['Konzert' . $this->suffix], $finance->fresh()->groupNames());
    }

    public function testTheControllerRejectsAMissingGroupOrSelection(): void
    {
        $finance = $this->booking([]);
        $controller = $this->financeController(['view' => $this->createStub(Twig::class), 'bookings' => $this->bookings]);

        $controller->bulkGroups($this->makeRequest('POST', '/finances/bulk-groups', [
            'finance_ids' => [(string) $finance->id],
            'group_name' => '  ',
            'mode' => 'add',
        ]), $this->makeResponse());
        $this->assertSame('Bitte eine Gruppe angeben.', $_SESSION['error']);

        $controller->bulkGroups($this->makeRequest('POST', '/finances/bulk-groups', [
            'group_name' => 'Konzert',
            'mode' => 'add',
        ]), $this->makeResponse());
        $this->assertSame('Bitte mindestens eine Buchung auswählen.', $_SESSION['error']);

        $controller->bulkGroups($this->makeRequest('POST', '/finances/bulk-groups', [
            'finance_ids' => [(string) $finance->id],
            'group_name' => 'Konzert',
            'mode' => 'drop',
        ]), $this->makeResponse());
        $this->assertSame('Unbekannte Aktion.', $_SESSION['error']);

        $controller->bulkGroups($this->makeRequest('POST', '/finances/bulk-groups', [
            'finance_ids' => [(string) $finance->id],
            'group_name' => 'Konzert; Reise',
            'mode' => 'add',
        ]), $this->makeResponse());
        $this->assertStringContainsString('Semikolon', $_SESSION['error']);
        $this->assertSame([], $finance->fresh()->groupNames());
    }

    private function budgeted(string $name): void
    {
        BudgetCategory::create([
            'fiscal_year_start' => self::YEAR,
            'finance_group_id' => FinanceGroup::firstOrCreate(['name' => $name . $this->suffix])->id,
            'type' => Finance::TYPE_EXPENSE,
        ]);
    }

    /**
     * @param list<string> $groups Namen ohne Anhängsel
     */
    private function booking(array $groups, ?string $paymentDate = null): Finance
    {
        $paymentDate ??= self::YEAR . '-03-01';
        $finance = $this->bookings->save(null, [
            'invoice_date' => $paymentDate,
            'payment_date' => $paymentDate,
            'description' => 'Stapeltest',
            'type' => Finance::TYPE_EXPENSE,
            'amount' => '20.00',
            'finance_account_id' => $this->fixtureAccountId(),
            'payment_method' => Finance::PAYMENT_METHOD_BANK_TRANSFER,
        ], array_map(fn(string $name): string => $name . $this->suffix, $groups), 1);
        $this->assertNotNull($finance);

        return $finance;
    }
}
