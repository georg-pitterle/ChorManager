<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\MailBadgeRefreshMiddleware;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\MailBadgeService;
use App\Services\MailCredentialCryptoService;
use App\Controllers\MailBadgeController;
use App\Util\AfterResponse;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

final class MailBadgeRefreshMiddlewareTest extends TestCase
{
    use TestHttpHelpers;

    private const ENV_KEY = 'MAIL_CREDENTIAL_KEY';

    private ?User $user = null;
    private ?string $originalEnvValue = null;
    private bool $hadEnvValue = false;

    /**
     * Die aufgeschobene Arbeit dieser Anfrage. `runAtShutdown` ist aus: Im Test
     * gibt es keine Antwort, hinter die man warten könnte - `runPending()` steht
     * dort, wo in einer echten Anfrage `fastcgi_finish_request` ausgelöst hätte.
     */
    private AfterResponse $afterResponse;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->hadEnvValue = array_key_exists(self::ENV_KEY, $_ENV);
        $this->originalEnvValue = $_ENV[self::ENV_KEY] ?? null;

        $generatedKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV[self::ENV_KEY] = $generatedKey;
        $_SERVER[self::ENV_KEY] = $generatedKey;
        putenv(self::ENV_KEY . '=' . $generatedKey);

        $_SESSION = [];

