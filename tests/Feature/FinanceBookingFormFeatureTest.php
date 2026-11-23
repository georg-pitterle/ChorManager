<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\FinanceController;
use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Models\FinanceRevision;
use App\Models\Setting;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\FinanceJournalService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Kassabuch als Arbeitsplatz: Erfassen ohne Datenverlust, Serien-Erfassen und
 * der Kassastand direkt über der Buchungsliste.
 *
 * Gerendert wird gegen die echte Twig-Umgebung, weil die Prüfungen am fertigen
 * HTML hängen: Ob ein Wert im Dialog wieder auftaucht, sieht nur das Template.
 */
final class FinanceBookingFormFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;
    use BuildsControllers;

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
        (new FinanceJournalService())->setClosedUntil(null);

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

    private function controller(): FinanceController
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addFilter(new \Twig\TwigFilter(
            'person_name',
            static fn(mixed $person): string => (new \App\Services\NameFormatterService())->formatPerson($person)
        ));
        // Die Session als Referenz: index() liest Flash-Meldungen erst beim Rendern aus.
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', '/finances');
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('csrf_token', 'test-token');
        $environment->addGlobal('settings', ['modules' => ['finance' => true, 'budget' => true]]);
        $this->registerMailBadgeStub($environment);
        $this->registerAttachmentPreviewStub($environment);
        $environment->addFunction(new TwigFunction('asset_path', static fn(string $path): string => $path));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = ''): array {
                $settings = ['modules' => ['finance' => true, 'budget' => true]];
                $context = NavigationContext::fromSession($_SESSION, $settings, '/finances', $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return $this->financeController(['view' => $twig]);
    }

    private function renderIndex(string $year = '2025'): string
    {
        $request = $this->makeRequest('GET', '/finances', [], ['year' => $year]);
        $response = $this->controller()->index($request, $this->makeResponse());
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** @param array<string, string|list<string>> $overrides */
    private function postBooking(array $overrides = []): void
    {
        $body = array_merge([
            'invoice_date' => '2025-10-01',
            'payment_date' => '2025-10-01',
            'description' => 'Belegstapel Chorfest',
            'group_names' => ['Konzert'],
            'type' => 'income',
            'finance_account_id' => (string) $this->account->id,
            'amount' => '12,50',
        ], $overrides);

        $response = $this->controller()->save($this->makeRequest('POST', '/finances/save', $body), $this->makeResponse());
        $this->assertSame(302, $response->getStatusCode());
    }

    private function booking(int $number, ?string $paymentDate, string $type, string $amount): Finance
    {
        return Finance::create([
            'running_number' => $number,
            'invoice_date' => $paymentDate ?? '2025-10-03',
            'payment_date' => $paymentDate,
            'description' => 'Buchung ' . $number,
            'type' => $type,
            'amount' => $amount,
            'payment_method' => 'bank_transfer',
            'finance_account_id' => $this->account->id,
        ]);
    }

    /** Teil des HTML ab dem Buchungsdialog - dort müssen Fehler und Eingaben stehen. */
    private function bookingDialog(string $html): string
    {
        $start = strpos($html, 'id="financeModal"');
        $this->assertNotFalse($start, 'Der Buchungsdialog fehlt.');
        $end = strpos($html, 'id="settingsModal"', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    public function testAnInvalidAmountKeepsTheInputAndReopensTheDialog(): void
    {
        $this->postBooking(['amount' => '12.50 abc']);

        $this->assertSame(0, Finance::count());

        $html = $this->renderIndex();
        $dialog = $this->bookingDialog($html);

        $this->assertStringContainsString('data-open-on-load="1"', $dialog);
        $this->assertStringContainsString('value="Belegstapel Chorfest"', $dialog);
        $this->assertStringContainsString('value="12.50 abc"', $dialog);
        $this->assertStringContainsString('value="2025-10-01"', $dialog);
        $this->assertMatchesRegularExpression('/value="income"[^>]*checked/', $dialog);
        // Die Meldung steht im Dialog, nicht ein zweites Mal oben auf der Seite.
        $this->assertSame(1, substr_count($html, 'Ungültiger Betrag'));
        $this->assertStringContainsString('Ungültiger Betrag', $dialog);
    }

    public function testPostedGroupNamesAreAllStored(): void
    {
        $this->postBooking(['group_names' => ['Konzert', 'Herbstkonzert 2026', '']]);

        $this->assertSame(['Herbstkonzert 2026', 'Konzert'], Finance::sole()->groupNames());
    }

    /**
     * Zwei Gruppen, die im Geschäftsjahr beide im Budget stehen, weist der
     * Dialog ab - mit der Meldung im Dialog und beiden Gruppen noch gewählt,
     * damit die Kassierin nur eine davon herausnehmen muss.
     */
    public function testAConflictReopensTheDialogWithTheMessageAndTheEnteredGroups(): void
    {
        foreach (['Konzert', 'Reise'] as $name) {
            $group = \App\Models\FinanceGroup::firstOrCreate(['name' => $name]);
            \App\Models\BudgetCategory::create([
                'fiscal_year_start' => 2025,
                'finance_group_id' => $group->id,
                'type' => 'income',
            ]);
        }

        $this->postBooking(['group_names' => ['Konzert', 'Reise']]);

        $this->assertSame(0, Finance::count());
        $dialog = $this->bookingDialog($this->renderIndex());
        $this->assertStringContainsString('data-open-on-load="1"', $dialog);
        $this->assertStringContainsString('beide im Budget', $dialog);
        $this->assertStringContainsString('<option value="Konzert" selected', $dialog);
        $this->assertStringContainsString('<option value="Reise" selected', $dialog);
    }

    public function testARejectedDialogMatchesGroupsRegardlessOfCase(): void
    {
        \App\Models\FinanceGroup::firstOrCreate(['name' => 'Konzert']);

        $this->postBooking(['group_names' => ['konzert'], 'amount' => 'kaputt']);

        $dialog = $this->bookingDialog($this->renderIndex());
        $this->assertStringContainsString('<option value="Konzert" selected', $dialog);
        $this->assertStringNotContainsString('value="konzert"', $dialog);
    }

    public function testTheDialogKeepsAGroupThatDoesNotExistYet(): void
    {
        $this->postBooking(['group_names' => ['Ganz neue Gruppe'], 'amount' => 'kaputt']);

        $dialog = $this->bookingDialog($this->renderIndex());
        $this->assertStringContainsString('<option value="Ganz neue Gruppe" selected', $dialog);
    }

    public function testWritersSeeTheBulkSelectionAndReadersDoNot(): void
    {
        $this->booking(9101, '2025-10-02', 'income', '5.00');
        $this->booking(9102, null, 'expense', '7.00');

        $html = $this->renderIndex();
        $this->assertSame(2, substr_count($html, 'data-finance-select-all'));
        $this->assertSame(2, preg_match_all('/data-finance-select\s/', $html));
        $this->assertStringContainsString('action="/finances/bulk-groups"', $html);
        $this->assertStringContainsString('name="year" value="2025"', $html);

        $_SESSION['can_manage_finances'] = false;
        $readerHtml = $this->renderIndex();
        $this->assertStringNotContainsString('data-finance-select', $readerHtml);
        $this->assertStringNotContainsString('/finances/bulk-groups', $readerHtml);
    }

    public function testAFailedEditKeepsTheBookingItIsAbout(): void
    {
        $existing = $this->booking(5001, '2025-10-01', 'expense', '40.00');

        $this->postBooking(['id' => (string) $existing->id, 'description' => '']);

        $dialog = $this->bookingDialog($this->renderIndex());
        $this->assertMatchesRegularExpression('/name="id"[^>]*value="' . $existing->id . '"/', $dialog);
        $this->assertStringContainsString('Buchung 5001 bearbeiten', $dialog);
    }

    public function testTheDialogStaysClosedWithoutAnError(): void
    {
        $dialog = $this->bookingDialog($this->renderIndex());

        $this->assertStringContainsString('data-open-on-load="0"', $dialog);
    }

    public function testTheOpenCheckboxStoresAnOpenItemDespiteADate(): void
    {
        $this->postBooking(['payment_open' => '1']);

        $booking = Finance::first();
        $this->assertNotNull($booking);
        $this->assertNull($booking->payment_date);
    }

    public function testSaveAndNextReopensTheDialogWithTheStickyFieldsOnly(): void
    {
        $this->postBooking(['save_and_next' => '1']);

        $this->assertSame(1, Finance::count());

        $html = $this->renderIndex();
        $dialog = $this->bookingDialog($html);

        $this->assertStringContainsString('verbucht', $html);
        $this->assertStringContainsString('data-open-on-load="1"', $dialog);
        // Konto, Art, Daten und Gruppe bleiben für den nächsten Beleg stehen ...
        $this->assertMatchesRegularExpression(
            '/<option value="' . $this->account->id . '"\s+selected/',
            $dialog
        );
        $this->assertMatchesRegularExpression('/value="income"[^>]*checked/', $dialog);
        $this->assertStringContainsString('value="2025-10-01"', $dialog);
        $this->assertStringContainsString('<option value="Konzert" selected', $dialog);
        // ... Beschreibung und Betrag gehören zum gerade gespeicherten Beleg.
        $this->assertStringNotContainsString('value="Belegstapel Chorfest"', $dialog);
        $this->assertStringNotContainsString('value="12,50"', $dialog);
    }

    /**
     * Beim Bearbeiten gibt es keinen "nächsten Beleg": Käme der Wert trotzdem an
     * (Enter im Formular), ginge der Dialog als neue Buchung mit den Daten der
     * bearbeiteten auf.
     */
    public function testSaveAndNextIsIgnoredWhenEditing(): void
    {
        $existing = $this->booking(5002, '2025-10-01', 'expense', '40.00');

        $this->postBooking(['id' => (string) $existing->id, 'save_and_next' => '1']);

        $dialog = $this->bookingDialog($this->renderIndex());
        $this->assertStringContainsString('data-open-on-load="0"', $dialog);
    }

    public function testAFailedEditBringsBackTheExistingReceipts(): void
    {
        $existing = $this->booking(5003, '2025-10-01', 'expense', '40.00');

        $this->postBooking(['id' => (string) $existing->id, 'amount' => 'abc']);

        $dialog = $this->bookingDialog($this->renderIndex());
        $this->assertStringContainsString('data-finance-reload-item=', $dialog);
    }

    public function testThePlainSaveDoesNotReopenTheDialog(): void
    {
        $this->postBooking();

        $dialog = $this->bookingDialog($this->renderIndex());
        $this->assertStringContainsString('data-open-on-load="0"', $dialog);
    }

    public function testTheCashbookShowsTheBalancePerAccount(): void
    {
        $this->booking(5005, '2025-10-01', 'income', '300.00');
        $this->booking(5006, '2025-11-01', 'expense', '120.00');

        $html = $this->renderIndex();

        $this->assertStringContainsString('id="finances-balance-title"', $html);
        $this->assertStringContainsString('Kassastand', $html);
        // Anfangsbestand 1000 + 300 - 120
        $this->assertStringContainsString('1.180,00', $html);
        $this->assertStringContainsString('1.000,00', $html);
    }

    public function testAmountsUseATrueMinusSignInBothLists(): void
    {
        $this->booking(5007, '2025-10-01', 'expense', '2064.35');
        $this->booking(5008, null, 'expense', '25.00');

        $html = $this->renderIndex();

        $this->assertStringContainsString('−2.064,35 €', $html);
        $this->assertStringContainsString('−25,00 €', $html);
        $this->assertStringNotContainsString('- 2.064,35', $html);
    }

    public function testAnEmptyYearInvitesTheFirstBooking(): void
    {
        $html = $this->renderIndex();

        $this->assertStringContainsString('Noch keine Buchungen in diesem Geschäftsjahr', $html);
        $this->assertStringContainsString('Erste Buchung erfassen', $html);
    }

    public function testTheHeaderShowsTheLockDate(): void
    {
        (new FinanceJournalService())->setClosedUntil('2025-12-31');

        $html = $this->renderIndex();

        $this->assertStringContainsString('Abgeschlossen bis 31.12.2025', $html);
    }

    public function testTheReversalQuestionNamesAmountAndDescription(): void
    {
        $this->booking(5009, '2025-10-01', 'expense', '35.00');

        $html = $this->renderIndex();

        $this->assertMatchesRegularExpression(
            '/data-confirm="Buchung 5009 \S+Buchung 5009\S+ über −35,00 € stornieren\?/u',
            $html
        );
    }
}
