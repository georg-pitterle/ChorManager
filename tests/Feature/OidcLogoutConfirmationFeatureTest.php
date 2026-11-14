<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Oidc\UserinfoController;
use App\Logging\RequestContext;
use App\Middleware\SecurityHeadersMiddleware;
use App\Models\OidcClient;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use App\Services\Oidc\AccessTokenService;
use App\Services\Oidc\IdTokenSigner;
use App\Services\Oidc\OidcClaimsBuilder;
use App\Services\Oidc\OidcClientService;
use App\Services\Oidc\OidcEndpoints;
use App\Services\Oidc\OidcRedirectOrigins;
use App\Services\Oidc\OidcSigningKeyService;
use App\Services\RememberLoginService;
use App\Services\SecretBoxCryptoService;
use App\Services\SessionAuthService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/**
 * Die Abmeldung über /oidc/logout fragt nach, wenn sie nicht nachweislich von
 * der angeschlossenen Anwendung kommt.
 *
 * Bisher meldete ein einfacher Aufruf der Adresse ab - ein Bild oder Link auf
 * einer fremden Seite genügte, um ein Mitglied ungefragt hinauszuwerfen. Den
 * Nachweis liefert die Anwendung als `id_token_hint`: das Anmeldezeugnis, das
 * sie von hier bekommen hat. Ist es echt und gehört es zur angemeldeten Person,
 * läuft die Abmeldung wie bisher sofort durch; sonst erscheint eine Seite mit
 * Rückfrage, deren Knopf per POST (mit CSRF-Token) abmeldet.
 */
final class OidcLogoutConfirmationFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use OidcTestEnvironment;

    private const POST_LOGOUT_URI = 'https://cloud.example.org/';

    private User $user;
    private OidcClient $client;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Tests\Unit\Bootstrap::setupTestDatabase();
        $this->enableOidcSigningSecret();
        $_ENV['APP_URL'] = 'https://chor.example.org';

        Capsule::connection()->beginTransaction();

        $this->user = User::create([
            'email' => 'logout-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Abmelde',
            'last_name' => 'Test',
            'is_active' => 1,
        ]);

        $this->client = (new OidcClientService())->createClient(
            'Nextcloud',
            ['https://cloud.example.org/apps/user_oidc/code'],
            [self::POST_LOGOUT_URI]
        )['client'];

        $this->keyService()->generateKey();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = ['user_id' => (int) $this->user->id, 'auth_epoch' => time()];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        $this->restoreOidcSigningSecret();
        parent::tearDown();
    }

    public function testPlainLinkAsksBeforeSigningOut(): void
    {
        $response = $this->logout('GET', $this->query());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame((int) $this->user->id, $_SESSION['user_id'] ?? null, 'Noch angemeldet');
        [$template, $data] = end($this->renderCalls);
        $this->assertSame('auth/oidc_logout_confirm.twig', $template);
        $this->assertSame((string) $this->client->client_id, $data['client_id']);
        $this->assertSame(self::POST_LOGOUT_URI, $data['post_logout_redirect_uri']);
        $this->assertSame('abc', $data['state']);
    }

    public function testValidHintFromTheApplicationSignsOutImmediately(): void
    {
        $response = $this->logout('GET', $this->query(['id_token_hint' => $this->idToken()]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(self::POST_LOGOUT_URI . '?state=abc', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    /**
     * Ein abgelaufenes Zeugnis zählt: Die Anwendung hält es nach der Anmeldung
     * oft stundenlang, und die Spezifikation lässt es als Hinweis ausdrücklich zu.
     */
    public function testExpiredHintStillCounts(): void
    {
        $hint = $this->idToken(['iat' => time() - 7200, 'exp' => time() - 3600]);

        $response = $this->logout('GET', $this->query(['id_token_hint' => $hint]));

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testHintOfAnotherMemberAsks(): void
    {
        $hint = $this->idToken(['sub' => 'cm-' . ((int) $this->user->id + 999)]);

        $response = $this->logout('GET', $this->query(['id_token_hint' => $hint]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame((int) $this->user->id, $_SESSION['user_id'] ?? null);
    }

    public function testHintForAnotherApplicationAsks(): void
    {
        $hint = $this->idToken(['aud' => 'eine-andere-anwendung']);

        $response = $this->logout('GET', $this->query(['id_token_hint' => $hint]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testForgedHintAsks(): void
    {
        [$header, , $signature] = explode('.', $this->idToken());
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'iss' => 'https://chor.example.org',
            'sub' => OidcClaimsBuilder::subjectFor($this->user),
            'aud' => (string) $this->client->client_id,
        ])), '+/', '-_'), '=');

        $response = $this->logout('GET', $this->query(['id_token_hint' => $header . '.' . $payload . '.' . $signature]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame((int) $this->user->id, $_SESSION['user_id'] ?? null);
    }

    public function testConfirmedPostSignsOut(): void
    {
        $response = $this->logout('POST', [], $this->query());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(self::POST_LOGOUT_URI . '?state=abc', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    /**
     * Wer nicht angemeldet ist, verliert nichts: Die Rückfrage entfällt, und die
     * Anwendung bekommt ihre Rücksprungadresse wie bisher.
     */
    public function testWithoutSessionTheRedirectHappensAsBefore(): void
    {
        $_SESSION = [];

        $response = $this->logout('GET', $this->query());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(self::POST_LOGOUT_URI . '?state=abc', $response->getHeaderLine('Location'));
    }

    public function testThePostRouteIsRegistered(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertStringContainsString(
            "\$app->post(OidcEndpoints::LOGOUT, [UserinfoController::class, 'logout']);",
            $routes
        );
    }

    /**
     * Der Knopf der Rückfrage schickt das Formular ab und landet per Weiterleitung
     * bei der Anwendung. Chrome prüft `form-action` auch auf diese Weiterleitung -
     * dieselbe Falle wie beim Login-Formular.
     */
    public function testConfirmationPageMayRedirectToThePostLogoutOrigin(): void
    {
        $middleware = new SecurityHeadersMiddleware('', static fn (): array => (new OidcRedirectOrigins())->forLoginForm());

        $response = $middleware->process(
            $this->makeRequest('GET', OidcEndpoints::LOGOUT),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertStringContainsString(
            "form-action 'self' https://cloud.example.org",
            $response->getHeaderLine('Content-Security-Policy')
        );
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $body
     */
    private function logout(string $method, array $query, array $body = []): ResponseInterface
    {
        return $this->controller()->logout(
            $this->makeRequest($method, OidcEndpoints::LOGOUT, $body, $query),
            $this->makeResponse()
        );
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function query(array $extra = []): array
    {
        return $extra + [
            'client_id' => (string) $this->client->client_id,
            'post_logout_redirect_uri' => self::POST_LOGOUT_URI,
            'state' => 'abc',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function idToken(array $overrides = []): string
    {
        return (new IdTokenSigner($this->keyService()))->sign($overrides + [
            'iss' => 'https://chor.example.org',
            'sub' => OidcClaimsBuilder::subjectFor($this->user),
            'aud' => (string) $this->client->client_id,
            'iat' => time(),
            'exp' => time() + 300,
        ]);
    }

    private function controller(): UserinfoController
    {
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data = []): ResponseInterface {
                $this->renderCalls[] = [$template, $data];
                return $response;
            }
        );

        return new UserinfoController(
            new AccessTokenService(),
            new OidcClaimsBuilder(),
            new OidcClientService(),
            new UserQuery(new NameFormatterService()),
            new SessionAuthService(new NameFormatterService(), new RequestContext()),
            new RememberLoginService(),
            new \Psr\Log\NullLogger(),
            new IdTokenSigner($this->keyService()),
            $twig
        );
    }

    private function keyService(): OidcSigningKeyService
    {
        return new OidcSigningKeyService(new SecretBoxCryptoService('OIDC_SIGNING_KEY_SECRET'));
    }
}
