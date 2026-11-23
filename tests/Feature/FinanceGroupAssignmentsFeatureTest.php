<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Finance;
use App\Models\FinanceGroup;
use App\Models\FinanceRevision;
use App\Models\Setting;
use App\Services\BudgetService;
use App\Services\FinanceBookingService;
use App\Services\FinanceCsvExportService;
use App\Services\FinanceJournalService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Alle Wege, die Gruppen einer Buchung schreiben oder lesen, gehen über die
 * Zuordnungstabelle: Speichern, Import, Storno, Budget-Ist, Bericht, Export
 * und Journal. Eine Buchung zählt in jeder ihrer Gruppen voll.
 */
final class FinanceGroupAssignmentsFeatureTest extends TestCase
{
    use BuildsControllers;
    use FinanceAccountFixture;

    private const YEAR = 2091;

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
        $this->bookings = new FinanceBookingService(new FinanceJournalService());
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

    public function testSavingABookingStoresAllGivenGroups(): void
    {
        $existing = FinanceGroup::create(['name' => 'Konzert' . $this->suffix]);

        $finance = $this->save(null, ['Konzert' . $this->suffix, 'Herbstkonzert' . $this->suffix]);

        $this->assertSame(
            ['Herbstkonzert' . $this->suffix, 'Konzert' . $this->suffix],
            $finance->fresh()->groupNames()
        );
        $this->assertSame(1, FinanceGroup::where('name', 'Konzert' . $this->suffix)->count());
        $this->assertTrue($finance->fresh()->groups->contains('id', $existing->id));
    }

    public function testGroupNamesDifferingOnlyInCaseOrSpacesHitTheSameGroup(): void
    {
        $existing = FinanceGroup::create(['name' => 'Konzert' . $this->suffix]);

        $finance = $this->save(null, [' konzert' . $this->suffix . ' ', 'Konzert' . $this->suffix]);

        $this->assertSame([(int) $existing->id], $finance->fresh()->groups->pluck('id')->map('intval')->all());
        $this->assertSame(1, FinanceGroup::where('name', 'like', '%' . trim($this->suffix))->count());
    }

    public function testUpdatingReplacesTheGroupsAndJournalsOneGroupsChange(): void
    {
        $finance = $this->save(null, ['A' . $this->suffix, 'B' . $this->suffix]);
        $this->save((int) $finance->id, ['B' . $this->suffix, 'C' . $this->suffix]);

        $this->assertSame(['B' . $this->suffix, 'C' . $this->suffix], $finance->fresh()->groupNames());
        $update = FinanceRevision::where('finance_id', $finance->id)
            ->where('action', FinanceRevision::ACTION_UPDATE)
            ->sole();
        $this->assertSame(
            ['from' => 'A' . $this->suffix . '; B' . $this->suffix, 'to' => 'B' . $this->suffix . '; C' . $this->suffix],
            $update->change_set['groups']
        );

        $this->save((int) $finance->id, ['C' . $this->suffix, 'B' . $this->suffix], 'Neue Beschreibung');
        $latest = FinanceRevision::where('finance_id', $finance->id)
            ->where('action', FinanceRevision::ACTION_UPDATE)
            ->orderByDesc('id')
            ->first();
        $this->assertArrayNotHasKey('groups', $latest->change_set);
    }

    public function testARefusedSaveInALockedPeriodCreatesNoGroup(): void
    {
        $finance = $this->save(null, []);
        Setting::updateOrCreate(
            ['setting_key' => FinanceJournalService::CLOSED_UNTIL_KEY],
            ['setting_value' => self::YEAR . '-12-31']
        );

        $result = $this->bookings->save((int) $finance->id, [
            'invoice_date' => self::YEAR . '-03-01',
            'payment_date' => self::YEAR . '-03-01',
            'description' => 'Gesperrt',
            'type' => Finance::TYPE_EXPENSE,
            'amount' => '100.00',
            'finance_account_id' => $this->fixtureAccountId(),
            'payment_method' => Finance::PAYMENT_METHOD_BANK_TRANSFER,
        ], ['Tippfehler' . $this->suffix], 1);

        $this->assertNull($result);
        $this->assertSame(0, FinanceGroup::where('name', 'Tippfehler' . $this->suffix)->count());
    }

    /**
     * Journal und Export trennen Gruppen mit "; " - ein Name mit Semikolon wäre
     * dort nicht von zwei Gruppen zu unterscheiden.
     */
    public function testAGroupNameWithASemicolonIsRejected(): void
    {
        try {
            $this->save(null, ['Konzert; Reise' . $this->suffix]);
            $this->fail('Ein Semikolon im Gruppennamen muss abgewiesen werden.');
        } catch (\App\Services\FinanceGroupConflictException $e) {
            $this->assertStringContainsString('Semikolon', $e->getMessage());
        }

        $this->assertSame(0, FinanceGroup::where('name', 'Konzert; Reise' . $this->suffix)->count());
    }

    public function testAnImportRowWithASemicolonGroupIsNamed(): void
    {
        try {
            $this->bookings->importRows([[
                'index' => 3,
                'invoice_date' => self::YEAR . '-04-01',
                'payment_date' => self::YEAR . '-04-01',
                'description' => 'Import',
                'type' => Finance::TYPE_INCOME,
                'amount' => '12.00',
                'import_hash' => 'hash' . bin2hex(random_bytes(8)),
                'group_names' => ['A;B' . $this->suffix],
            ]], \App\Models\FinanceAccount::findOrFail($this->fixtureAccountId()), 1);
            $this->fail('Die Zeile hätte abgewiesen werden müssen.');
        } catch (\App\Services\FinanceGroupConflictException $e) {
            $this->assertSame([3], array_keys($e->rowMessages));
            $this->assertStringContainsString('Semikolon', $e->rowMessages[3]);
        }
    }