        $this->afterResponse = new AfterResponse(static function (): void {
        }, false);
    }

    protected function tearDown(): void
    {
        if ($this->user !== null) {
            UserMailAccount::query()->where('user_id', $this->user->id)->delete();
            $this->user->delete();
            $this->user = null;
        }

        if ($this->hadEnvValue) {
            $_ENV[self::ENV_KEY] = $this->originalEnvValue;
            $_SERVER[self::ENV_KEY] = $this->originalEnvValue;
            putenv(self::ENV_KEY . '=' . $this->originalEnvValue);
        } else {
            unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);
            putenv(self::ENV_KEY);
        }

        parent::tearDown();
    }

    private function makeHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };
    }

    private function makeBadgeService(): MailBadgeService
    {
        return new MailBadgeService(new MailCredentialCryptoService(), new NullLogger(), 1);
    }

    private function createUser(): User
    {
        $this->user = User::create([
            'first_name' => 'Badge',
            'last_name' => 'Tester',
            'email' => 'badge.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => 1,
        ]);

        return $this->user;
    }

    public function testNoSessionUserIdPassesThroughWithoutTouchingAnyAccount(): void
    {
        $user = $this->createUser();
        $crypto = new MailCredentialCryptoService();

        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => $crypto->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 3,
            'mail_last_uid_seen' => '42',
            'mail_last_checked_at' => Carbon::now()->subMinutes(10),
        ]);

        unset($_SESSION['user_id']);

        $middleware = new MailBadgeRefreshMiddleware(
            fn () => $this->makeBadgeService(),
            new NullLogger(),
            $this->afterResponse
        );
        $response = $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertSame(200, $response->getStatusCode());

        $account = UserMailAccount::where('user_id', $user->id)->first();
        $this->assertSame(3, $account->mail_last_unseen_count);
        $this->assertSame('42', $account->mail_last_uid_seen);
    }

    public function testUserWithoutMailAccountPassesThroughCleanly(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;

        $middleware = new MailBadgeRefreshMiddleware(
            fn () => $this->makeBadgeService(),
            new NullLogger(),
            $this->afterResponse
        );
        $response = $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(UserMailAccount::where('user_id', $user->id)->first());
    }

    public function testFreshlyCheckedAccountInsideStalenessWindowIsNotRefreshed(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $crypto = new MailCredentialCryptoService();

        $checkedAt = Carbon::now()->subMinutes(2);

        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => $crypto->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 9,
            'mail_last_uid_seen' => '77',
            'mail_last_checked_at' => $checkedAt,
        ]);

        $middleware = new MailBadgeRefreshMiddleware(
            fn () => $this->makeBadgeService(),
            new NullLogger(),
            $this->afterResponse
        );
        $response = $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertSame(200, $response->getStatusCode());

        $account = UserMailAccount::where('user_id', $user->id)->first();
        $this->assertSame(9, $account->mail_last_unseen_count);
        $this->assertSame('77', $account->mail_last_uid_seen);
        $this->assertSame(
            $checkedAt->format('Y-m-d H:i:s'),
            Carbon::parse($account->mail_last_checked_at)->format('Y-m-d H:i:s')
        );
    }

    public function testBadgeServiceConstructionFailureDoesNotBreakTheRequest(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $crypto = new MailCredentialCryptoService();

        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => $crypto->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 4,
            'mail_last_uid_seen' => '11',
            'mail_last_checked_at' => Carbon::now()->subMinutes(10),
        ]);

        // Simulate a misconfigured MAIL_CREDENTIAL_KEY: the badge service cannot
        // even be constructed. The request must still complete normally.
        $factory = static function (): MailBadgeService {
            throw new \RuntimeException('MAIL_CREDENTIAL_KEY is not configured correctly');
        };

        $middleware = new MailBadgeRefreshMiddleware($factory, new NullLogger(), $this->afterResponse);
        $response = $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());
        $this->afterResponse->runPending();

        $this->assertSame(200, $response->getStatusCode());

        $account = UserMailAccount::where('user_id', $user->id)->first();
        $this->assertSame(4, $account->mail_last_unseen_count);
        $this->assertSame('11', $account->mail_last_uid_seen);
    }

    public function testStaleAccountTriggersRefreshAttemptAndRequestStillCompletes(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $crypto = new MailCredentialCryptoService();

        $staleCheckedAt = Carbon::now()->subMinutes(10);

        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => $crypto->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 9,
            'mail_last_uid_seen' => '77',
            'mail_last_checked_at' => $staleCheckedAt,
        ]);

        $middleware = new MailBadgeRefreshMiddleware(
            fn () => $this->makeBadgeService(),
            new NullLogger(),
            $this->afterResponse
        );
        $response = $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());
        $this->afterResponse->runPending();

        $this->assertSame(200, $response->getStatusCode());

        // Refresh attempted against an unreachable host fails gracefully:
        // cached columns remain exactly as they were (refresh() contract).
        $account = UserMailAccount::where('user_id', $user->id)->first();
        $this->assertSame(9, $account->mail_last_unseen_count);
        $this->assertSame('77', $account->mail_last_uid_seen);
    }

    public function testFailedRefreshStillArmsTheStalenessWindow(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $crypto = new MailCredentialCryptoService();

        $staleCheckedAt = Carbon::now()->subMinutes(10);

        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => $crypto->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 9,
            'mail_last_uid_seen' => '77',
            'mail_last_checked_at' => $staleCheckedAt,
        ]);

        $middleware = new MailBadgeRefreshMiddleware(
            fn () => $this->makeBadgeService(),
            new NullLogger(),
            $this->afterResponse
        );
        $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());
        $this->afterResponse->runPending();

        // MailBadgeService::refresh() lässt mail_last_checked_at bei einem Fehlschlag
        // unberührt. Ohne einen eigenen Vermerk bliebe der Zeitstempel für immer alt und
        // jeder einzelne Seitenaufruf dieses Mitglieds öffnete erneut eine Verbindung zum
        // nicht erreichbaren IMAP-Server - inklusive Verbindungszeitüberschreitung.
        $account = UserMailAccount::where('user_id', $user->id)->first();
        $this->assertNotNull($account->mail_last_checked_at);
        $this->assertTrue(
            Carbon::parse($account->mail_last_checked_at)->greaterThan($staleCheckedAt),
            'Ein fehlgeschlagener Abgleich muss die Wartezeit trotzdem starten.'
        );

        // Der zwischengespeicherte Zählerstand bleibt dabei unverändert.
        $this->assertSame(9, $account->mail_last_unseen_count);
        $this->assertSame('77', $account->mail_last_uid_seen);
    }

    public function testSecondRequestAfterAFailedRefreshDoesNotRetryImmediately(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $crypto = new MailCredentialCryptoService();

        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => $crypto->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 9,
            'mail_last_uid_seen' => '77',
            'mail_last_checked_at' => Carbon::now()->subMinutes(10),
        ]);

        $attempts = 0;
        $factory = function () use (&$attempts): MailBadgeService {
            $attempts++;

            return $this->makeBadgeService();
        };

        $middleware = new MailBadgeRefreshMiddleware($factory, new NullLogger(), $this->afterResponse);
        $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());
        $this->afterResponse->runPending();
        $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());
        $this->afterResponse->runPending();

        $this->assertSame(1, $attempts);
    }

    /**
     * Eine kurze Datenbank-Störung ist kein Fehler.
     *
     * Dieselbe Regel setzen MailQueueProcessingMiddleware und
     * OpportunisticReminderMiddleware schon um: Startet der db-Container beim
     * Ausrollen neu, ist die nebenbei erledigte Arbeit Kür, und der nächste
     * Aufruf holt sie nach. Hier fehlte die Unterscheidung - jede Anfrage eines
     * angemeldeten Mitglieds mit Postfach schrieb in diesen Sekunden eine
     * Fehlerzeile, und die tägliche Log-Analyse muss jede davon anschauen.
     */
    public function testTransientDatabaseOutageIsLoggedAsSkippedNotAsFailure(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $crypto = new MailCredentialCryptoService();

        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => $crypto->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 9,
            'mail_last_uid_seen' => '77',
            'mail_last_checked_at' => Carbon::now()->subMinutes(10),
        ]);

        $logHandler = new TestHandler();
        $logger = new Logger('test', [$logHandler]);

        $middleware = new MailBadgeRefreshMiddleware(
            fn (): MailBadgeService => $this->makeFailingBadgeService($this->queryException()),
            $logger,
            $this->afterResponse
        );
        $response = $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());
        $this->afterResponse->runPending();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('mail_badge.middleware.skipped', $this->loggedEvents($logHandler));
        $this->assertNotContains('mail_badge.middleware.failed', $this->loggedEvents($logHandler));
        $this->assertTrue($logHandler->hasWarningRecords());
        $this->assertFalse($logHandler->hasErrorRecords());
    }

    /**
     * Alles andere bleibt ein Fehler - sonst verdeckte die neue Unterscheidung
     * genau die Fehlschläge, für die die Protokollzeile da ist.
     */
    public function testAnyOtherFailureIsStillLoggedAsAnError(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $crypto = new MailCredentialCryptoService();

        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => $crypto->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 9,
            'mail_last_uid_seen' => '77',
            'mail_last_checked_at' => Carbon::now()->subMinutes(10),
        ]);

        $logHandler = new TestHandler();
        $logger = new Logger('test', [$logHandler]);

        $middleware = new MailBadgeRefreshMiddleware(
            static function (): MailBadgeService {
                throw new \RuntimeException('MAIL_CREDENTIAL_KEY ist nicht richtig gesetzt');
            },
            $logger,
            $this->afterResponse
        );
        $response = $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());
        $this->afterResponse->runPending();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('mail_badge.middleware.failed', $this->loggedEvents($logHandler));
        $this->assertNotContains('mail_badge.middleware.skipped', $this->loggedEvents($logHandler));
        $this->assertTrue($logHandler->hasErrorRecords());
    }

    /**
     * Der IMAP-Abgleich wartet, bis die Antwort draußen ist.
     *
     * Vorher lief er vor dem Controller: Alle fünf Minuten wartete genau die
     * Person, deren Aufruf ihn auslöste, zusätzlich auf DNS, TLS, LOGIN und
     * STATUS - bei einem nicht erreichbaren Server bis zur
     * Verbindungszeitüberschreitung von fünf Sekunden. Für die Seite, die
     * gerade gebaut wird, braucht den Zählerstand niemand; die Navigationsleiste
     * zeigt ihn aus den zwischengespeicherten Spalten, und die Oberfläche fragt
     * ihn beim Zurückwechseln in den Tab ohnehin selbst nach.
     *
     * Dieselbe Entscheidung wie bei Mailwarteschlange und Erinnerungen.
     */
    public function testTheImapRefreshRunsOnlyAfterTheResponseIsOut(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createDueAccount($user);

        $calls = new \ArrayObject();
        $logHandler = new TestHandler();

        $middleware = new MailBadgeRefreshMiddleware(
            fn (): MailBadgeService => $this->makeRecordingBadgeService($calls),
            new Logger('test', [$logHandler]),
            $this->afterResponse
        );

        $response = $middleware->process(
            $this->makeRequest('GET', '/dashboard'),
            $this->makeRecordingHandler($calls)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['handler'],
            $calls->getArrayCopy(),
            'Der Abgleich darf die Antwort nicht aufhalten.'
        );

        $this->afterResponse->runPending();

        $this->assertSame(['handler', 'refresh'], $calls->getArrayCopy());
        $this->assertSame([], $logHandler->getRecords(), 'Kein stiller Fehlschlag.');
    }

    /**
     * Die eine Ausnahme: Der Badge-Endpunkt trägt den Zählerstand in seiner
     * eigenen Antwort. Hier muss der Abgleich davor stehen, sonst liefert er den
     * Stand von vorher - und genau diesen Endpunkt fragt die Oberfläche beim
     * Zurückwechseln in den Tab ab, weil der angezeigte Stand überholt sein
     * könnte.
     */
    public function testTheBadgeEndpointItselfRefreshesBeforeItsOwnResponse(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createDueAccount($user);

        $calls = new \ArrayObject();
        $logHandler = new TestHandler();

        $middleware = new MailBadgeRefreshMiddleware(
            fn (): MailBadgeService => $this->makeRecordingBadgeService($calls),
            new Logger('test', [$logHandler]),
            $this->afterResponse
        );

        $middleware->process(
            $this->makeRequest('GET', MailBadgeController::REFRESH_PATH),
            $this->makeRecordingHandler($calls)
        );

        $this->assertSame(['refresh', 'handler'], $calls->getArrayCopy());

        $this->afterResponse->runPending();

        $this->assertSame(
            ['refresh', 'handler'],
            $calls->getArrayCopy(),
            'Kein zweiter Abgleich nach der Antwort.'
        );
        $this->assertSame([], $logHandler->getRecords(), 'Kein stiller Fehlschlag.');
    }

    /**
     * Der Vermerk aus der Session wird noch vor der Antwort abgeräumt.
     *
     * Nach der Freigabe durch AfterResponse ist die Sitzung geschrieben und
     * geschlossen: Ein `unset($_SESSION[...])` von dort erreicht die Platte
     * nicht mehr. Bliebe der Vermerk stehen, liefe jede folgende Anfrage als
     * erzwungener Abgleich - also gegen die Untergrenze von 15 Sekunden statt
     * gegen die reguläre Wartezeit von fünf Minuten.
     */
    public function testTheForceSignalIsConsumedBeforeTheResponse(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $_SESSION[MailBadgeController::FORCE_SESSION_KEY] = true;
        $this->createDueAccount($user);

        $calls = new \ArrayObject();
        $logHandler = new TestHandler();

        $middleware = new MailBadgeRefreshMiddleware(
            fn (): MailBadgeService => $this->makeRecordingBadgeService($calls),
            new Logger('test', [$logHandler]),
            $this->afterResponse
        );

        $middleware->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertArrayNotHasKey(MailBadgeController::FORCE_SESSION_KEY, $_SESSION);

        $this->afterResponse->runPending();
        $this->assertSame(['refresh'], $calls->getArrayCopy());
        $this->assertSame([], $logHandler->getRecords(), 'Kein stiller Fehlschlag.');
    }

    private function createDueAccount(User $user): UserMailAccount
    {
        return UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => (new MailCredentialCryptoService())->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => 9,
            'mail_last_uid_seen' => '77',
            'mail_last_checked_at' => Carbon::now()->subMinutes(10),
        ]);
    }

    /**
     * Aufgezeichnet wird in ein Objekt, nicht in ein Feld per Referenz: Die
     * Fabrik des Badge-Dienstes wird als Closure übergeben, und eine Closure
     * fängt ihre Umgebung als Kopie ein. Über eine Referenz lief die Aufzeichnung
     * dann ins Leere, und weil OpportunisticWork jeden Fehlschlag bei sich
     * behält, sah das aus wie ein Abgleich, der nie stattfand.
     */
    private function makeRecordingHandler(\ArrayObject $calls): RequestHandlerInterface
    {
        return new class ($calls) implements RequestHandlerInterface {
            public function __construct(private readonly \ArrayObject $calls)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls->append('handler');

                return (new Response())->withStatus(200);
            }
        };
    }

    private function makeRecordingBadgeService(\ArrayObject $calls): MailBadgeService
    {
        return new class (new MailCredentialCryptoService(), new NullLogger(), 1, $calls) extends MailBadgeService {
            public function __construct(
                MailCredentialCryptoService $crypto,
                NullLogger $logger,
                int $connectTimeoutSeconds,
                private readonly \ArrayObject $calls
            ) {
                parent::__construct($crypto, $logger, $connectTimeoutSeconds);
            }

            public function refresh(UserMailAccount $account): bool
            {
                $this->calls->append('refresh');

                return true;
            }
        };
    }

    /**
     * @return list<string|null>
     */
    private function loggedEvents(TestHandler $logHandler): array
    {
        return array_map(
            static fn (\Monolog\LogRecord $record): ?string => $record->context['event'] ?? null,
            $logHandler->getRecords()
        );
    }

    private function queryException(): QueryException
    {
        return new QueryException(
            'mysql',
            'select * from `user_mail_accounts` where `user_id` = ?',
            [1],
            new \PDOException('SQLSTATE[HY000] [2002] Connection refused')
        );
    }

    /**
     * Ein Badge-Dienst, dessen Abgleich an der genannten Störung scheitert -
     * `MailBadgeService::refresh()` schreibt seine Ergebnisspalten selbst in die
     * Datenbank und ist damit die Stelle, an der eine Störung ankommt.
     */
    private function makeFailingBadgeService(\Throwable $failure): MailBadgeService
    {
        return new class (new MailCredentialCryptoService(), new NullLogger(), 1, $failure) extends MailBadgeService {
            public function __construct(
                MailCredentialCryptoService $crypto,
                NullLogger $logger,
                int $connectTimeoutSeconds,
                private readonly \Throwable $failure
            ) {
                parent::__construct($crypto, $logger, $connectTimeoutSeconds);
            }

            public function refresh(UserMailAccount $account): bool
            {
                throw $this->failure;
            }
        };
    }
}
