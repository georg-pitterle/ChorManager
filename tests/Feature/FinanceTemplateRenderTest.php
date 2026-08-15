<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceAccountController;
use App\Controllers\FinanceController;
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
use App\Services\Pdf\TcLibPdfCanvas;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Rendert die Kassabuch-Templates gegen die echte Twig-Umgebung.
 *
 * Hintergrund: Twig greift auf Modellwerte zuerst als Property zu. Eine
 * parameterlose Methode wie isReversal() haelt Eloquent dabei fuer eine
 * Relation und wirft "must return a relationship instance" - ein Fehler, den
 * Tests mit Twig-Stub nie sehen.
 */
final class FinanceTemplateRenderTest extends TestCase
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
        Setting::updateOrCreate(['setting_key' => 'fiscal_year_start'], ['setting_value' => '01.09.']);

        $this->account = FinanceAccount::create([
            'name' => 'Bankkonto',
            'type' => FinanceAccount::TYPE_BANK,
            'iban' => 'AT911600000100629615',
            'opening_balance' => '1000.00',
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

    private function createTwig(string $currentPath): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();

        $environment->addFilter(new \Twig\TwigFilter(
            'person_name',
            static fn(mixed $person): string => (new \App\Services\NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', $currentPath);
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('csrf_token', 'test-token');
        $environment->addGlobal('settings', ['modules' => ['finance' => true, 'budget' => true]]);
        $this->registerMailBadgeStub($environment);
        $environment->addFunction(new TwigFunction('asset_path', static fn(string $path): string => $path));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = '') use ($currentPath): array {
                $settings = ['modules' => ['finance' => true, 'budget' => true]];
                $context = NavigationContext::fromSession($_SESSION, $settings, $currentPath, $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return $twig;
    }

    private function financeController(string $currentPath): FinanceController
    {
        return new FinanceController(
            $this->createTwig($currentPath),
            new BudgetService(),
            new NullLogger(),
            new FinanceReportPdfService(new TcLibPdfCanvas()),
            new BankStatementImportService(new NullLogger()),
            new FinanceAccountService(),
            new FinanceJournalService(),
            new FinanceCsvExportService()
        );
    }

    private function booking(int $number, string $paymentDate, string $type, string $amount): Finance
    {
        return Finance::create([
            'running_number' => $number,
            'invoice_date' => $paymentDate,
            'payment_date' => $paymentDate,
            'description' => 'Buchung ' . $number,
            'group_name' => 'Konzert',
            'finance_group_id' => null,
            'type' => $type,
            'amount' => $amount,
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $this->account->id,
        ]);
    }

    public function testKassabuchRendersWithNormalReversedAndOpenBookings(): void
    {
        $normal = $this->booking(5001, '2025-10-01', 'income', '100.00');
        $reversed = $this->booking(5002, '2025-10-02', 'expense', '50.00');
        $reversal = $this->booking(5003, '2025-10-02', 'income', '50.00');
        $reversal->update(['reversal_of_id' => $reversed->id, 'description' => 'Storno zu Nr. 5002']);

        Finance::create([
            'running_number' => 5004,
            'invoice_date' => '2025-10-03',
            'payment_date' => null,
            'description' => 'Noch offen',
            'group_name' => null,
            'finance_group_id' => null,
            'type' => 'expense',
            'amount' => '25.00',
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $this->account->id,
        ]);

        $request = $this->makeRequest('GET', '/finances', [], ['year' => '2025']);
        $response = $this->financeController('/finances')->index($request, $this->makeResponse());
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Buchung ' . $normal->running_number, $html);
        $this->assertStringContainsString('Storno zu Nr. 5002', $html);

        // Original als storniert, Gegenbuchung als Storno markiert.
        $this->assertStringContainsString('>storniert<', $html);
        $this->assertStringContainsString('>Storno<', $html);

        // Offene Posten stehen im eigenen Abschnitt.
        $this->assertStringContainsString('Offene Posten', $html);
        $this->assertStringContainsString('Noch offen', $html);

        // Nur die stornierbare Buchung bietet die Aktion an.
        $this->assertStringContainsString('/finances/' . $normal->id . '/reverse', $html);
        $this->assertStringNotContainsString('/finances/' . $reversed->id . '/reverse', $html);
    }

    public function testAuswertungRendersTheAccountStatement(): void
    {
        $this->booking(5005, '2025-10-01', 'income', '300.00');
        $this->booking(5006, '2025-11-01', 'expense', '120.00');

        $request = $this->makeRequest('GET', '/finances/report', [], ['year' => '2025']);
        $response = $this->financeController('/finances/report')->report($request, $this->makeResponse());
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Kassabericht je Konto', $html);
        $this->assertStringContainsString('Anfangsbestand', $html);
        $this->assertStringContainsString('Endbestand', $html);
        $this->assertStringContainsString('1.000,00', $html);
        // Anfangsbestand 1000 + 300 - 120
        $this->assertStringContainsString('1.180,00', $html);
    }

    public function testJournalRenders(): void
    {
        $booking = $this->booking(5007, '2025-10-01', 'income', '100.00');
        (new FinanceJournalService())->recordCreate($booking, 1);
        (new FinanceJournalService())->recordUpdate(
            $booking,
            ['amount' => '100'],
            ['amount' => '150.00'],
            1
        );

        $request = $this->makeRequest('GET', '/finances/journal');
        $response = $this->financeController('/finances/journal')->journal($request, $this->makeResponse());
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Änderungsjournal', $html);
        $this->assertStringContainsString('angelegt', $html);
        $this->assertStringContainsString('geändert', $html);
        $this->assertStringContainsString('amount', $html);
    }

    public function testKontenseiteRenders(): void
    {
        $this->booking(5008, '2025-10-01', 'income', '10.00');

        $controller = new FinanceAccountController(
            $this->createTwig('/finances/accounts'),
            new FinanceAccountService(),
            new NullLogger()
        );

        $request = $this->makeRequest('GET', '/finances/accounts');
        $response = $controller->index($request, $this->makeResponse());
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Bankkonto', $html);
        $this->assertStringContainsString('AT911600000100629615', $html);
        $this->assertStringContainsString('Anfangsbestand', $html);
    }

    public function testImportvorschauRenders(): void
    {
        $csv = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/bank_statement_sample.csv');
        $tempDir = sys_get_temp_dir() . '/chormanager_render_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0777, true);
        $path = $tempDir . '/auszug.csv';
        file_put_contents($path, $csv);

        $request = $this->makeRequest('POST', '/finances/import/preview')
            ->withUploadedFiles([
                'statement' => new \Slim\Psr7\UploadedFile($path, 'auszug.csv', null, filesize($path) ?: null),
            ]);

        $response = $this->financeController('/finances')->importPreview($request, $this->makeResponse());
        $html = (string) $response->getBody();

        unlink($path);
        rmdir($tempDir);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Kontoauszug importieren', $html);
        $this->assertStringContainsString('STRIPE - KUPF SERVICES GMBH', $html);
        // Konto wird ueber die IBAN des Auszugs vorbelegt.
        $this->assertStringContainsString('Anhand der IBAN', $html);
    }
}
