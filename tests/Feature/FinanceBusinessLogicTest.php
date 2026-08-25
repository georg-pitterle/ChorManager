<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Attachment;
use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Models\FinanceGroup;
use App\Models\Setting;
use App\Services\BankStatementImportService;
use App\Services\BudgetService;
use App\Services\FinanceAccountService;
use App\Services\FinanceCsvExportService;
use App\Services\FinanceJournalService;
use App\Services\FinanceReportPdfService;
use App\Services\Pdf\TcLibPdfCanvas;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response as SlimResponse;
use Slim\Psr7\UploadedFile;
use Slim\Views\Twig;

/**
 * Behavioural coverage for FinanceController business logic fixes:
 * amount validation, group-name edge cases, running-number integrity,
 * transactional delete, and exception logging.
 */
final class FinanceBusinessLogicTest extends TestCase
{
    use TestHttpHelpers;
    use FinanceAccountFixture;

    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$capsule?->connection()->beginTransaction();

        // Ohne Bargeldkonto findet der Controller kein Ziel für die Buchung und bricht mit einer
        // Meldung ab, statt zu speichern. Auf einer frisch aufgesetzten Datenbank gibt es keins,
        // deshalb legt der Test es selbst an, statt auf Seed-Daten zu hoffen.
        FinanceAccount::firstOrCreate(
            ['type' => FinanceAccount::TYPE_CASH, 'name' => 'Kassa (Test)'],
            [
                'opening_balance' => 0,
                'opening_date' => '2020-01-01',
                'is_active' => true,
                'sort_order' => 0,
            ]
        );
    }

    protected function tearDown(): void
    {
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function makeController(?LoggerInterface $logger = null): FinanceController
    {
        $view = $this->createStub(Twig::class);

        return new FinanceController(
            $view,
            new BudgetService(),
            $logger ?? new NullLogger(),
            new FinanceReportPdfService(new TcLibPdfCanvas()),
            new BankStatementImportService(new NullLogger()),
            new FinanceAccountService(),
            new FinanceJournalService(),
            new FinanceCsvExportService()
        );
    }

    private function baseFinanceData(array $overrides = []): array
    {
        return array_merge([
            'invoice_date' => '2025-10-01',
            'payment_date' => '',
            'description' => 'Test booking',
            'group_name' => '',
            'type' => 'income',
            'amount' => '100,00',
            'payment_method' => 'cash',
        ], $overrides);
    }

    public function testSaveRejectsNegativeAmount(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData(['amount' => '-50,00']));

        $countBefore = Finance::count();
        $controller->save($request, $this->makeResponse());

        $this->assertSame($countBefore, Finance::count(), 'A negative amount must not create a booking.');
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['error']);
    }

    public function testSaveRejectsZeroAmount(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData(['amount' => '0']));

        $countBefore = Finance::count();
        $controller->save($request, $this->makeResponse());

        $this->assertSame($countBefore, Finance::count(), 'A zero amount must not create a booking.');
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['error']);
    }

    public function testSaveRejectsNonNumericAmount(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData(['amount' => 'abc']));

        $countBefore = Finance::count();
        $controller->save($request, $this->makeResponse());

        $this->assertSame($countBefore, Finance::count(), 'A non-numeric amount must not create a booking.');
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['error']);
    }

    public function testNormalizeAmountInputHandlesMultiCommaGrouping(): void
    {
        $this->assertSame('1234567', FinanceController::normalizeAmountInput('1,234,567'));
        $this->assertSame('1234.56', FinanceController::normalizeAmountInput('1,234.56'));
    }

    public function testSaveTreatsGroupNameZeroAsValidValue(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData(['group_name' => '0']));

        $controller->save($request, $this->makeResponse());

        $finance = Finance::orderByDesc('id')->first();
        $this->assertNotNull($finance);
        $this->assertSame('0', $finance->group_name);
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testSaveRejectsPaymentDateBeforeInvoiceDate(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData([
            'invoice_date' => '2025-10-10',
            'payment_date' => '2025-10-01',
        ]));

        $countBefore = Finance::count();
        $controller->save($request, $this->makeResponse());

        $this->assertSame($countBefore, Finance::count(), 'A payment date before the invoice date must be rejected.');
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['error']);
    }

    /**
     * Die Datumsfelder wurden bisher nur als Zeichenkette verglichen. Ein leeres
     * oder unsinniges Rechnungsdatum lief damit bis in die Datenbank durch und
     * landete bei nicht-striktem SQL-Modus als 0000-00-00 in den Büchern.
     */
    public function testSaveRejectsAMissingInvoiceDate(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData(['invoice_date' => '']));

        $countBefore = Finance::count();
        $controller->save($request, $this->makeResponse());

        $this->assertSame($countBefore, Finance::count(), 'Ohne Rechnungsdatum darf keine Buchung entstehen.');
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['error']);
    }

    public function testSaveRejectsAMalformedInvoiceDate(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData([
            'invoice_date' => '01.10.2025',
        ]));

        $countBefore = Finance::count();
        $controller->save($request, $this->makeResponse());

        $this->assertSame($countBefore, Finance::count(), 'Ein Datum im falschen Format darf nicht durchlaufen.');
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['error']);
    }

    public function testSaveRejectsACalendricallyImpossibleDate(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData([
            'invoice_date' => '2025-02-30',
        ]));

        $countBefore = Finance::count();
        $controller->save($request, $this->makeResponse());

        $this->assertSame($countBefore, Finance::count(), 'Den 30. Februar gibt es nicht.');
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['error']);
    }

    public function testSaveRejectsAMalformedPaymentDate(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData([
            'invoice_date' => '2025-10-01',
            'payment_date' => 'heute',
        ]));

        $countBefore = Finance::count();
        $controller->save($request, $this->makeResponse());

        $this->assertSame($countBefore, Finance::count(), 'Ein unlesbares Zahldatum darf nicht durchlaufen.');
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['error']);
    }

    /**
     * Die Beschreibung war nur im Formular als Pflichtfeld markiert. Wer den
     * Request an der Oberfläche vorbei schickt, hat damit eine Buchung ohne
     * jeden Text angelegt - im Kassabuch nicht mehr zuordenbar.
     */
    public function testSaveRejectsAnEmptyDescription(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData([
            'description' => '   ',
        ]));

        $countBefore = Finance::count();
        $controller->save($request, $this->makeResponse());

        $this->assertSame($countBefore, Finance::count(), 'Ohne Beschreibung darf keine Buchung entstehen.');
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['error']);
    }

    public function testSaveRejectsAMissingDescriptionOnUpdate(): void
    {
        $controller = $this->makeController();
        $controller->save($this->makeRequest('POST', '/finances/save', $this->baseFinanceData()), $this->makeResponse());
        unset($_SESSION['success'], $_SESSION['error']);

        $finance = Finance::orderByDesc('id')->firstOrFail();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData([
            'id' => (string) $finance->id,
            'description' => '',
        ]));

        $controller->save($request, $this->makeResponse());

        $this->assertSame('Test booking', $finance->fresh()?->description);
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['error']);
    }

    public function testSaveAcceptsAValidDatePair(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData([
            'invoice_date' => '2025-10-01',
            'payment_date' => '2025-10-05',
        ]));

        $controller->save($request, $this->makeResponse());

        $finance = Finance::orderByDesc('id')->first();
        $this->assertNotNull($finance);
        $this->assertSame('2025-10-05', $finance->payment_date?->format('Y-m-d'));
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testRunningNumberIsNeverReusedAfterDeletingHighestEntry(): void
    {
        $controller = $this->makeController();

        $controller->save($this->makeRequest('POST', '/finances/save', $this->baseFinanceData()), $this->makeResponse());
        $first = Finance::orderByDesc('id')->first();
        unset($_SESSION['success'], $_SESSION['error']);

        $controller->save($this->makeRequest('POST', '/finances/save', $this->baseFinanceData()), $this->makeResponse());
        $second = Finance::orderByDesc('id')->first();
        unset($_SESSION['success'], $_SESSION['error']);

        $this->assertSame($first->running_number + 1, $second->running_number);

        $controller->reverse(
            $this->makeRequest('POST', '/finances/' . $second->id . '/reverse'),
            $this->makeResponse(),
            ['id' => (string) $second->id]
        );
        $reversal = Finance::orderByDesc('id')->first();
        unset($_SESSION['success'], $_SESSION['error']);

        $controller->save($this->makeRequest('POST', '/finances/save', $this->baseFinanceData()), $this->makeResponse());
        $third = Finance::orderByDesc('id')->first();
        unset($_SESSION['success'], $_SESSION['error']);

        $this->assertGreaterThan(
            $reversal->running_number,
            $third->running_number,
            'A running number must never be reused, not even after a reversal.'
        );
    }

    public function testReversalKeepsTheOriginalBookingAndItsAttachments(): void
    {
        $controller = $this->makeController();
        $controller->save($this->makeRequest('POST', '/finances/save', $this->baseFinanceData()), $this->makeResponse());
        $finance = Finance::orderByDesc('id')->first();
        unset($_SESSION['success'], $_SESSION['error']);

        Attachment::create([
            'entity_type' => 'finance',
            'entity_id' => $finance->id,
            'filename' => 'test.txt',
            'original_name' => 'test.txt',
            'mime_type' => 'text/plain',
            'file_size' => 4,
            'file_content' => 'test',
        ]);

        $controller->reverse(
            $this->makeRequest('POST', '/finances/' . $finance->id . '/reverse'),
            $this->makeResponse(),
            ['id' => (string) $finance->id]
        );

        // Belegprinzip: Buchung und Beleg bleiben erhalten, korrigiert wird über
        // die Gegenbuchung.
        $this->assertNotNull(Finance::find($finance->id));
        $this->assertSame(1, Attachment::where('entity_type', 'finance')->where('entity_id', $finance->id)->count());
        $this->assertSame(1, Finance::where('reversal_of_id', $finance->id)->count());
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testSaveLogsExceptionWithEventContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->isString(),
                $this->callback(function (array $context): bool {
                    return ($context['event'] ?? null) === 'finance.save.failed'
                        && array_key_exists('exception', $context);
                })
            );

        $controller = $this->makeController($logger);
        // Eine zu lange Beschreibung (Spalte: varchar(255)) lässt das Insert im
        // try-Block scheitern. Ein leeres Rechnungsdatum taugt dafür nicht mehr:
        // Das wird jetzt validiert, bevor es überhaupt in die Datenbank geht.
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData([
            'description' => str_repeat('x', 300),
        ]));

        $controller->save($request, $this->makeResponse());
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testReversingAnUnknownBookingReportsAnError(): void
    {
        $controller = $this->makeController();

        $controller->reverse(
            $this->makeRequest('POST', '/finances/999999999/reverse'),
            $this->makeResponse(),
            ['id' => '999999999']
        );

        $this->assertStringContainsString('nicht gefunden', (string) $_SESSION['error']);
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testUpdateSettingsRejectsOutOfRangeDayAndMonth(): void
    {
        $controller = $this->makeController();
        $before = Setting::find('fiscal_year_start')?->setting_value;

        $controller->updateSettings(
            $this->makeRequest('POST', '/finances/settings', ['fiscal_year_start' => '45.19.']),
            $this->makeResponse()
        );

        $this->assertArrayHasKey('error', $_SESSION);
        $after = Setting::find('fiscal_year_start')?->setting_value;
        $this->assertSame($before, $after, 'An out-of-range fiscal day/month must not be persisted.');
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testIndexListsGroupsFromFinanceGroupTableNotJustBookedFinances(): void
    {
        FinanceGroup::create(['name' => 'TEST_OnlyInBudget_' . uniqid()]);

        $controller = $this->makeController();
        $view = new class extends Twig {
            public array $captured = [];

            public function __construct()
            {
            }

            public function render($response, $template, array $data = []): \Psr\Http\Message\ResponseInterface
            {
                $this->captured = $data;
                return $response;
            }
        };

        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('view');
        $property->setValue($controller, $view);

        $controller->index($this->makeRequest('GET', '/finances'), $this->makeResponse());

        $groupNames = collect($view->captured['groups'] ?? [])->values()->all();
        $this->assertContains(
            FinanceGroup::orderByDesc('id')->first()->name,
            $groupNames,
            'Groups that only exist via the budget module must also appear in the Kassa group list.'
        );
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testSaveLogsUploadRejectedForOversizedAttachmentWithoutFilename(): void
    {
        $handlerLog = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handlerLog);
        $controller = $this->makeController($logger);

        $oversizedContent = str_repeat('x', (10 * 1024 * 1024) + 1);
        $stream = (new StreamFactory())->createStream($oversizedContent);
        $uploadedFile = new UploadedFile(
            $stream,
            'geheimer-beleg.pdf',
            'application/pdf',
            strlen($oversizedContent),
            UPLOAD_ERR_OK
        );

        $request = $this->makeRequest('POST', '/finances', $this->baseFinanceData())
            ->withUploadedFiles(['attachments' => [$uploadedFile]]);

        $controller->save($request, $this->makeResponse());

        $records = $handlerLog->getRecords();
        $match = array_values(array_filter(
            $records,
            static fn ($record): bool => ($record->context['event'] ?? null) === 'security.upload.rejected'
        ));

        $this->assertNotEmpty($match);
        $this->assertSame('size_exceeded', $match[0]->context['reason']);

        foreach ($records as $record) {
            $this->assertStringNotContainsString('geheimer-beleg', (string) json_encode($record->context));
        }

        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testBuildReportDataAggregatesIncomeExpenseAndBalance(): void
    {
        $controller = $this->makeController();
        $method = new \ReflectionMethod($controller, 'buildReportData');

        // Dev-Datenbank enthält bereits Bestandsbuchungen im Fiskaljahr 2025;
        // daher Vorher/Nachher-Differenz statt absoluter Summen prüfen
        // (gleiche Konvention wie z. B. testSaveRejectsNegativeAmount oben).
        $before = $method->invoke($controller, 2025);
        $financesCountBefore = $before['finances']->count();

        Finance::create([
            'running_number' => 9001, 'invoice_date' => '2025-10-05', 'payment_date' => '2025-10-05',
            'description' => 'Einnahme A', 'group_name' => null, 'finance_group_id' => null,
            'type' => 'income', 'amount' => '300.00', 'payment_method' => 'cash',
            'finance_account_id' => $this->fixtureAccountId(),
        ]);
        Finance::create([
            'running_number' => 9002, 'invoice_date' => '2025-11-05', 'payment_date' => '2025-11-05',
            'description' => 'Ausgabe B', 'group_name' => null, 'finance_group_id' => null,
            'type' => 'expense', 'amount' => '120.00', 'payment_method' => 'bank_transfer',
            'finance_account_id' => $this->fixtureAccountId(),
        ]);

        $data = $method->invoke($controller, 2025);

        $this->assertEqualsWithDelta(
            300.0,
            $data['total_income'] - $before['total_income'],
            0.001
        );
        $this->assertEqualsWithDelta(
            120.0,
            $data['total_expense'] - $before['total_expense'],
            0.001
        );
        $this->assertEqualsWithDelta(
            180.0,
            $data['balance'] - $before['balance'],
            0.001
        );
        $this->assertGreaterThanOrEqual($financesCountBefore + 2, $data['finances']->count());
        unset($_SESSION['success'], $_SESSION['error']);
    }
}
