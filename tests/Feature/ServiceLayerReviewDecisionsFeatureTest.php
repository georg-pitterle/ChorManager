<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BudgetCategory;
use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Models\FinanceGroup;
use App\Models\MailQueue;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueAdminService;
use App\Services\NewsletterLockingService;
use App\Services\RateLimiterService;
use App\Services\SheetArchiveService;
use App\Util\AppUrlResolver;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Unit\Bootstrap;

/**
 * Die im Review offen gebliebenen Punkte, nachdem der Entwickler entschieden hat.
 */
final class ServiceLayerReviewDecisionsFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        unset($_ENV['APP_ENV'], $_ENV['APP_URL'], $_ENV['DDEV_PRIMARY_URL'], $_ENV['DDEV_PRIMARY_URL_WITHOUT_PORT']);
        unset($_SERVER['APP_ENV'], $_SERVER['APP_URL']);

        parent::tearDown();
    }

    // ---------------------------------------------------------------- Punkt 1

    /**
     * `blob:` kennt HTMLPurifier nicht, solche Adressen fallen bei der Prüfung
     * ohnehin durch. Die Freigabe war toter Konfigurationsbestand.
     */
    public function testNewsletterSanitizerNoLongerAllowsTheBlobScheme(): void
    {
        $sanitized = (new HtmlSanitizer())->sanitizeNewsletterHtml(
            '<p><a href="blob:https://chor.example/1234">Blob</a></p>'
        );

        $this->assertStringNotContainsString('blob:', $sanitized);
    }

    /**
     * Eingebettete Bilder bleiben erlaubt - der Upload-Helfer erzeugt sie.
     */
    public function testNewsletterSanitizerStillAllowsInlineImages(): void
    {
        $pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

        $sanitized = (new HtmlSanitizer())->sanitizeNewsletterHtml('<p><img src="' . $pixel . '" alt="Punkt"></p>');

        $this->assertStringContainsString('data:image/png', $sanitized);
    }

    // ---------------------------------------------------------------- Punkt 6

    /**
     * Beide Methoden wurden nirgends aufgerufen - weder im Code noch in
     * Vorlagen noch in Tests - und schrieben beim blossen Nachfragen.
     */
    public function testUnusedLockHelpersAreGone(): void
    {
        $reflection = new ReflectionClass(NewsletterLockingService::class);

        $this->assertFalse($reflection->hasMethod('isLockedByOther'));
        $this->assertFalse($reflection->hasMethod('getLockInfo'));
    }

    /**
     * Eine Abfrage darf nichts verändern: Der abgelaufene Sperrvermerk bleibt
     * stehen, bis ihn jemand tatsächlich übernimmt.
     */
    public function testIsLockedByDoesNotWriteWhenTheLockHasExpired(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();

        $newsletter = Newsletter::create([
            'title' => 'Gesperrt',
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => (int) $owner->id,
            'locked_by' => (int) $owner->id,
            'locked_at' => Carbon::now()->subHours(3),
        ]);

        $service = new NewsletterLockingService();

        $this->assertFalse($service->isLockedBy($newsletter, (int) $other->id));

        $stored = $newsletter->fresh();
        $this->assertSame((int) $owner->id, (int) $stored->locked_by, 'Die Abfrage darf nichts schreiben.');
        $this->assertNotNull($stored->locked_at);
    }

    // ---------------------------------------------------------------- Punkt 7

    /**
     * Die Spalte fasst 100 Zeichen, geprüft wurde in Bytes. Eine Stimmkategorie
     * mit Umlauten wurde deshalb früher abgelehnt, als sie musste.
     */
    public function testVoiceCategoryLengthIsMeasuredInCharactersNotBytes(): void
    {
        $song = \App\Models\Song::create([
            'title' => 'Umlaut-Probe ' . bin2hex(random_bytes(4)),
            'composer' => 'Testkomponist',
        ]);

        // 60 Zeichen, aber 120 Bytes in UTF-8.
        $category = str_repeat('ä', 60);
        $this->assertSame(60, mb_strlen($category));
        $this->assertGreaterThan(100, strlen($category));

        $archive = (new SheetArchiveService())->saveArchiveData(
            (int) $song->id,
            'ARCH-Umlaut',
            'Regal U',
            [['voice_category' => $category, 'count' => 3]]
        );

        $this->assertSame($category, (string) $archive->lineItems->first()->voice_category);
    }

    // ---------------------------------------------------------------- Punkt 3

    /**
     * Das Budget rechnet stornierte Paare heraus, das Kassabuch zählt sie mit.
     * Die Übersicht muss den Unterschied beziffern, sonst stehen zwei Summen
     * ohne erkennbaren Grund nebeneinander.
     */
    public function testBudgetOverviewReportsTheAmountRemovedByReversals(): void
    {
        $group = FinanceGroup::create(['name' => 'Storno-Gruppe ' . bin2hex(random_bytes(4))]);
        $account = FinanceAccount::create([
            'name' => 'Probe-Konto ' . bin2hex(random_bytes(4)),
            'type' => FinanceAccount::TYPE_CASH,
            'opening_balance' => 0,
            'opening_date' => '2020-01-01',
            'is_active' => 1,
            'sort_order' => 1,
        ]);

        $service = new BudgetService();
        $year = $service->defaultFiscalYearStart();
        [$day, $month] = $service->getFiscalConfig();
        [$from] = $service->datesForYear($year, $day, $month);
        $bookingDay = $from->copy()->addDay()->format('Y-m-d');

        $original = $this->createFinance($group, $account, 'expense', '120.00', $bookingDay);
        $this->createFinance($group, $account, 'income', '120.00', $bookingDay, (int) $original->id);
        $this->createFinance($group, $account, 'expense', '50.00', $bookingDay);

        foreach (['income', 'expense'] as $type) {
            BudgetCategory::create([
                'fiscal_year_start' => $year,
                'finance_group_id' => (int) $group->id,
                'type' => $type,
            ]);
        }

        $overview = $service->getOverview($year);

        // Bewusst die eigene Gruppe statt der Jahressumme: In der Entwicklungs-
        // datenbank liegen weitere Kategorien desselben Haushaltsjahres, deren
        // Beträge sonst mitzählen und die Zusicherung wertlos machen.
        $expense = $this->findGroupRow($overview['expense'], (int) $group->id);
        $income = $this->findGroupRow($overview['income'], (int) $group->id);

        $this->assertSame('50.00', $expense['actual'], 'Das Ist zählt das Storno-Paar nicht.');
        $this->assertSame('120.00', $expense['reversed'], 'Der herausgerechnete Betrag muss beziffert sein.');
        $this->assertSame('0.00', $income['actual']);
        $this->assertSame('120.00', $income['reversed']);
        $this->assertTrue($overview['has_reversals']);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function findGroupRow(array $rows, int $financeGroupId): array
    {
        foreach ($rows as $row) {
            if ((int) $row['category']->finance_group_id === $financeGroupId) {
                return $row;
            }
        }

        $this->fail('Für die angelegte Gruppe steht keine Zeile in der Übersicht.');
    }

    // ---------------------------------------------------------------- Punkt 2

    /**
     * Ohne APP_URL bildet der Auflöser die Basisadresse aus dem Host-Kopf der
     * Anfrage. Im Produktivbetrieb folgt damit jeder erzeugte Link dem, was in
     * der Anfrage stand - deshalb bricht die Anwendung dort jetzt ab.
     */
    public function testProductionRefusesToDeriveTheBaseUrlFromTheRequest(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://angreifer.example/reset-password', ['REMOTE_ADDR' => '127.0.0.1']);

        $this->expectException(RuntimeException::class);
        AppUrlResolver::resolveBaseUrl($request);
    }

    public function testProductionAcceptsAConfiguredAppUrl(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_URL'] = 'https://chor.example.org';

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://angreifer.example/reset-password', ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertSame('https://chor.example.org', AppUrlResolver::resolveBaseUrl($request));
    }

    /**
     * Ausserhalb des Produktivbetriebs bleibt der Rückfall auf die Anfrage
     * erhalten - sonst liesse sich lokal nichts mehr starten.
     */
    public function testDevelopmentKeepsTheRequestFallback(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost:8080/reset-password', ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertSame('http://localhost:8080', AppUrlResolver::resolveBaseUrl($request));
    }

    // ---------------------------------------------------------------- Punkt 4

    /**
     * Lässt sich die Zählerdatei nicht anlegen, lässt der Begrenzer weiter durch -
     * ein Dateisystemproblem soll niemanden aussperren. Bisher geschah das
     * lautlos, die Bremse war damit unbemerkt aus.
     */
    public function testRateLimiterLogsAWarningWhenItFallsOpen(): void
    {
        $records = [];
        $logger = new class ($records) extends AbstractLogger {
            /** @param array<int, array<string, mixed>> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'context' => $context];
            }
        };

        // Eine Datei statt eines Verzeichnisses: Das Anlegen der Zählerdatei
        // darunter kann nicht gelingen.
        $blocked = sys_get_temp_dir() . '/chormanager_rate_limit_blocked_' . bin2hex(random_bytes(4));
        file_put_contents($blocked, 'kein Verzeichnis');

        try {
            $limiter = new RateLimiterService($blocked, null, $logger);
            $result = $limiter->hit('probe@example.test', 3, 60);

            $this->assertTrue($result['allowed'], 'Ein Dateisystemproblem darf niemanden aussperren.');
            $this->assertNotSame([], $records, 'Das Durchlassen muss protokolliert werden.');
            $this->assertSame('warning', $records[0]['level']);
            $this->assertSame('auth.rate_limit.unavailable', $records[0]['context']['event'] ?? null);
        } finally {
            @unlink($blocked);
        }
    }

    // ---------------------------------------------------------------- Punkt 8

    /**
     * Nach einem grossen Newsletter-Versand liegen entsprechend viele Zeilen in
     * der Warteschlange. Die Verwaltungsseite lud sie alle auf einmal.
     */
    public function testMailQueueListingIsPaged(): void
    {
        for ($i = 0; $i < 5; $i++) {
            MailQueue::create([
                'mail_type' => 'newsletter',
                'recipient_email' => "paged{$i}@example.test",
                'subject' => 'Seitenweise ' . $i,
                'body_html' => '<p>x</p>',
                'payload_json' => [],
                'status' => 'queued',
                'attempts' => 0,
                'max_attempts' => 3,
                'is_retryable' => false,
                'next_attempt_at' => Carbon::now(),
            ]);
        }

        $service = new MailQueueAdminService();

        $firstPage = $service->listEntries(['per_page' => 2]);
        $this->assertCount(2, $firstPage);

        $secondPage = $service->listEntries(['per_page' => 2, 'page' => 2]);
        $this->assertCount(2, $secondPage);
        $this->assertNotSame(
            $firstPage->pluck('id')->all(),
            $secondPage->pluck('id')->all(),
            'Die zweite Seite muss andere Zeilen zeigen.'
        );

        $this->assertGreaterThanOrEqual(5, $service->countEntries([]));
    }

    // ---------------------------------------------------------------- Helfer

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "review_decisions_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }

    private function createFinance(
        FinanceGroup $group,
        FinanceAccount $account,
        string $type,
        string $amount,
        string $paymentDate,
        ?int $reversalOfId = null
    ): Finance {
        return Finance::create([
            'running_number' => random_int(100000, 999999),
            'invoice_date' => $paymentDate,
            'payment_date' => $paymentDate,
            'description' => 'Review-Buchung',
            'finance_group_id' => (int) $group->id,
            'type' => $type,
            'amount' => $amount,
            'payment_method' => 'cash',
            'finance_account_id' => (int) $account->id,
            'reversal_of_id' => $reversalOfId,
        ]);
    }
}
