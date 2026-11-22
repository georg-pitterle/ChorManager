<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\RequestContext;
use App\Middleware\AuthMiddleware;
use App\Models\AppSetting;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Util\PasswordHasher;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

/**
 * Eine erzwungene Abmeldung hinterlässt eine Zeile im Protokoll.
 *
 * Die AuthMiddleware beendet Sitzungen an drei Stellen: an der globalen Sperre
 * nach einer Backup-Wiederherstellung, an einem fehlenden oder stillgelegten
 * Konto, und an der Kontogrenze nach einem Passwortwechsel. Protokolliert wurde
 * nur das Setzen dieser Sperren, nie ihr Greifen - in der täglichen Log-Analyse
 * war "ich werde dauernd abgemeldet" deshalb nicht nachvollziehbar, und welcher
 * der drei Anlässe es war, schon gar nicht.
 *
 * Geprüft wird beides: dass jeder Anlass seinen eigenen Grund nennt, und dass
 * eine Sitzung, die durchläuft, keine Zeile erzeugt. Ohne die zweite Hälfte
 * wäre eine Zeile je Anfrage genauso "grün" wie eine je beendeter Sitzung.
 */
final class AuthMiddlewareSessionEndLoggingFeatureTest extends TestCase
{
    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->logs = new TestHandler();
        AppSetting::query()->where('setting_key', 'session_valid_after')->delete();
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

    public function testASessionThatPassesLogsNothing(): void
    {
        $user = $this->createUser();
        $_SESSION = ['user_id' => (int) $user->id, 'auth_epoch' => time()];

        $response = $this->runMiddleware();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [],
            $this->endedRecords(),
            'Eine durchgelassene Anfrage darf keine Abmeldung protokollieren.'
        );
    }

    public function testGlobalLockNamesItselfAsTheReason(): void
    {
        $user = $this->createUser();
        $_SESSION = ['user_id' => (int) $user->id, 'auth_epoch' => time() - 60];
        AppSetting::putText('session_valid_after', (string) time());

        $this->assertRedirectsToLogin($this->runMiddleware());
        $this->assertSame('revoked_globally', $this->singleReason());
    }

    /**
     * Stillgelegtes und gelöschtes Konto nennen denselben Grund.
     *
     * Das ist Absicht und keine Ungenauigkeit: `UserQuery::findForSession()`
     * filtert auf `is_active = 1`, ein stillgelegtes Konto erreicht die
     * Middleware also schon nicht mehr als Modell. Zwei Gründe zu vergeben hieße,
     * eine Unterscheidung zu behaupten, die diese Stelle nicht treffen kann - und
     * der eine davon käme nie vor. Die Log-Analyse läse daraus, dass eine
     * Stilllegung niemanden abmeldet.
     */
    public function testDeactivatedAndDeletedAccountShareOneReason(): void
    {
        $deactivated = $this->createUser();
        $deactivated->is_active = 0;
        $deactivated->save();
        $_SESSION = ['user_id' => (int) $deactivated->id, 'auth_epoch' => time()];

        $this->assertRedirectsToLogin($this->runMiddleware());
        $this->assertSame('user_unavailable', $this->singleReason());

        $this->logs->clear();

        $deleted = $this->createUser();
        $_SESSION = ['user_id' => (int) $deleted->id, 'auth_epoch' => time()];
        $deleted->delete();

        $this->assertRedirectsToLogin($this->runMiddleware());
        $this->assertSame('user_unavailable', $this->singleReason());
    }

    public function testAccountBoundaryAfterAPasswordChangeNamesItselfAsTheReason(): void
    {
        $user = $this->createUser();
        $user->sessions_valid_after = time();
        $user->save();
        $_SESSION = ['user_id' => (int) $user->id, 'auth_epoch' => time() - 60];

        $this->assertRedirectsToLogin($this->runMiddleware());
        $this->assertSame('revoked_for_account', $this->singleReason());
    }

    /**
     * Der Logger muss aus dem Container kommen.
     *
     * Er ist am Konstruktor optional und fällt auf den NullLogger zurück, damit
     * die acht Testklassen, die die Middleware selbst bauen, kein weiteres
     * Argument brauchen. PHP-DI setzt an einem Parameter mit Vorgabe aber nichts
     * ein, sondern lässt die Vorgabe stehen: Ohne die ausdrückliche Verdrahtung
     * in Dependencies.php stand dort der NullLogger, und keine der Zeilen dieser
     * Testklasse wäre im Betrieb jemals angekommen - sichtbar an der Middleware
     * selbst ist das nicht, nur am gebauten Container.
     */
    public function testTheContainerWiresARealLogger(): void
    {
        $builder = new ContainerBuilder();
        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($builder);
        $container = $builder->build();

        $middleware = $container->get(AuthMiddleware::class);
        $logger = (new \ReflectionObject($middleware))->getProperty('logger')->getValue($middleware);

        $this->assertNotInstanceOf(
            NullLogger::class,
            $logger,
            'Ohne Verdrahtung in Dependencies.php landet auth.session.ended im Nichts.'
        );
        $this->assertSame($container->get(LoggerInterface::class), $logger);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function endedRecords(): array
    {
        $ended = [];

        foreach ($this->logs->getRecords() as $record) {
            if (($record->context['event'] ?? null) === 'auth.session.ended') {
                $ended[] = $record->context;
            }
        }

        return $ended;
    }

    private function singleReason(): string
    {
        $ended = $this->endedRecords();

        $this->assertCount(1, $ended, 'Genau eine Zeile je beendeter Sitzung.');

        return (string) $ended[0]['reason'];
    }

    private function runMiddleware(): ResponseInterface
    {
        $names = new NameFormatterService();
        $middleware = new AuthMiddleware(
            new UserQuery($names),
            new RememberLoginService(),
            new SessionAuthService($names, new RequestContext()),
            new Logger('test', [$this->logs])
        );

        return $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );
    }

    private function assertRedirectsToLogin(ResponseInterface $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('/login', $response->getHeaderLine('Location'));

        // Die Sitzung muss weg sein - sonst protokolliert die Middleware eine
        // Abmeldung, die nicht stattgefunden hat.
        $this->assertArrayNotHasKey('user_id', $_SESSION);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Sitzungsende',
            'last_name' => 'Protokoll',
            'email' => 'session.end.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('Old-Password-1'),
            'is_active' => 1,
        ]);
    }
}
