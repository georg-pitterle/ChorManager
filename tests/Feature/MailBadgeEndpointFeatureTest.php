<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\MailBadgeController;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\MailBadgeViewService;
use App\Services\MailCredentialCryptoService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Der schlanke JSON-Endpunkt, den das Frontend beim Zurückwechseln in den Tab abfragt.
 *
 * Er hält den Zähler aktuell, ohne dass die ganze Seite neu geladen werden muss -
 * und damit auch dann, wenn das Postfach über einen externen Webmail-Link in einem
 * zweiten Tab geöffnet wurde und der Server davon gar nichts mitbekommt.
 */
final class MailBadgeEndpointFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

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

        $_SESSION = [];

        parent::tearDown();
    }

    private function createUser(): User
    {
        $this->user = User::create([
            'first_name' => 'Badge',
            'last_name' => 'Endpoint',
            'email' => 'badge.endpoint.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        return $this->user;
    }

    private function createAccount(User $user, int $unseen, bool $badgeEnabled = true): void
    {
        UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'someone@example.test',
            'imap_password_enc' => (new MailCredentialCryptoService())->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => $badgeEnabled,
            'mail_last_unseen_count' => $unseen,
            'mail_last_uid_seen' => '77',
            'mail_last_checked_at' => Carbon::now()->subSeconds(5),
        ]);
    }

    private function makeController(): MailBadgeController
    {
        return new MailBadgeController(new MailBadgeViewService(new NullLogger()));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return (array) json_decode((string) $response->getBody()->getContents(), true);
    }

    public function testEndpointReturnsTheCachedUnseenCount(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createAccount($user, 4);

        $response = $this->makeController()->show(
            $this->makeRequest('GET', MailBadgeController::REFRESH_PATH),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['unseen_count' => 4], $this->decode($response));
    }

    public function testEndpointReportsZeroUnreadMessages(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createAccount($user, 0);

        $response = $this->makeController()->show(
            $this->makeRequest('GET', MailBadgeController::REFRESH_PATH),
            $this->makeResponse()
        );

        // 0 muss als Zahl ankommen, nicht als null: Nur so blendet das Frontend die
        // rote Pille wieder aus, statt den alten Stand einfach stehen zu lassen.
        $this->assertSame(['unseen_count' => 0], $this->decode($response));
    }

    public function testEndpointReturnsNullWithoutAConfiguredMailbox(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;

        $response = $this->makeController()->show(
            $this->makeRequest('GET', MailBadgeController::REFRESH_PATH),
            $this->makeResponse()
        );

        $this->assertSame(['unseen_count' => null], $this->decode($response));
    }

    public function testEndpointReturnsNullWhenTheBadgeIsDisabled(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createAccount($user, 7, false);

        $response = $this->makeController()->show(
            $this->makeRequest('GET', MailBadgeController::REFRESH_PATH),
            $this->makeResponse()
        );

        $this->assertSame(['unseen_count' => null], $this->decode($response));
    }

    public function testEndpointForbidsBrowserCaching(): void
    {
        $user = $this->createUser();
        $_SESSION['user_id'] = $user->id;
        $this->createAccount($user, 2);

        $response = $this->makeController()->show(
            $this->makeRequest('GET', MailBadgeController::REFRESH_PATH),
            $this->makeResponse()
        );

        // Ohne no-store beantwortet der Browser den nächsten Fokuswechsel aus seiner
        // eigenen Kopie - also genau mit dem veralteten Zähler.
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * Der Endpunkt hängt nicht am Webmail-Modul.
     *
     * Gerade wer sein Postfach über einen externen Webmail-Link öffnet, bekommt vom
     * Server nichts mit - für diese Installationen ist die Fokus-Abfrage der einzige
     * Weg, den Zähler ohne Neuladen der Seite geradezurücken.
     *
     * Baut wie WebmailFeatureFlagTest eine minimale echte Slim-App und liest den
     * tatsächlichen RouteCollector aus. Hinweis: AppFactory::setContainer() verändert
     * prozessweiten statischen Zustand von Slim.
     */
    private function badgeRouteIsRegistered(bool $webmailEnabled): bool
    {
        $settings = ['modules' => ['webmail' => $webmailEnabled]];

        $container = new class ($settings) implements \Psr\Container\ContainerInterface {
            public function __construct(private array $settings)
            {
            }

            public function get(string $id): mixed
            {
                return $id === 'settings' ? $this->settings : null;
            }

            public function has(string $id): bool
            {
                return $id === 'settings';
            }
        };

        \Slim\Factory\AppFactory::setContainer($container);
        $app = \Slim\Factory\AppFactory::create();

        $routes = require dirname(__DIR__, 2) . '/src/Routes.php';
        $routes($app);

        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            if ($route->getPattern() !== MailBadgeController::REFRESH_PATH) {
                continue;
            }

            if (in_array('GET', $route->getMethods(), true)) {
                return true;
            }
        }

        return false;
    }

    public function testBadgeRouteIsRegisteredRegardlessOfTheWebmailModule(): void
    {
        $this->assertTrue($this->badgeRouteIsRegistered(true));
        $this->assertTrue($this->badgeRouteIsRegistered(false));
    }

    /**
     * Template und JavaScript teilen sich zwei Haltepunkte im Markup. Driften sie
     * auseinander, bleibt das Badge stumm stehen, ohne dass irgendwo ein Fehler
     * auftaucht - deshalb hier festgehalten.
     */
    public function testTemplateAndScriptShareTheSameMarkupHooks(): void
    {
        $partial = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/partials/navigation/user_menu.twig'
        );
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/mail-badge.js');

        $this->assertStringContainsString('data-mail-badge>', $partial);
        $this->assertStringContainsString('data-mail-badge-count>', $partial);

        $this->assertStringContainsString('[data-mail-badge]', $script);
        $this->assertStringContainsString('[data-mail-badge-count]', $script);
        $this->assertStringContainsString(MailBadgeController::REFRESH_PATH, $script);

        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/layout.twig');
        $this->assertStringContainsString('/js/mail-badge.js', $layout);
    }
}