    public function testReversalCopiesAllGroupsOfTheOriginal(): void
    {
        $original = $this->save(null, ['A' . $this->suffix, 'B' . $this->suffix]);

        $reversal = $this->bookings->reverse($original->fresh(), 1);

        $this->assertSame(['A' . $this->suffix, 'B' . $this->suffix], $reversal->fresh()->groupNames());
    }

    public function testImportedRowsGetTheirGroups(): void
    {
        $this->bookings->importRows([[
            'invoice_date' => self::YEAR . '-04-01',
            'payment_date' => self::YEAR . '-04-01',
            'description' => 'Import',
            'type' => Finance::TYPE_INCOME,
            'amount' => '12.00',
            'import_hash' => 'hash' . bin2hex(random_bytes(8)),
            'group_names' => ['A' . $this->suffix, 'B' . $this->suffix],
        ]], \App\Models\FinanceAccount::findOrFail($this->fixtureAccountId()), 1);

        $imported = Finance::where('description', 'Import')->latest('id')->firstOrFail();
        $this->assertSame(['A' . $this->suffix, 'B' . $this->suffix], $imported->groupNames());
    }

    public function testBudgetActualCountsBookingsByAssignment(): void
    {
        $this->save(null, ['X' . $this->suffix, 'Y' . $this->suffix]);
        $x = FinanceGroup::where('name', 'X' . $this->suffix)->firstOrFail();
        $y = FinanceGroup::where('name', 'Y' . $this->suffix)->firstOrFail();
        $from = Carbon::create(self::YEAR, 1, 1);
        $to = Carbon::create(self::YEAR, 12, 31);
        $budget = new BudgetService();

        $this->assertSame('100.00', $budget->computeActual((int) $x->id, Finance::TYPE_EXPENSE, $from, $to));
        $this->assertSame('100.00', $budget->computeActual((int) $y->id, Finance::TYPE_EXPENSE, $from, $to));
    }

    public function testReportTotalsCountABookingInEachOfItsGroups(): void
    {
        $this->save(null, ['A' . $this->suffix, 'B' . $this->suffix]);

        $report = $this->reportData();

        $this->assertSame(100.0, $report['group_totals']['A' . $this->suffix]['expense']);
        $this->assertSame(100.0, $report['group_totals']['B' . $this->suffix]['expense']);
        $this->assertTrue($report['groups_overlap']);
    }

    public function testReportWithSingleGroupsDoesNotWarnAboutOverlap(): void
    {
        $this->save(null, ['A' . $this->suffix]);
        $this->save(null, []);

        $report = $this->reportData();

        $this->assertFalse($report['groups_overlap']);
        $this->assertSame(100.0, $report['group_totals']['(Keine Gruppe)']['expense']);
    }

    public function testCsvJoinsGroupsAlphabetically(): void
    {
        $finance = $this->save(null, ['B' . $this->suffix, 'A' . $this->suffix]);

        $csv = (new FinanceCsvExportService())->build([$finance->fresh(['groups', 'financeAccount'])]);

        $this->assertStringContainsString('A' . $this->suffix . '; B' . $this->suffix, $csv);
    }

    public function testGroupNamesAreSortedAlphabetically(): void
    {
        $finance = $this->save(null, ['reise' . $this->suffix, 'Konzert' . $this->suffix, 'Ausflug 2' . $this->suffix]);

        $this->assertSame(
            ['Ausflug 2' . $this->suffix, 'Konzert' . $this->suffix, 'reise' . $this->suffix],
            $finance->fresh()->groupNames()
        );
    }

    public function testOldJournalEntriesStillShowGroupNames(): void
    {
        $a = FinanceGroup::create(['name' => 'Alt' . $this->suffix]);
        $b = FinanceGroup::create(['name' => 'Neu' . $this->suffix]);

        $described = (new FinanceJournalService())->describeChanges([
            'finance_group_id' => ['from' => (string) $a->id, 'to' => (string) $b->id],
        ]);

        $this->assertSame('Alt' . $this->suffix, $described[0]['from']);
        $this->assertSame('Neu' . $this->suffix, $described[0]['to']);
    }

    /**
     * @param list<string> $groupNames
     */
    private function save(?int $id, array $groupNames, string $description = 'Gruppentest'): Finance
    {
        $finance = $this->bookings->save($id, [
            'invoice_date' => self::YEAR . '-03-01',
            'payment_date' => self::YEAR . '-03-01',
            'description' => $description,
            'type' => Finance::TYPE_EXPENSE,
            'amount' => '100.00',
            'finance_account_id' => $this->fixtureAccountId(),
            'payment_method' => Finance::PAYMENT_METHOD_BANK_TRANSFER,
        ], $groupNames, 1);
        $this->assertNotNull($finance);

        return $finance;
    }

    /**
     * @return array<string, mixed>
     */
    private function reportData(): array
    {
        $controller = $this->financeController(['view' => $this->createStub(Twig::class)]);
        $method = new \ReflectionMethod(FinanceController::class, 'buildReportData');

        return $method->invoke($controller, self::YEAR);
    }
}
