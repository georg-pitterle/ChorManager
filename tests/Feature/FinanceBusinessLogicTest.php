<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Attachment;
use App\Models\Finance;
use App\Models\FinanceGroup;
use App\Models\Setting;
use App\Services\BudgetService;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

/**
 * Behavioural coverage for FinanceController business logic fixes:
 * amount validation, group-name edge cases, running-number integrity,
 * transactional delete, and exception logging.
 */
final class FinanceBusinessLogicTest extends TestCase
{
    use TestHttpHelpers;

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

        return new FinanceController($view, new BudgetService(), $logger ?? new NullLogger());
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

        $controller->delete($this->makeRequest('POST', '/finances/' . $second->id . '/delete'), $this->makeResponse(), ['id' => (string) $second->id]);
        unset($_SESSION['success'], $_SESSION['error']);

        $controller->save($this->makeRequest('POST', '/finances/save', $this->baseFinanceData()), $this->makeResponse());
        $third = Finance::orderByDesc('id')->first();
        unset($_SESSION['success'], $_SESSION['error']);

        $this->assertGreaterThan(
            $second->running_number,
            $third->running_number,
            'A running number must never be reused, even after the highest booking was deleted.'
        );
    }

    public function testDeleteRemovesFinanceAndItsAttachments(): void
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

        $controller->delete(
            $this->makeRequest('POST', '/finances/' . $finance->id . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $finance->id]
        );

        $this->assertNull(Finance::find($finance->id));
        $this->assertSame(0, Attachment::where('entity_type', 'finance')->where('entity_id', $finance->id)->count());
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
        // Missing required 'invoice_date' triggers a DB-level failure inside the try block.
        $request = $this->makeRequest('POST', '/finances/save', $this->baseFinanceData(['invoice_date' => '']));

        $controller->save($request, $this->makeResponse());
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testDeleteLogsExceptionWithEventContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->isString(),
                $this->callback(function (array $context): bool {
                    return ($context['event'] ?? null) === 'finance.delete.failed'
                        && array_key_exists('exception', $context);
                })
            );

        $controller = $this->makeController($logger);
        $controller->delete($this->makeRequest('POST', '/finances/999999999/delete'), $this->makeResponse(), ['id' => '999999999']);
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
}
