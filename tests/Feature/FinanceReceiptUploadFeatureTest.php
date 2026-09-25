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
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Der Beleg einer Buchung, hochgeladen über POST /finances/save.
 *
 * Diese Wege waren bisher nur über den Quelltext abgesichert: Ein Test suchte die
 * Zeichenkette "'file_size' => $size" in FinanceController. Das hielt nicht fest,
 * dass die Größe auch ankommt, und überlebte die Umstellung auf
 * EntityAttachmentService nicht - obwohl die Größe dort genauso geschrieben wird.
 *
 * Mitgeprüft wird das Namensschema: Der Ablagename trägt seit der Umstellung ein
 * Zufallspräfix, wie bei jedem anderen Anhang. Vorher stand im Kassabuch derselbe
 * Name in beiden Spalten, und zwei Belege gleichen Namens waren in der Ablage
 * nicht zu unterscheiden.
 */
final class FinanceReceiptUploadFeatureTest extends TestCase
{
    use TestHttpHelpers;

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
        Attachment::where('entity_type', FinanceController::ENTITY_TYPE)->delete();
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

    public function testAReceiptIsStoredWithItsSizeUnderTheBooking(): void
    {
        $content = '%PDF-1.4 Rechnung Notenpult';

        $this->save('Rechnung Notenpult.pdf', $content);

        $booking = Finance::query()->orderByDesc('id')->firstOrFail();
        $receipts = Attachment::where('entity_type', FinanceController::ENTITY_TYPE)
            ->where('entity_id', (int) $booking->id)
            ->get();

        $this->assertCount(1, $receipts, 'Der Beleg muss an der Buchung hängen: '
            . ($_SESSION['error'] ?? 'kein Fehler'));

        $receipt = $receipts->first();
        $this->assertSame(strlen($content), (int) $receipt->file_size, 'Die Größe wird mitgeschrieben.');
        $this->assertSame('application/pdf', (string) $receipt->mime_type);
        $this->assertSame('Rechnung Notenpult.pdf', (string) $receipt->original_name);

        // Zufallspräfix im Ablagenamen, wie bei jedem anderen Anhang: Zwei Belege
        // gleichen Namens sind damit in der Ablage unterscheidbar.
        $this->assertNotSame(
            (string) $receipt->original_name,
            (string) $receipt->filename,
            'Der Ablagename darf nicht der blanke Name des Uploads sein.'
        );
        $this->assertStringEndsWith('_Rechnung Notenpult.pdf', (string) $receipt->filename);
    }

    public function testTwoReceiptsOfTheSameNameGetDistinctStoredNames(): void
    {
        $this->save('Beleg.pdf', '%PDF-1.4 eins');
        $firstBooking = Finance::query()->orderByDesc('id')->firstOrFail();

        $this->save('Beleg.pdf', '%PDF-1.4 zwei');

        $names = Attachment::where('entity_type', FinanceController::ENTITY_TYPE)
            ->pluck('filename')
            ->all();

        $this->assertCount(2, $names);
        $this->assertSame($names, array_unique($names), 'Die Ablagenamen müssen sich unterscheiden.');
        $this->assertNotNull($firstBooking);
    }

    public function testAnOverlongReceiptNameIsTruncatedInsteadOfFailing(): void
    {
        $longName = str_repeat('Rechnung-', 40) . '.pdf';
        $this->assertGreaterThan(255, mb_strlen($longName), 'Der Testname muss die Spalte sprengen.');

        $this->save($longName, '%PDF-1.4 lang');

        $receipt = Attachment::where('entity_type', FinanceController::ENTITY_TYPE)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($receipt, 'Der Beleg muss gespeichert sein: ' . ($_SESSION['error'] ?? 'kein Fehler'));
        $this->assertLessThanOrEqual(255, mb_strlen((string) $receipt->filename));
        $this->assertLessThanOrEqual(255, mb_strlen((string) $receipt->original_name));
        $this->assertStringEndsWith('.pdf', (string) $receipt->original_name);
    }

    /**
     * Der Beleg ist die Beilage, die Buchung die Hauptsache. Eine beanstandete
     * Datei darf das Verbuchen nicht zurücknehmen - sonst verlöre der Kassier
     * seine Eingabe wegen eines zu großen Anhangs.
     */
    public function testARejectedReceiptDoesNotUndoTheBooking(): void
    {
        $this->save('beleg.php', '<?php echo "kein Beleg";', 'application/x-php');

        $booking = Finance::query()->orderByDesc('id')->first();

        $this->assertNotNull($booking, 'Die Buchung bleibt verbucht.');
        $this->assertSame(
            0,
            Attachment::where('entity_type', FinanceController::ENTITY_TYPE)->count(),
            'Der abgelehnte Beleg landet nicht in der Tabelle.'
        );
        $this->assertNotNull($_SESSION['error'] ?? null, 'Die Ablehnung wird gemeldet.');
        $this->assertNotNull($_SESSION['success'] ?? null, 'Die Buchung wird trotzdem als verbucht gemeldet.');
    }

    private function save(string $filename, string $content, string $mediaType = 'application/pdf'): void
    {
        $uploadedFile = new UploadedFile(
            (new StreamFactory())->createStream($content),
            $filename,
            $mediaType,
            strlen($content),
            UPLOAD_ERR_OK
        );

        $request = $this->makeRequest('POST', '/finances/save', [
            'amount' => '120,00',
            'type' => 'expense',
            'invoice_date' => '2025-10-05',
            'payment_date' => '2025-10-05',
            'description' => 'Notenpult',
            'finance_account_id' => (string) $this->account->id,
        ])->withUploadedFiles(['attachments' => [$uploadedFile]]);

        $this->controller()->save($request, $this->makeResponse());
    }

    private function controller(): FinanceController
    {
        return new FinanceController(
            $this->createStub(Twig::class),
            new BudgetService(),
            new NullLogger(),
            new FinanceReportPdfService(new TcLibPdfCanvas()),
            new BankStatementImportService(new NullLogger()),
            new FinanceAccountService(),
            new FinanceJournalService(),
            new FinanceCsvExportService()
        );
    }
}
