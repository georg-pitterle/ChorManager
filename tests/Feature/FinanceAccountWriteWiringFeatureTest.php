<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceAccountController;
use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Services\FinanceAccountService;
use App\Services\FinanceAccountWriteService;
use App\Services\FinanceJournalService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Der Kontoteil der Kassa nach der Antwort auf Review-Lauf 77 (Frage 1b).
 *
 * Zwei Dinge hielt vorher nichts fest: Die Übersicht fragte je Konto einzeln
 * nach Kontostand und Buchungszahl, und die Transaktion um Kontoänderung samt
 * Nachziehen der Zahlungsart stand im Controller - also an einer Stelle, die
 * sich nur über HTTP prüfen lässt.
 */
final class FinanceAccountWriteWiringFeatureTest extends TestCase
{
    use TestHttpHelpers;

    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];
    private FinanceAccountController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

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
            new FinanceAccountWriteService(new FinanceJournalService(), new NullLogger())
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

    /**
     * Die Übersicht darf nicht je Konto nachfragen.
     *
     * Gemessen wird die Steigung, nicht eine absolute Zahl - dieselbe Bauart wie
     * RegistrationQueryCountFeatureTest: Eine Schranke müsste bei jeder
     * harmlosen Änderung nachgezogen werden und sagte über das Problem nichts.
     */
    public function testTheOverviewDoesNotQueryPerAccount(): void
    {
        $withTwo = $this->countOverviewQueries(2);
        $withSix = $this->countOverviewQueries(6);

        $this->assertSame(
            $withTwo,
            $withSix,
            'Vier weitere Konten dürfen keine einzige Abfrage mehr kosten'
        );
    }

    /** Die Zahlen der Übersicht bleiben dieselben wie bei der Abfrage je Konto. */
    public function testTheOverviewStillShowsBalanceAndBookingCountPerAccount(): void
    {
        $withBookings = $this->makeAccount('Mit Buchungen', '100,00', '2026-01-01');
        $this->makeAccount('Leer', '40,00', '2026-01-01');
        // Vor dem Stichtag: steckt laut Konvention im Anfangsbestand und zählt
        // nicht in der Bewegungsrechnung - wohl aber als Buchung des Kontos.
        $early = $this->makeAccount('Stichtag später', '10,00', '2026-03-01');

        $this->booking($withBookings, '2026-02-01', '25.00', Finance::TYPE_INCOME);
        $this->booking($withBookings, '2026-02-02', '5.00', Finance::TYPE_EXPENSE);
        $this->booking($early, '2026-01-15', '7.00', Finance::TYPE_INCOME);

        $rows = $this->renderOverview();

        $this->assertSame(
            ['Leer' => 40.0, 'Mit Buchungen' => 120.0, 'Stichtag später' => 10.0],
            $this->byName($rows, 'balance')
        );
        $this->assertSame(
            ['Leer' => 0, 'Mit Buchungen' => 2, 'Stichtag später' => 1],
            $this->byName($rows, 'booking_count')
        );
    }

    /**
     * Scheitert das Nachziehen der Zahlungsart, bleibt auch die Kontoart stehen.
     *
     * Sonst zeigten Kassabericht und Zahlungsarten-Auswertung widersprüchliche
     * Werte: Das Konto wäre eine Barkassa, seine Buchungen stünden weiter auf
     * Überweisung. Dasselbe Muster wie UserPersistenceAtomicFeatureTest - eine
     * Unterklasse, deren Mitarbeiter immer scheitert.
     */
    public function testTheAccountTypeStaysWhenSyncingThePaymentMethodFails(): void
    {
        $account = $this->makeAccount('Umstellung', '0,00', '2026-01-01');
        $this->booking($account, '2026-02-01', '25.00', Finance::TYPE_INCOME);

        $writer = new class (new FinanceJournalService(), new NullLogger()) extends FinanceAccountWriteService {
            protected function syncPaymentMethod(FinanceAccount $account, string $previousType): void
            {
                throw new RuntimeException('Zahlungsart nicht schreibbar');
            }
        };

        $result = $writer->save((int) $account->id, [
            'name' => 'Umstellung',
            'type' => FinanceAccount::TYPE_CASH,
            'iban' => null,
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => true,
            'sort_order' => 0,
        ], null);

        $this->assertNull($result['account']);
        $this->assertSame('Fehler beim Speichern des Kontos.', $result['error']);

        $account->refresh();
        $this->assertSame(FinanceAccount::TYPE_BANK, $account->type, 'Die Kontoart darf nicht allein umschwenken.');
        $this->assertSame(
            Finance::PAYMENT_METHOD_BANK_TRANSFER,
            (string) Finance::where('finance_account_id', $account->id)->value('payment_method')
        );
    }

    /** Der Controller öffnet selbst keine Transaktion mehr. */
    public function testTheControllerLeavesTheConnectionToTheService(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/FinanceAccountController.php');

        $this->assertStringNotContainsString('Capsule', $source);
        $this->assertStringNotContainsString('transaction(', $source);
    }

    /** Im echten Container hängt der Schreibdienst am Controller. */
    public function testTheContainerWiresTheWriteServiceIntoTheController(): void
    {
        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($builder);
        $container = $builder->build();
        $container->set(Capsule::class, Bootstrap::getCapsule());

        $controller = $container->get(FinanceAccountController::class);
        $property = new ReflectionProperty(FinanceAccountController::class, 'writer');

        $this->assertInstanceOf(FinanceAccountWriteService::class, $property->getValue($controller));
    }

    /**
     * Legt $count Konten an, misst die Abfragen der Übersicht und räumt wieder ab.
     */
    private function countOverviewQueries(int $count): int
    {
        for ($i = 0; $i < $count; $i++) {
            $account = $this->makeAccount('Zählkonto ' . $i, '10,00', '2026-01-01');
            $this->booking($account, '2026-02-01', '3.00', Finance::TYPE_INCOME);
        }

        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $this->renderOverview();
        } finally {
            $queries = count($connection->getQueryLog());
            $connection->disableQueryLog();
            $connection->flushQueryLog();
            Finance::query()->delete();
            FinanceAccount::query()->delete();
        }

        return $queries;
    }

    /** @return list<array<string, mixed>> */
    private function renderOverview(): array
    {
        $this->renderCalls = [];
        $this->controller->index($this->makeRequest('GET', '/finances/accounts'), $this->makeResponse());

        return $this->renderCalls[0][1]['rows'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function byName(array $rows, string $field): array
    {
        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['account']->name] = $row[$field];
        }
        ksort($values);

        return $values;
    }

    private function makeAccount(string $name, string $openingBalance, string $openingDate): FinanceAccount
    {
        $response = $this->controller->save(
            $this->makeRequest('POST', '/finances/accounts/save', [
                'name' => $name,
                'type' => FinanceAccount::TYPE_BANK,
                'iban' => '',
                'opening_balance' => $openingBalance,
                'opening_date' => $openingDate,
                'sort_order' => '0',
                'is_active' => 'on',
            ]),
            $this->makeResponse()
        );
        $this->assertRedirect($response, '/finances/accounts');

        return FinanceAccount::where('name', $name)->firstOrFail();
    }

    private function booking(FinanceAccount $account, string $paymentDate, string $amount, string $type): Finance
    {
        return Finance::create([
            'running_number' => random_int(100000, 999999),
            'invoice_date' => $paymentDate,
            'payment_date' => $paymentDate,
            'description' => 'Wiring-Test',
            'group_name' => null,
            'finance_group_id' => null,
            'type' => $type,
            'amount' => $amount,
            'payment_method' => Finance::PAYMENT_METHOD_BANK_TRANSFER,
            'finance_account_id' => $account->id,
        ]);
    }
}
