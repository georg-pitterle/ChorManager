<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\Finance;
use App\Models\FinanceGroup;
use App\Services\BudgetService;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural coverage for the FK-based link between budget categories and
 * finance bookings (replaces the previous brittle group_name string match).
 */
final class BudgetFinanceGroupLinkTest extends TestCase
{
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

    public function testFinanceGroupRelationsAreWired(): void
    {
        $this->assertInstanceOf(BelongsTo::class, (new Finance())->financeGroup());
        $this->assertInstanceOf(BelongsTo::class, (new BudgetCategory())->financeGroup());
        $this->assertContains('finance_group_id', (new BudgetCategory())->getFillable());
        $this->assertContains('finance_group_id', (new Finance())->getFillable());
    }

    public function testComputeActualSumsByFinanceGroupId(): void
    {
        $service = new BudgetService();
        $group = FinanceGroup::create(['name' => 'TEST_Konzert_' . uniqid()]);

        [$from, $to] = $service->datesForYear(2025, 1, 9);

        $this->makeFinance($group->id, 'income', '2025-10-01', 500.00);
        $this->makeFinance($group->id, 'income', '2026-02-01', 250.00);
        // Out of range / wrong type / wrong group must be ignored.
        $this->makeFinance($group->id, 'income', '2024-01-01', 999.00);
        $this->makeFinance($group->id, 'expense', '2025-10-01', 999.00);

        $actual = $service->computeActual($group->id, 'income', $from, $to);

        $this->assertSame('750.00', $actual);
    }

    public function testActualSurvivesGroupRename(): void
    {
        $service = new BudgetService();
        $group = FinanceGroup::create(['name' => 'TEST_Foerderung_' . uniqid()]);
        [$from, $to] = $service->datesForYear(2025, 1, 9);
        $this->makeFinance($group->id, 'income', '2025-10-01', 1200.00);

        $before = $service->computeActual($group->id, 'income', $from, $to);

        $group->update(['name' => 'TEST_Renamed_' . uniqid()]);

        $after = $service->computeActual($group->id, 'income', $from, $to);

        $this->assertSame('1200.00', $before);
        $this->assertSame($before, $after, 'Renaming the group must not break the actual link.');
    }

    public function testBuildAvailableYearsIncludesFutureYears(): void
    {
        $service = new BudgetService();
        $default = $service->defaultFiscalYearStart();

        $years = array_keys($service->buildAvailableYears());

        $this->assertContains($default + 1, $years, 'Next fiscal year must be selectable for planning.');
    }

    private function makeFinance(int $groupId, string $type, string $invoiceDate, float $amount): void
    {
        $runningNumber = ((int) Finance::max('running_number')) + 1;
        Finance::create([
            'running_number' => $runningNumber,
            'invoice_date' => $invoiceDate,
            'payment_date' => null,
            'description' => 'TEST booking',
            'finance_group_id' => $groupId,
            'group_name' => null,
            'type' => $type,
            'amount' => $amount,
            'payment_method' => 'bank_transfer',
        ]);
    }
}
