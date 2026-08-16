<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceAccountController;
use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Services\FinanceAccountService;
use App\Services\FinanceJournalService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

final class FinanceAccountControllerTest extends TestCase
{
    use TestHttpHelpers;

    private FinanceAccountController $controller;
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        // Selbstreferenz der Stornobuchungen zuerst loesen, sonst blockiert der
        // Fremdschluessel reversal_of_id das Leeren der Tabelle.
        Finance::query()->whereNotNull('reversal_of_id')->update(['reversal_of_id' => null]);
        Finance::query()->delete();
        FinanceAccount::query()->delete();

        $this->renderCalls = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data = []): ResponseInterface {
                $this->renderCalls[] = [$template, $data];
                return $response;
            }
        );

        $this->controller = new FinanceAccountController(
            $twig,
            new FinanceAccountService(),
            new FinanceJournalService(),
            new NullLogger()
        );
        $_SESSION = [];
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
    private function post(array $overrides = []): ResponseInterface
    {
        $body = array_merge([
            'name' => 'Sparbuch',
            'type' => 'bank',
            'iban' => 'AT02 2050 3033 0044 3714',
            'opening_balance' => '1.250,50',
            'opening_date' => '2026-01-01',
            'sort_order' => '3',
            'is_active' => 'on',
        ], $overrides);

        return $this->controller->save(
            $this->makeRequest('POST', '/finances/accounts/save', $body),
            $this->makeResponse()
        );
    }

    public function testRoutesAndTemplateAreRegistered(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');
        $this->assertStringContainsString("'/finances/accounts'", $routes);
        $this->assertStringContainsString("'/finances/accounts/save'", $routes);
        $this->assertStringContainsString('requiresFinanceManagement', $routes);

        $this->assertFileExists(dirname(__DIR__, 2) . '/templates/finances/accounts.twig');
    }

    public function testCreatesAnAccountAndNormalizesInput(): void
    {
        $response = $this->post();

        $this->assertRedirect($response, '/finances/accounts');
        $this->assertSame('Konto angelegt.', $_SESSION['success']);

        $account = FinanceAccount::where('name', 'Sparbuch')->firstOrFail();
        $this->assertSame('bank', $account->type);
        $this->assertSame('1250.50', (string) $account->opening_balance);
        // IBAN wird ohne Leerzeichen und in Großbuchstaben abgelegt.
        $this->assertSame('AT022050303300443714', $account->iban);
        $this->assertTrue($account->is_active);
    }

    public function testRejectsDuplicateNames(): void
    {
        $this->post();
        $_SESSION = [];

        $this->post();

        $this->assertSame(1, FinanceAccount::where('name', 'Sparbuch')->count());
        $this->assertStringContainsString('existiert bereits', (string) $_SESSION['error']);
    }

    public function testRejectsInvalidTypeAmountAndDate(): void
    {
        $this->post(['type' => 'krypto']);
        $this->assertStringContainsString('Kontoart', (string) $_SESSION['error']);

        $this->post(['name' => 'A', 'opening_balance' => 'keine Zahl']);
        $this->assertStringContainsString('Anfangsbestand', (string) $_SESSION['error']);

        $this->post(['name' => 'B', 'opening_date' => '31.02.2026']);
        $this->assertStringContainsString('Stichtag', (string) $_SESSION['error']);

        $this->assertSame(0, FinanceAccount::count());
    }

    public function testUpdatesAnExistingAccountAndCanDeactivateIt(): void
    {
        $this->post();
        $account = FinanceAccount::where('name', 'Sparbuch')->firstOrFail();

        $this->post(['id' => (string) $account->id, 'name' => 'Sparbuch alt', 'is_active' => null]);

        $account->refresh();
        $this->assertSame('Sparbuch alt', $account->name);
        $this->assertFalse($account->is_active);
    }

    public function testRefusesToChangeOpeningDataOfAClosedPeriod(): void
    {
        $this->post();
        $account = FinanceAccount::where('name', 'Sparbuch')->firstOrFail();
        (new FinanceJournalService())->setClosedUntil('2026-06-30');
        $_SESSION = [];

        $this->post(['id' => (string) $account->id, 'opening_balance' => '9.999,00']);

        $account->refresh();
        $this->assertSame('1250.50', (string) $account->opening_balance);
        $this->assertStringContainsString('abgeschlossen', (string) $_SESSION['error']);
    }

    public function testAllowsOtherAccountChangesWhileThePeriodIsClosed(): void
    {
        $this->post();
        $account = FinanceAccount::where('name', 'Sparbuch')->firstOrFail();
        (new FinanceJournalService())->setClosedUntil('2026-06-30');
        $_SESSION = [];

        $this->post(['id' => (string) $account->id, 'name' => 'Sparbuch neu']);

        $account->refresh();
        $this->assertSame('Sparbuch neu', $account->name);
        $this->assertSame('Konto aktualisiert.', $_SESSION['success']);
    }

    public function testTypeChangeSyncsThePaymentMethodOfExistingBookings(): void
    {
        $this->post();
        $account = FinanceAccount::where('name', 'Sparbuch')->firstOrFail();
        Finance::create([
            'running_number' => 8103,
            'invoice_date' => '2026-02-01',
            'payment_date' => '2026-02-01',
            'description' => 'Zufluss',
            'group_name' => null,
            'finance_group_id' => null,
            'type' => 'income',
            'amount' => '25.00',
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $account->id,
        ]);

        $this->post(['id' => (string) $account->id, 'type' => 'cash', 'iban' => '']);

        $account->refresh();
        $this->assertSame('cash', $account->type);
        $this->assertSame(
            'cash',
            (string) Finance::where('finance_account_id', $account->id)->value('payment_method')
        );
    }

    public function testDeletesAnEmptyAccount(): void
    {
        $this->post();
        $account = FinanceAccount::where('name', 'Sparbuch')->firstOrFail();

        $response = $this->controller->delete(
            $this->makeRequest('POST', '/finances/accounts/' . $account->id . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $account->id]
        );

        $this->assertRedirect($response, '/finances/accounts');
        $this->assertSame(0, FinanceAccount::where('id', $account->id)->count());
    }

    public function testRefusesToDeleteAnAccountThatStillHasBookings(): void
    {
        $this->post();
        $account = FinanceAccount::where('name', 'Sparbuch')->firstOrFail();
        Finance::create([
            'running_number' => 8101,
            'invoice_date' => '2026-02-01',
            'payment_date' => '2026-02-01',
            'description' => 'Testbuchung',
            'group_name' => null,
            'finance_group_id' => null,
            'type' => 'income',
            'amount' => '10.00',
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $account->id,
        ]);

        $this->controller->delete(
            $this->makeRequest('POST', '/finances/accounts/' . $account->id . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $account->id]
        );

        $this->assertSame(1, FinanceAccount::where('id', $account->id)->count());
        $this->assertStringContainsString('inaktiv', (string) $_SESSION['error']);
    }

    public function testIndexShowsCurrentBalanceAndBookingCount(): void
    {
        $this->post(['opening_balance' => '100,00', 'opening_date' => '2026-01-01']);
        $account = FinanceAccount::where('name', 'Sparbuch')->firstOrFail();
        Finance::create([
            'running_number' => 8102,
            'invoice_date' => '2026-02-01',
            'payment_date' => '2026-02-01',
            'description' => 'Zufluss',
            'group_name' => null,
            'finance_group_id' => null,
            'type' => 'income',
            'amount' => '25.00',
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $account->id,
        ]);

        $this->controller->index($this->makeRequest('GET', '/finances/accounts'), $this->makeResponse());

        $data = $this->renderCalls[0][1];
        $this->assertSame('finances/accounts.twig', $this->renderCalls[0][0]);
        $this->assertCount(1, $data['rows']);
        $this->assertSame(125.0, $data['rows'][0]['balance']);
        $this->assertSame(1, $data['rows'][0]['booking_count']);
    }
}
