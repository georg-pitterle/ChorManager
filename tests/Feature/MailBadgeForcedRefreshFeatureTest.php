<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\MailBadgeController;
use App\Middleware\MailBadgeRefreshMiddleware;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\MailBadgeService;
use App\Services\MailCredentialCryptoService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

/**
 * Der Zähler muss nach dem Lesen der Mails wieder stimmen.
 *
 * Das Postfach öffnet sich in einem eigenen Tab, der ChorManager-Tab stellt von sich
 * aus keine neue Anfrage - und selbst beim nächsten Seitenaufruf sperrte die
 * Wartezeit von fünf Minuten den IMAP-Abgleich. Der Zähler zeigte danach weiter die
 * längst gelesenen Nachrichten. Zwei Signale heben die Wartezeit nun gezielt auf:
 * ein Einmalvermerk in der Session nach dem Start des Webmails und der Aufruf des
 * Badge-Endpunkts, den das Frontend beim Zurückwechseln in den Tab abfragt.
 */
final class MailBadgeForcedRefreshFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private const ENV_KEY = 'MAIL_CREDENTIAL_KEY';

    private ?User $user = null;
    private ?string $originalEnvValue = null;
    private bool $hadEnvValue = false;

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

        // Die Middleware ruft session_start(), und das leert ein von Hand befülltes
        // $_SESSION in einem frischen CLI-Prozess wieder. Erst starten, dann füllen -
        // sonst hinge das Ergebnis an der Reihenfolge der Testläufe.
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION = [];
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

        $_SESSION = [];

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

    private function createUser(): User
    {
        $this->user = User::create([
            'first_name' => 'Badge',
            'last_name' => 'Forced',
            'email' => 'badge.forced.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        return $this->user;
    }

    /**
     * Legt ein Konto an, dessen IMAP-Ziel garantiert unerreichbar ist. Ob der
     * Abgleich gelingt, ist hier gleichgültig - geprüft wird allein, ob er
     * überhaupt versucht wird.
     */
    private function createAccount(User $user, Carbon $lastCheckedAt): UserMailAccount
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
            'mail_last_checked_at' => $lastCheckedAt,
        ]);
    }

    /**
     * @param int $attempts Zähler der tatsächlich gebauten Badge-Dienste.
     */
    private function makeMiddleware(int &$attempts): MailBadgeRefreshMiddleware
    {
        $factory = static function () use (&$attempts): MailBadgeService {
            $attempts++;

            return new MailBadgeService(new MailCredentialCryptoService(), new NullLogger(), 1);
        };

        return new MailBadgeRefreshMiddleware($factory, new NullLogger());
    }

    public function testSessionSignalForcesRefreshInsideTheStalenessWindow(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createAccount($user, Carbon::now()->subMinutes(2));

        $_SESSION[MailBadgeController::FORCE_SESSION_KEY] = true;

        $attempts = 0;
        $this->makeMiddleware($attempts)
            ->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertSame(1, $attempts, 'Der Vermerk aus der Session muss die Wartezeit aufheben.');
    }

    public function testWithoutTheSignalTheStalenessWindowStillBlocksTheRefresh(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createAccount($user, Carbon::now()->subMinutes(2));

        $attempts = 0;
        $this->makeMiddleware($attempts)
            ->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertSame(0, $attempts, 'Ohne Signal bleibt die Wartezeit von fünf Minuten unangetastet.');
    }

    public function testSessionSignalIsConsumedAfterASingleRequest(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createAccount($user, Carbon::now()->subMinutes(2));

        $_SESSION[MailBadgeController::FORCE_SESSION_KEY] = true;

        $attempts = 0;
        $this->makeMiddleware($attempts)
            ->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertArrayNotHasKey(
            MailBadgeController::FORCE_SESSION_KEY,
            $_SESSION,
            'Ein Einmalvermerk, der liegen bleibt, erzwingt jeden folgenden Seitenaufruf aufs Neue.'
        );
    }

    public function testSessionSignalIsConsumedEvenWithoutAMailbox(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $_SESSION[MailBadgeController::FORCE_SESSION_KEY] = true;

        $attempts = 0;
        $this->makeMiddleware($attempts)
            ->process($this->makeRequest('GET', '/dashboard'), $this->makeHandler());

        $this->assertSame(0, $attempts);
        $this->assertArrayNotHasKey(MailBadgeController::FORCE_SESSION_KEY, $_SESSION);
    }

    public function testSessionSignalSurvivesARequestThatTheShortFloorBlocked(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createAccount($user, Carbon::now()->subSeconds(3));

        $_SESSION[MailBadgeController::FORCE_SESSION_KEY] = true;

        $attempts = 0;
        $this->makeMiddleware($attempts)
            ->process($this->makeRequest('GET', '/logo'), $this->makeHandler());

        $this->assertSame(0, $attempts);

        // Zwischen Klick aufs Postfach und Rückkehr in den Tab laufen andere Anfragen
        // durch - ein Symbol, der Service Worker. Verbrauchte eine davon den Vermerk,
        // ohne dass ein Abgleich stattfand, stünde der Zähler danach wieder bis zum
        // Ablauf der regulären Wartezeit auf dem Stand von vor dem Lesen.
        $this->assertTrue(
            $_SESSION[MailBadgeController::FORCE_SESSION_KEY] ?? false,
            'Ein gebremster Vermerk muss für die nächste Anfrage erhalten bleiben.'
        );
    }

    public function testBadgeEndpointForcesRefreshInsideTheStalenessWindow(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createAccount($user, Carbon::now()->subMinutes(2));

        $attempts = 0;
        $this->makeMiddleware($attempts)
            ->process($this->makeRequest('GET', MailBadgeController::REFRESH_PATH), $this->makeHandler());

        $this->assertSame(
            1,
            $attempts,
            'Ein Aufruf des Badge-Endpunkts ist die Bitte um einen aktuellen Zählerstand.'
        );
    }

    public function testForcedRefreshStillHonorsTheShortFloor(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createAccount($user, Carbon::now()->subSeconds(3));

        $attempts = 0;
        $this->makeMiddleware($attempts)
            ->process($this->makeRequest('GET', MailBadgeController::REFRESH_PATH), $this->makeHandler());

        // Ohne Untergrenze baut jeder Fokuswechsel im Browser eine eigene
        // IMAP-Verbindung auf - beim schnellen Hin- und Herschalten zwischen Tabs
        // wären das Dutzende innerhalb weniger Sekunden.
        $this->assertSame(0, $attempts, 'Erzwungene Abgleiche brauchen trotzdem eine Untergrenze.');
    }

    public function testForcedRefreshRunsAgainOnceTheShortFloorHasPassed(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createAccount($user, Carbon::now()->subSeconds(30));

        $attempts = 0;
        $this->makeMiddleware($attempts)
            ->process($this->makeRequest('GET', MailBadgeController::REFRESH_PATH), $this->makeHandler());

        $this->assertSame(1, $attempts);
    }

    public function testForcedRefreshIsSkippedWhenTheBadgeIsDisabled(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $account = $this->createAccount($user, Carbon::now()->subMinutes(2));
        $account->mail_badge_enabled = false;
        $account->save();

        $_SESSION[MailBadgeController::FORCE_SESSION_KEY] = true;

        $attempts = 0;
        $this->makeMiddleware($attempts)
            ->process($this->makeRequest('GET', MailBadgeController::REFRESH_PATH), $this->makeHandler());

        $this->assertSame(0, $attempts, 'Ein abgeschaltetes Badge wird auch auf Zuruf nicht abgeglichen.');
    }
}
