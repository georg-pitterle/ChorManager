<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Services\FinanceBookingService;
use App\Services\FinanceJournalService;
use ArrayObject;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Der FinanceController schreibt Buchungen nicht selbst, sondern über den
 * FinanceBookingService.
 *
 * Buchen, Importieren und Stornieren samt Periodensperre und Nummernkreis standen
 * als Transaktionen im Controller. Die Regeln selbst bewachen weiter
 * FinanceReversalAndLockFeatureTest, FinanceImportFeatureTest und
 * FinancePaymentDatePeriodTest; dieser Test hält nur fest, dass der Controller
 * den Dienst ruft und dessen Antwort richtig übersetzt.
 */
final class FinanceBookingWiringFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = ['user_id' => 1];
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

    public function testReversalGoesThroughTheBookingService(): void
    {
        $account = FinanceAccount::firstOrCreate(
            ['type' => FinanceAccount::TYPE_CASH, 'name' => 'Kassa (Test)'],
            [
                'opening_balance' => 0,
                'opening_date' => '2020-01-01',
                'is_active' => true,
                'sort_order' => 0,
            ]
        );
        $original = Finance::create([
            'running_number' => ((int) Finance::max('running_number')) + 1,
            'invoice_date' => '2026-03-01',
            'payment_date' => '2026-03-01',
            'description' => 'Storno-Anschluss ' . bin2hex(random_bytes(3)),
            'type' => Finance::TYPE_INCOME,
            'amount' => '10.00',
            'payment_method' => $account->paymentMethod(),
            'finance_account_id' => $account->id,
        ]);

        $calls = new ArrayObject();
        $service = new class ($calls) extends FinanceBookingService {
            public function __construct(private readonly ArrayObject $calls)
            {
                parent::__construct(new FinanceJournalService());
            }

            public function reverse(Finance $original, ?int $actorId): ?Finance
            {
                $this->calls[] = [(int) $original->id, $actorId];

                return null;
            }
        };

        $controller = $this->financeController([
            'view' => $this->createStub(Twig::class),
            'bookings' => $service,
        ]);

        $controller->reverse(
            $this->makeRequest('POST', '/finances/' . $original->id . '/reverse'),
            $this->makeResponse(),
            ['id' => (string) $original->id]
        );

        self::assertSame([[(int) $original->id, 1]], $calls->getArrayCopy(), 'Genau ein Aufruf, mit dem Akteur.');
        self::assertSame('Diese Buchung wurde bereits storniert.', $_SESSION['error'] ?? null);
        self::assertSame(0, Finance::where('reversal_of_id', $original->id)->count());
    }
}
