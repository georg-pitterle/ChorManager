<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Attachment;
use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Models\FinanceRevision;
use App\Models\Setting;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\BankStatementImportService;
use App\Services\BudgetService;
use App\Services\FinanceAccountService;
use App\Services\FinanceCsvExportService;
use App\Services\FinanceJournalService;
use App\Services\FinanceReportPdfService;
use App\Services\NameFormatterService;
use App\Services\Pdf\TcLibPdfCanvas;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Das Kassabuch zieht die Belege nicht mehr im Volltext durch den Speicher.
 *
 * `attachments.file_content` ist ein BLOB. Buchungsliste, CSV-Export und
 * Jahresbericht luden ihn mit, obwohl keine der drei Seiten den Inhalt anzeigt:
 * Die Liste zeigt Dateinamen, der Export zählt nur, der Bericht nennt Anhänge
 * gar nicht. Bei einem Geschäftsjahr mit Rechnungs-PDFs sind das schnell
 * zweistellige Megabytes je Seitenaufruf - und ein Export, der am
 * Speicherlimit endet.
 *
 * Geprüft wird an der abgesetzten Abfrage statt am Ergebnis: Ein `select *` auf
 * `attachments` holt den BLOB, gleich wie wenig danach davon gelesen wird.
 */
final class FinanceAttachmentBlobLoadFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private FinanceAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        FinanceRevision::query()->delete();
        Finance::query()->whereNotNull('reversal_of_id')->update(['reversal_of_id' => null]);
        Finance::query()->delete();
        FinanceAccount::query()->delete();
        Attachment::where('entity_type', 'finance')->delete();
        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.09.']);

        $this->account = FinanceAccount::create([
            'name' => 'Bankkonto',
            'type' => FinanceAccount::TYPE_BANK,
            'iban' => null,
            'opening_balance' => '0.00',
            'opening_date' => '2025-09-01',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $_SESSION = [
            'user_id' => 1,
            'can_manage_finances' => true,
            'can_read_finances' => true,
        ];
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

    public function testTheBookingListNeverSelectsTheAttachmentBlob(): void
    {
        $this->bookingWithReceipt();

        $statements = $this->attachmentStatementsDuring(function (): void {
            $request = $this->makeRequest('GET', '/finances', [], ['year' => '2025']);
            $this->financeController('/finances')->index($request, $this->makeResponse());
        });

        $this->assertNotSame([], $statements, 'Ohne Anhang-Abfrage prüft der Test nichts.');
        $this->assertNoBlobSelect($statements);
    }

    public function testTheBookingListStillShowsTheAttachmentName(): void
    {
        $this->bookingWithReceipt();

        $request = $this->makeRequest('GET', '/finances', [], ['year' => '2025']);
        $response = $this->financeController('/finances')->index($request, $this->makeResponse());

        $this->assertStringContainsString(
            'Rechnung Notenpult.pdf',
            (string) $response->getBody(),
            'Die Liste braucht Name, Typ und Größe des Anhangs weiterhin.'
        );
    }

    public function testTheCsvExportNeverSelectsTheAttachmentBlob(): void
    {
        $this->bookingWithReceipt();

        $statements = $this->attachmentStatementsDuring(function (): void {
            $request = $this->makeRequest('GET', '/finances/export', [], ['year' => '2025']);
            $this->financeController('/finances')->exportCsv($request, $this->makeResponse());
        });

        $this->assertNotSame([], $statements, 'Ohne Anhang-Abfrage prüft der Test nichts.');
        $this->assertNoBlobSelect($statements);
    }

    public function testTheCsvExportStillCountsTheAttachments(): void
    {
        $booking = $this->bookingWithReceipt();
        $this->receipt($booking, 'Zweiter Beleg.pdf');

        $request = $this->makeRequest('GET', '/finances/export', [], ['year' => '2025']);
        $response = $this->financeController('/finances')->exportCsv($request, $this->makeResponse());

        $lines = array_values(array_filter(explode("\n", (string) $response->getBody())));
        $row = (string) end($lines);

        $this->assertStringEndsWith('2', rtrim($row, "\r"), 'Die Spalte "Anhänge" zählt beide Belege.');
    }

    public function testTheAnnualReportDoesNotTouchTheAttachmentsAtAll(): void
    {
        $this->bookingWithReceipt();

        $statements = $this->attachmentStatementsDuring(function (): void {
            $request = $this->makeRequest('GET', '/finances/report.pdf', [], ['year' => '2025']);
            $this->financeController('/finances/report')->reportPdf($request, $this->makeResponse());
        });

        $this->assertSame(
            [],
            $statements,
            'Der Jahresbericht nennt keinen Anhang; jede Abfrage darauf ist verschenkt.'
        );
    }

    /**
     * Die SQL-Anweisungen, die während des Aufrufs auf `attachments` gingen.
     *
     * @return list<string>
     */
    private function attachmentStatementsDuring(callable $run): array
    {
        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $run();
            $log = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        return array_values(array_filter(
            array_map(static fn(array $entry): string => (string) ($entry['query'] ?? ''), $log),
            static fn(string $sql): bool => str_contains($sql, '`attachments`')
        ));
    }

    /**
     * @param list<string> $statements
     */
    private function assertNoBlobSelect(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->assertStringNotContainsString(
                'select * from `attachments`',
                $sql,
                'Eine Abfrage ohne Spaltenliste holt den BLOB mit: ' . $sql
            );
            $this->assertStringNotContainsString(
                'file_content',
                $sql,
                'Der Datei-Inhalt gehört in keine Übersicht: ' . $sql
            );
        }
    }

    private function bookingWithReceipt(): Finance
    {
        $booking = Finance::create([
            'running_number' => 7001,
            'invoice_date' => '2025-10-05',
            'payment_date' => '2025-10-05',
            'description' => 'Notenpult',
            'group_name' => 'Anschaffung',
            'finance_group_id' => null,
            'type' => 'expense',
            'amount' => '120.00',
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $this->account->id,
        ]);

        $this->receipt($booking, 'Rechnung Notenpult.pdf');

        return $booking;
    }

    private function receipt(Finance $booking, string $name): Attachment
    {
        // Bewusst kein Kurzinhalt: Genau die Größe ist der Grund, warum der BLOB
        // in keine Übersicht gehört.
        $content = str_repeat('%PDF-1.4 Beleg ', 20000);

        return Attachment::create([
            'entity_type' => 'finance',
            'entity_id' => $booking->id,
            'filename' => bin2hex(random_bytes(16)) . '_' . $name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($content),
            'file_content' => $content,
        ]);
    }

    private function financeController(string $currentPath): FinanceController
    {
        return new FinanceController(
            $this->createFinanceTwig($currentPath),
            new BudgetService(),
            new NullLogger(),
            new FinanceReportPdfService(new TcLibPdfCanvas()),
            new BankStatementImportService(new NullLogger()),
            new FinanceAccountService(),
            new FinanceJournalService(),
            new FinanceCsvExportService()
        );
    }

    private function createFinanceTwig(string $currentPath): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $modules = ['modules' => ['finance' => true, 'budget' => true]];

        $environment->addFilter(new TwigFilter(
            'person_name',
            static fn(mixed $person): string => (new NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', $currentPath);
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('csrf_token', 'test-token');
        $environment->addGlobal('settings', $modules);
        $this->registerMailBadgeStub($environment);
        $this->registerAttachmentPreviewStub($environment);
        $environment->addFunction(new TwigFunction('asset_path', static fn(string $path): string => $path));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = '') use ($currentPath, $modules): array {
                $context = NavigationContext::fromSession($_SESSION, $modules, $currentPath, $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return $twig;
    }
}
