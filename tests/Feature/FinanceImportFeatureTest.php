<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Finance;
use App\Models\FinanceGroup;
use App\Services\BankStatementImportService;
use App\Services\BudgetService;
use App\Services\FinanceReportPdfService;
use App\Services\Pdf\TcLibPdfCanvas;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\UploadedFile;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

final class FinanceImportFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private const TEST_GROUP = 'Import-Testgruppe';

    private FinanceController $controller;
    private string $tempDir;
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];
    private int $baselineId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->baselineId = (int) Finance::max('id');

        $this->tempDir = sys_get_temp_dir() . '/chormanager_import_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0777, true);

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
            new BankStatementImportService(new NullLogger())
        );

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        $_SESSION = [];
        parent::tearDown();
    }

    /** Nur die in diesem Test angelegten Buchungen, nie vorhandene Seed-Daten. */
    private function imported(): Builder
    {
        return Finance::query()
            ->where('id', '>', $this->baselineId)
            ->whereNotNull('import_hash');
    }

    private function upload(string $filename, ?string $content = null): UploadedFile
    {
        $content ??= (string) file_get_contents(dirname(__DIR__) . '/Fixtures/bank_statement_sample.csv');
        $path = $this->tempDir . '/' . bin2hex(random_bytes(4)) . '-' . $filename;
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, null, filesize($path) ?: null, UPLOAD_ERR_OK);
    }

    private function preview(string $filename = 'auszug.csv', ?string $content = null): ResponseInterface
    {
        $request = $this->makeRequest('POST', '/finances/import/preview')
            ->withUploadedFiles(['statement' => $this->upload($filename, $content)]);

        return $this->controller->importPreview($request, $this->makeResponse());
    }

    /**
     * @param list<int> $indexes
     * @param array<int, string> $groups
     */
    private function confirm(array $indexes, array $groups = []): ResponseInterface
    {
        $request = $this->makeRequest('POST', '/finances/import/confirm', [
            'selected' => array_map('strval', $indexes),
            'group' => $groups,
        ]);

        return $this->controller->importConfirm($request, $this->makeResponse());
    }

    public function testImportActionsAndRoutesAreRegisteredBehindFinanceManagement(): void
    {
        $this->assertTrue(method_exists(FinanceController::class, 'importPreview'));
        $this->assertTrue(method_exists(FinanceController::class, 'importConfirm'));
        $this->assertTrue(method_exists(FinanceController::class, 'importCancel'));

        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');
        $this->assertStringContainsString("'/finances/import/preview'", $routes);
        $this->assertStringContainsString("'/finances/import/confirm'", $routes);
        $this->assertStringContainsString("'/finances/import/cancel'", $routes);
        $this->assertStringContainsString('requiresFinanceManagement', $routes);

        $this->assertFileExists(dirname(__DIR__, 2) . '/templates/finances/import.twig');
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/finances/index.twig');
        $this->assertStringContainsString('/finances/import/preview', $index);
    }

    public function testPreviewParsesTheStatementAndStashesItInTheSession(): void
    {
        $response = $this->preview();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->renderCalls);
        $this->assertSame('finances/import.twig', $this->renderCalls[0][0]);
        $this->assertCount(4, $this->renderCalls[0][1]['rows']);

        $this->assertArrayHasKey('finance_import', $_SESSION);
        $this->assertCount(4, $_SESSION['finance_import']['rows']);
        $this->assertSame('auszug.csv', $_SESSION['finance_import']['filename']);
    }

    public function testPreviewRejectsNonCsvUploads(): void
    {
        $response = $this->preview('auszug.txt');

        $this->assertRedirect($response, '/finances');
        $this->assertArrayNotHasKey('finance_import', $_SESSION);
        $this->assertStringContainsString('CSV', (string) $_SESSION['error']);
    }

    public function testPreviewRejectsFilesWithAnUnexpectedLayout(): void
    {
        $response = $this->preview('auszug.csv', "Datum;Wert\n01.02.2026;5\n");

        $this->assertRedirect($response, '/finances');
        $this->assertArrayNotHasKey('finance_import', $_SESSION);
        $this->assertStringContainsString('Buchungsdatum', (string) $_SESSION['error']);
    }

    public function testConfirmCreatesBookingsForTheSelectedRowsOnly(): void
    {
        $this->preview();
        $rows = $_SESSION['finance_import']['rows'];

        $response = $this->confirm([0, 1], [0 => self::TEST_GROUP]);

        $this->assertRedirect($response, '/finances');
        $this->assertStringContainsString('2 Buchungen importiert', (string) $_SESSION['success']);
        $this->assertArrayNotHasKey('finance_import', $_SESSION);

        $imported = $this->imported()->orderBy('running_number')->get();
        $this->assertCount(2, $imported);

        $first = $imported[0];
        $this->assertSame($rows[0]['invoice_date'], $first->invoice_date->format('Y-m-d'));
        $this->assertSame($rows[0]['type'], $first->type);
        $this->assertSame($rows[0]['amount'], (string) $first->amount);
        $this->assertSame('bank_transfer', $first->payment_method);
        $this->assertSame($rows[0]['import_hash'], $first->import_hash);

        // Gruppenname und kanonische ID müssen gemeinsam gesetzt sein, sonst
        // fehlen die Buchungen später in den Budget-Ist-Werten.
        $group = FinanceGroup::where('name', self::TEST_GROUP)->first();
        $this->assertNotNull($group);
        $this->assertSame(self::TEST_GROUP, $first->group_name);
        $this->assertSame($group->id, $first->finance_group_id);

        $this->assertNull($imported[1]->group_name);
        $this->assertNull($imported[1]->finance_group_id);
        $this->assertSame($first->running_number + 1, $imported[1]->running_number);
    }

    public function testAlreadyImportedRowsAreFlaggedAndNeverInsertedTwice(): void
    {
        $this->preview();
        $this->confirm([0, 1, 2, 3]);
        $this->assertSame(4, $this->imported()->count());

        $this->preview();
        $rows = $this->renderCalls[1][1]['rows'];
        foreach ($rows as $row) {
            $this->assertTrue($row['duplicate'], 'Bereits importierte Zeile muss als Dublette markiert sein.');
        }

        $this->confirm([0, 1, 2, 3]);

        $this->assertSame(4, $this->imported()->count());
        $this->assertStringContainsString('0 Buchungen importiert', (string) $_SESSION['success']);
        $this->assertStringContainsString('4 übersprungen', (string) $_SESSION['success']);
    }

    public function testConfirmWithoutSessionPayloadImportsNothing(): void
    {
        $response = $this->confirm([0, 1]);

        $this->assertRedirect($response, '/finances');
        $this->assertSame(0, $this->imported()->count());
        $this->assertNotEmpty($_SESSION['error']);
    }

    public function testConfirmIgnoresAnExpiredSessionPayload(): void
    {
        $this->preview();
        $_SESSION['finance_import']['created_at'] = time() - 7200;

        $response = $this->confirm([0]);

        $this->assertRedirect($response, '/finances');
        $this->assertSame(0, $this->imported()->count());
        $this->assertArrayNotHasKey('finance_import', $_SESSION);
    }

    public function testConfirmRefusesRowsThatCouldNotBeParsed(): void
    {
        $csv = "Buchungsdatum;Valutadatum;Betrag;Währung;Auftraggebername;Auftraggeber IBAN/Kto.Nr.;"
            . "Auftraggeber BIC/BLZ;Empfängername;Empfänger IBAN/Kto.Nr.;Empfänger BIC/BLZ;Text;Verwendungszweck\n"
            . "31.02.2026;31.02.2026;-10,00;EUR;Chorkuma;AT91;BTV;Kaputt;AT42;HYP;Text;Ungueltiges Datum\n";

        $this->preview('auszug.csv', $csv);
        $this->confirm([0]);

        $this->assertSame(0, $this->imported()->count());
        $this->assertStringContainsString('0 Buchungen importiert', (string) $_SESSION['success']);
    }

    public function testCancelDiscardsThePendingImport(): void
    {
        $this->preview();
        $this->assertArrayHasKey('finance_import', $_SESSION);

        $response = $this->controller->importCancel(
            $this->makeRequest('POST', '/finances/import/cancel'),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/finances');
        $this->assertArrayNotHasKey('finance_import', $_SESSION);
        $this->assertSame(0, $this->imported()->count());
    }
}
