<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Attachment;
use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Models\FinanceRevision;
use App\Models\Setting;
use App\Services\BankStatementImportService;
use App\Services\BudgetService;
use App\Services\FinanceAccountService;
use App\Services\FinanceCsvExportService;
use App\Services\FinanceJournalService;
use App\Services\FinanceReportPdfService;
use App\Services\Pdf\TcLibPdfCanvas;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

final class FinanceRevisionFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private FinanceController $controller;
    private FinanceJournalService $journal;
    private FinanceAccount $account;
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        FinanceRevision::query()->delete();
        Finance::query()->update(['reversal_of_id' => null]);
        Finance::query()->delete();
        FinanceAccount::query()->delete();
        Setting::where('setting_key', FinanceJournalService::CLOSED_UNTIL_KEY)->delete();

        $this->account = FinanceAccount::create([
            'name' => 'Bankkonto',
            'type' => FinanceAccount::TYPE_BANK,
            'iban' => null,
            'opening_balance' => '0.00',
            'opening_date' => '2025-01-01',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->journal = new FinanceJournalService();
        $this->renderCalls = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data = []): ResponseInterface {
                $this->renderCalls[] = [$template, $data];
                return $response;
            }
        );

        $this->controller = new FinanceController(
            $twig,
            new BudgetService(),
            new NullLogger(),
            new FinanceReportPdfService(new TcLibPdfCanvas()),
            new BankStatementImportService(new NullLogger()),
            new FinanceAccountService(),
            $this->journal,
            new FinanceCsvExportService()
        );

        $_SESSION = ['user_id' => 42];
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

    /** @param array<string, mixed> $overrides */
    private function save(array $overrides = []): ResponseInterface
    {
        $body = array_merge([
            'invoice_date' => '2026-03-01',
            'payment_date' => '2026-03-05',
            'description' => 'Notenkauf',
            'group_name' => '',
            'type' => 'expense',
            'amount' => '120,00',
            'finance_account_id' => (string) $this->account->id,
        ], $overrides);

        return $this->controller->save(
            $this->makeRequest('POST', '/finances/save', $body),
            $this->makeResponse()
        );
    }

    private function latestBooking(): Finance
    {
        return Finance::orderBy('id', 'desc')->firstOrFail();
    }

    public function testCreatingABookingWritesACreateRevision(): void
    {
        $this->save();
        $booking = $this->latestBooking();

        $revision = FinanceRevision::where('finance_id', $booking->id)->firstOrFail();
        $this->assertSame(FinanceRevision::ACTION_CREATE, $revision->action);
        $this->assertSame(42, $revision->user_id);
    }

    public function testUpdatingABookingRecordsBeforeAndAfterValues(): void
    {
        $this->save();
        $booking = $this->latestBooking();

        $this->save(['id' => (string) $booking->id, 'amount' => '150,00', 'description' => 'Notenkauf korrigiert']);

        $revision = FinanceRevision::where('finance_id', $booking->id)
            ->where('action', FinanceRevision::ACTION_UPDATE)
            ->firstOrFail();
        $changes = $revision->changeSet();

        $this->assertSame('120', $changes['amount']['from']);
        $this->assertSame('150', $changes['amount']['to']);
        $this->assertSame('Notenkauf', $changes['description']['from']);
        $this->assertSame('Notenkauf korrigiert', $changes['description']['to']);
    }

    public function testUnchangedSaveDoesNotWriteARevision(): void
    {
        $this->save();
        $booking = $this->latestBooking();

        $this->save(['id' => (string) $booking->id]);

        $this->assertSame(
            0,
            FinanceRevision::where('finance_id', $booking->id)
                ->where('action', FinanceRevision::ACTION_UPDATE)
                ->count()
        );
    }

    public function testReversalCreatesACounterBookingAndKeepsTheOriginal(): void
    {
        $this->save();
        $original = $this->latestBooking();

        $response = $this->controller->reverse(
            $this->makeRequest('POST', '/finances/' . $original->id . '/reverse'),
            $this->makeResponse(),
            ['id' => (string) $original->id]
        );

        $this->assertRedirect($response, '/finances');

        $original->refresh();
        $this->assertNotNull($original->id, 'Das Original darf nicht gelöscht werden.');

        $reversal = Finance::where('reversal_of_id', $original->id)->firstOrFail();
        $this->assertSame('income', $reversal->type, 'Die Gegenbuchung dreht die Richtung um.');
        $this->assertSame((string) $original->amount, (string) $reversal->amount);
        $this->assertSame($original->finance_account_id, $reversal->finance_account_id);
        $this->assertStringContainsString('Storno', $reversal->description);
        $this->assertStringContainsString((string) $original->running_number, $reversal->description);
        $this->assertGreaterThan($original->running_number, $reversal->running_number);
    }

    public function testReversalNeutralizesTheBalance(): void
    {
        $this->save();
        $original = $this->latestBooking();
        $accountService = new FinanceAccountService();

        $before = $accountService->balanceAt($this->account, \Carbon\Carbon::parse('2026-12-31'));
        $this->assertSame(-120.0, $before);

        $this->controller->reverse(
            $this->makeRequest('POST', '/finances/' . $original->id . '/reverse'),
            $this->makeResponse(),
            ['id' => (string) $original->id]
        );

        $after = $accountService->balanceAt($this->account, \Carbon\Carbon::parse('2026-12-31'));
        $this->assertSame(0.0, $after);
    }

    public function testABookingCannotBeReversedTwice(): void
    {
        $this->save();
        $original = $this->latestBooking();

        $this->controller->reverse(
            $this->makeRequest('POST', '/finances/' . $original->id . '/reverse'),
            $this->makeResponse(),
            ['id' => (string) $original->id]
        );
        $this->controller->reverse(
            $this->makeRequest('POST', '/finances/' . $original->id . '/reverse'),
            $this->makeResponse(),
            ['id' => (string) $original->id]
        );

        $this->assertSame(1, Finance::where('reversal_of_id', $original->id)->count());
        $this->assertStringContainsString('storniert', (string) $_SESSION['error']);
    }

    public function testAnInvalidBookingTypeIsRejected(): void
    {
        $this->save(['type' => 'geschenk']);

        $this->assertSame(0, Finance::count());
        $this->assertStringContainsString('Buchungsart', (string) $_SESSION['error']);
    }

    public function testAPaymentBeforeTheAccountOpeningDateIsRejected(): void
    {
        // Konto-Stichtag ist der 01.01.2025; frühere Zahlungen stecken schon im
        // Anfangsbestand und würden den Kassabericht doppelt belasten.
        $this->save(['invoice_date' => '2024-06-01', 'payment_date' => '2024-06-01']);

        $this->assertSame(0, Finance::count());
        $this->assertStringContainsString('Stichtag', (string) $_SESSION['error']);
    }

    public function testLockedPeriodsRejectChanges(): void
    {
        $this->save();
        $booking = $this->latestBooking();
        $this->journal->setClosedUntil('2026-06-30');

        $this->save(['id' => (string) $booking->id, 'amount' => '999,00']);

        $booking->refresh();
        $this->assertSame('120.00', (string) $booking->amount);
        $this->assertStringContainsString('abgeschlossen', (string) $_SESSION['error']);
    }

    public function testLockedPeriodsRejectNewBookingsInThatPeriod(): void
    {
        $this->journal->setClosedUntil('2026-06-30');

        $this->save(['payment_date' => '2026-05-01']);

        $this->assertSame(0, Finance::count());
        $this->assertStringContainsString('abgeschlossen', (string) $_SESSION['error']);
    }

    public function testBookingsAfterTheLockAreStillEditable(): void
    {
        $this->journal->setClosedUntil('2026-06-30');

        $this->save(['payment_date' => '2026-07-01']);

        $this->assertSame(1, Finance::count());
        $this->assertArrayNotHasKey('error', $_SESSION);
    }

    public function testOpenItemsAreNeverLocked(): void
    {
        $this->journal->setClosedUntil('2030-12-31');

        $this->save(['payment_date' => '']);

        $this->assertSame(1, Finance::count());
    }

    public function testReversingInsideALockedPeriodBooksOnToday(): void
    {
        $this->save(['payment_date' => '2026-05-01']);
        $original = $this->latestBooking();
        $this->journal->setClosedUntil('2026-06-30');

        $this->controller->reverse(
            $this->makeRequest('POST', '/finances/' . $original->id . '/reverse'),
            $this->makeResponse(),
            ['id' => (string) $original->id]
        );

        $reversal = Finance::where('reversal_of_id', $original->id)->firstOrFail();
        // Der gesperrte Zeitraum darf sich nicht mehr ändern, das Storno wandert
        // daher auf den heutigen Tag.
        $this->assertSame(
            \Carbon\Carbon::now()->format('Y-m-d'),
            $reversal->payment_date->format('Y-m-d')
        );
    }

    public function testReversingOutsideALockedPeriodKeepsTheOriginalDate(): void
    {
        $this->save(['payment_date' => '2026-07-01']);
        $original = $this->latestBooking();

        $this->controller->reverse(
            $this->makeRequest('POST', '/finances/' . $original->id . '/reverse'),
            $this->makeResponse(),
            ['id' => (string) $original->id]
        );

        $reversal = Finance::where('reversal_of_id', $original->id)->firstOrFail();
        $this->assertSame('2026-07-01', $reversal->payment_date->format('Y-m-d'));
    }

    public function testJournalTranslatesFieldNamesAndValuesIntoPlainGerman(): void
    {
        $described = $this->journal->describeChanges([
            'amount' => ['from' => '120', 'to' => '150.5'],
            'type' => ['from' => 'expense', 'to' => 'income'],
            'payment_method' => ['from' => 'bank_transfer', 'to' => 'cash'],
            'payment_date' => ['from' => null, 'to' => '2026-03-05'],
            'finance_account_id' => ['from' => null, 'to' => (string) $this->account->id],
        ]);

        $byLabel = [];
        foreach ($described as $change) {
            $byLabel[$change['label']] = $change;
        }

        $this->assertSame(
            ['Betrag', 'Art', 'Zahlungsart', 'Zahldatum', 'Konto'],
            array_keys($byLabel),
            'Die Datenbankspalten dürfen nicht mehr durchschlagen.'
        );
        $this->assertSame('120,00 €', $byLabel['Betrag']['from']);
        $this->assertSame('150,50 €', $byLabel['Betrag']['to']);
        $this->assertSame('Ausgang', $byLabel['Art']['from']);
        $this->assertSame('Eingang', $byLabel['Art']['to']);
        $this->assertSame('Überweisung', $byLabel['Zahlungsart']['from']);
        $this->assertSame('Bar', $byLabel['Zahlungsart']['to']);
        $this->assertSame('05.03.2026', $byLabel['Zahldatum']['to']);
        // Fremdschlüssel werden zum Kontonamen aufgelöst, nicht als ID gezeigt.
        $this->assertSame('Bankkonto', $byLabel['Konto']['to']);
        // Ein fehlender Vorher-Wert bleibt null; das Template zeigt dafür "leer".
        $this->assertNull($byLabel['Konto']['from']);
    }

    public function testJournalKeepsTheIdOfADeletedAccountReadable(): void
    {
        $described = $this->journal->describeChanges([
            'finance_account_id' => ['from' => '999999', 'to' => (string) $this->account->id],
        ]);

        $this->assertSame('Konto #999999', $described[0]['from']);
    }

    public function testJournalPageListsRevisionsNewestFirst(): void
    {
        $this->save();
        $booking = $this->latestBooking();
        $this->save(['id' => (string) $booking->id, 'amount' => '150,00']);

        $this->controller->journal($this->makeRequest('GET', '/finances/journal'), $this->makeResponse());

        $data = end($this->renderCalls)[1];
        $this->assertSame('finances/journal.twig', end($this->renderCalls)[0]);
        $this->assertCount(2, $data['revisions']);
        $this->assertSame(FinanceRevision::ACTION_UPDATE, $data['revisions'][0]->action);
    }

    /**
     * Der Beleg gehört zur Buchung. Ist deren Zeitraum abgeschlossen, darf er
     * nicht mehr verschwinden - sonst fehlt der Nachweis zu einer geprüften Zahl.
     */
    public function testAttachmentsOfALockedPeriodCannotBeDeleted(): void
    {
        $this->save();
        $booking = $this->latestBooking();
        $attachment = $this->attachTo($booking);

        $this->journal->setClosedUntil('2026-06-30');

        $this->controller->deleteAttachment(
            $this->makeRequest('POST', '/finances/attachments/' . $attachment->id . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(1, Attachment::where('id', $attachment->id)->count());
        $this->assertStringContainsString('abgeschlossen', (string) $_SESSION['error']);
    }

    public function testDeletingAnAttachmentIsRecordedInTheJournal(): void
    {
        $this->save();
        $booking = $this->latestBooking();
        $attachment = $this->attachTo($booking);

        $this->controller->deleteAttachment(
            $this->makeRequest('POST', '/finances/attachments/' . $attachment->id . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(0, Attachment::where('id', $attachment->id)->count());

        $revision = FinanceRevision::where('finance_id', $booking->id)
            ->where('action', FinanceRevision::ACTION_UPDATE)
            ->firstOrFail();
        $changes = $revision->changeSet();

        $this->assertSame('beleg.pdf', $changes['attachment']['from']);
        $this->assertNull($changes['attachment']['to']);
        $this->assertSame(42, $revision->user_id);
    }

    private function attachTo(Finance $booking): Attachment
    {
        return Attachment::create([
            'entity_type' => 'finance',
            'entity_id' => $booking->id,
            'filename' => 'beleg.pdf',
            'original_name' => 'beleg.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 4,
            'file_content' => 'test',
        ]);
    }

    /**
     * Der Buchungsabschluss lässt sich zurückdatieren und öffnet damit einen
     * bereits geprüften Zeitraum wieder. Wer das wann getan hat, muss
     * nachvollziehbar bleiben.
     */
    public function testChangingTheBookingLockIsLogged(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = new FinanceController(
            $this->createStub(Twig::class),
            new BudgetService(),
            $logger,
            new FinanceReportPdfService(new TcLibPdfCanvas()),
            new BankStatementImportService(new NullLogger()),
            new FinanceAccountService(),
            $this->journal,
            new FinanceCsvExportService()
        );

        $this->journal->setClosedUntil('2026-06-30');

        $controller->updateSettings(
            $this->makeRequest('POST', '/finances/settings', [
                'fiscal_year_start' => '01.09.',
                'closed_until' => '2026-03-31',
            ]),
            $this->makeResponse()
        );

        $record = $this->recordFor($handler, 'finance.closed_until.changed');
        $this->assertNotNull($record, 'Eine Änderung des Buchungsabschlusses muss protokolliert werden.');
        $this->assertSame('2026-06-30', $record->context['from']);
        $this->assertSame('2026-03-31', $record->context['to']);
        $this->assertSame(42, $record->context['user_id']);
    }

    public function testDeleteRouteIsGoneInFavourOfReversal(): void
    {
        $this->assertFalse(method_exists(FinanceController::class, 'delete'));
        $this->assertTrue(method_exists(FinanceController::class, 'reverse'));

        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');
        $this->assertStringContainsString("'/finances/{id:[0-9]+}/reverse'", $routes);
        $this->assertStringNotContainsString("'/finances/{id:[0-9]+}/delete'", $routes);
        $this->assertStringContainsString("'/finances/journal'", $routes);
    }
}
