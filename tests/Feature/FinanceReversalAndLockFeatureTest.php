<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Finance;
use App\Models\FinanceRevision;
use App\Models\Setting;
use App\Services\FinanceJournalService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Storno aus einem abgeschlossenen Zeitraum und das Protokoll des
 * Buchungsabschlusses.
 *
 * Das Storno wanderte pauschal auf "heute". Liegt der Abschluss-Stichtag in der
 * Zukunft, ist "heute" selbst gesperrt - die Gegenbuchung landete dann mitten im
 * abgeschlossenen Zeitraum. Und der Abschluss selbst war nur im Anwendungslog
 * vermerkt: im Prüfjournal, das der Rechnungsprüfer liest, fehlte er.
 */
final class FinanceReversalAndLockFeatureTest extends TestCase
{
    private FinanceJournalService $journal;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->journal = new FinanceJournalService();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheFirstOpenBookingDayFollowsTheClosingDate(): void
    {
        $this->journal->setClosedUntil(Carbon::now()->addDays(10)->format('Y-m-d'));

        $this->assertSame(
            Carbon::now()->addDays(11)->format('Y-m-d'),
            $this->journal->firstOpenBookingDay(),
            'Der erste offene Tag liegt hinter dem Stichtag, auch wenn der in der Zukunft steht.'
        );
    }

    public function testTodayIsTheFirstOpenBookingDayWhenTheClosingDateIsInThePast(): void
    {
        $this->journal->setClosedUntil(Carbon::now()->subMonth()->format('Y-m-d'));

        $this->assertSame(Carbon::now()->format('Y-m-d'), $this->journal->firstOpenBookingDay());
    }

    public function testTodayIsTheFirstOpenBookingDayWithoutAnyClosingDate(): void
    {
        Setting::where('setting_key', FinanceJournalService::CLOSED_UNTIL_KEY)->delete();

        $this->assertSame(Carbon::now()->format('Y-m-d'), $this->journal->firstOpenBookingDay());
    }

    public function testChangingTheClosingDateIsRecordedInTheJournal(): void
    {
        $this->journal->setClosedUntil('2026-06-30');
        $before = FinanceRevision::max('id') ?? 0;

        $this->journal->recordLockChange('2026-06-30', '2025-12-31', 42);

        $revision = FinanceRevision::where('id', '>', $before)->orderBy('id')->first();

        $this->assertNotNull($revision, 'Der Buchungsabschluss gehört ins Prüfjournal.');
        $this->assertSame(FinanceRevision::ACTION_LOCK, (string) $revision->action);
        $this->assertNull($revision->finance_id, 'Der Abschluss hängt an keiner einzelnen Buchung.');
        $this->assertSame(42, (int) $revision->user_id);
        $this->assertSame(
            ['closed_until' => ['from' => '2026-06-30', 'to' => '2025-12-31']],
            $revision->changeSet()
        );
    }

    public function testAnUnchangedClosingDateLeavesNoJournalEntry(): void
    {
        $before = FinanceRevision::max('id') ?? 0;

        $this->journal->recordLockChange('2026-06-30', '2026-06-30', 42);

        $this->assertSame(
            $before,
            (int) (FinanceRevision::max('id') ?? 0),
            'Ohne Unterschied wird nichts protokolliert.'
        );
    }

    public function testTheJournalDescribesTheClosingDateChangeReadably(): void
    {
        $described = $this->journal->describeChanges([
            'closed_until' => ['from' => '2026-06-30', 'to' => null],
        ]);

        $this->assertSame('Buchungsabschluss', $described[0]['label']);
        $this->assertSame('30.06.2026', $described[0]['from']);
        $this->assertNull($described[0]['to']);
    }
}
