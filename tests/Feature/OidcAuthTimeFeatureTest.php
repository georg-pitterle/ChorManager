<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Oidc\AuthorizeController;
use App\Controllers\Oidc\TokenController;
use App\Models\OidcAuthCode;
use App\Models\OidcClient;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use App\Services\Oidc\AccessTokenService;
use App\Services\Oidc\AuthorizationCodeService;
use App\Services\Oidc\IdTokenSigner;
use App\Services\Oidc\OidcClaimsBuilder;
use App\Services\Oidc\OidcClientService;
use App\Services\Oidc\OidcSigningKeyService;
use App\Services\Oidc\OidcSigningReadiness;
use App\Services\RateLimiterService;
use App\Services\SecretBoxCryptoService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * `auth_time` im Anmeldezeugnis sagt, wann sich die Person angemeldet hat - nicht,
 * wann das Zeugnis ausgestellt wurde.
 *
 * Der Unterschied ist der ganze Zweck der Angabe. Eine angeschlossene Anwendung
 * fragt damit "wie lange ist der Nachweis her?", um nach einer Frist eine neue
 * Anmeldung zu verlangen. Stünde dort der Ausstellungszeitpunkt, wäre die Antwort
 * immer "gerade eben" und die Frist nie erreicht - auch bei einer Sitzung, die
 * über "Angemeldet bleiben" seit Wochen läuft.
 *
 * Maßgeblich ist `$_SESSION['auth_epoch']`: der Zeitpunkt, an dem diese Sitzung
 * durch Vorlage eines Nachweises entstand. Der Rechte-Abzug bei jeder Anfrage
 * lässt ihn stehen (SessionAuthService), eine Abmeldung nimmt ihn mit.
 */
final class OidcAuthTimeFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use OidcTestEnvironment;

    private const REDIRECT_URI = 'https://cloud.example.org/apps/user_oidc/code';

    private User $user;
    private OidcClient $client;
    private string $clientSecret;
    private string $codeVerifier;
    private RateLimiterService $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();
        \Tests\Unit\Bootstrap::setupTestDatabase();
        $this->enableOidcSigningSecret();

        $_ENV['APP_URL'] = 'https://chor.example.org';
        $_SESSION = [];

        Capsule::connection()->beginTransaction();

        $this->user = User::create([
            'email' => 'authtime-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Maria',
            'last_name' => 'Musterfrau',
            'is_active' => 1,
        ]);

        $created = (new OidcClientService())->createClient('Nextcloud', [self::REDIRECT_URI]);
        $this->client = $created['client'];
        $this->clientSecret = $created['secret'];
        $this->codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->keyService()->generateKey();
        $this->rateLimiter = new RateLimiterService(
            sys_get_temp_dir() . '/oidc-authtime-' . bin2hex(random_bytes(6))
        );
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

    /**
     * Der Kern: Eine drei Wochen alte Sitzung bekommt ein Zeugnis, das drei
     * Wochen zurückweist. Vorher stand dort die Ausstellungszeit, und die Frist
     * einer angeschlossenen Anwendung war damit wirkungslos.
     */
    public function testAuthTimeIsTheSessionLoginAndNotTheTokenIssueTime(): void
    {
        $loggedInAt = time() - 21 * 24 * 3600;
        $_SESSION = ['user_id' => (int) $this->user->id, 'auth_epoch' => $loggedInAt];

        $claims = $this->idTokenClaims($this->runFlow());

        $this->assertArrayHasKey('auth_time', $claims);
        $this->assertSame($loggedInAt, $claims['auth_time']);
        $this->assertLessThan(
            $claims['iat'] - 20 * 24 * 3600,
            $claims['auth_time'],
            'Die Anmeldung liegt deutlich vor der Ausstellung - genau das muss ablesbar sein.'
        );
    }

    /**
     * Die Angabe reist im Code mit, nicht in der Uhr des Token-Endpunkts: Der
     * Endpunkt sieht keine Sitzung, er bedient eine Anwendung ohne Browser.
     */
    public function testTheAuthorizationCodeCarriesTheAuthenticationTime(): void
    {
        $loggedInAt = time() - 3600;
        $_SESSION = ['user_id' => (int) $this->user->id, 'auth_epoch' => $loggedInAt];

        $this->queryOf($this->authorize());

        $code = OidcAuthCode::query()->firstOrFail();
        $this->assertNotNull($code->authenticated_at);
        $this->assertSame($loggedInAt, $code->authenticated_at->getTimestamp());
    }

    /**
     * Eine Sitzung ohne `auth_epoch` - etwa eine, die schon vor diesem Ausbau
     * lief - liefert keine Angabe. Eine fehlende Angabe ist ehrlich, eine
     * geratene nicht; der Standard verlangt sie nur bei einer Altersanfrage, und
     * die bietet dieser Anbieter nicht an.
     */
    public function testAuthTimeIsOmittedWhenTheSessionDoesNotKnowIt(): void
    {
        $_SESSION = ['user_id' => (int) $this->user->id];

        $claims = $this->idTokenClaims($this->runFlow());

        $this->assertArrayNotHasKey('auth_time', $claims);
        $this->assertSame('cm-' . (int) $this->user->id, $claims['sub'], 'Der übrige Satz bleibt vollständig.');
    }

    /**
     * Nicht die Zeit des Rechte-Abzugs: AuthMiddleware ruft
     * SessionAuthService::setAuthenticatedUser() bei jeder geschützten Anfrage
     * auf. Würde der Aufruf `auth_epoch` neu setzen, wäre die Angabe wieder die
     * Ausstellungszeit - nur auf einem Umweg.
     */
    public function testThePermissionRefreshDoesNotMoveTheAuthenticationTime(): void
    {
        $loggedInAt = time() - 7 * 24 * 3600;
        $_SESSION = ['user_id' => (int) $this->user->id, 'auth_epoch' => $loggedInAt];

        (new \App\Services\SessionAuthService(
            new NameFormatterService(),
            new \App\Logging\RequestContext()
        ))->setAuthenticatedUser($this->user->fresh(['roles', 'voiceGroups']));

        $this->assertSame($loggedInAt, $_SESSION['auth_epoch']);
        $this->assertSame($loggedInAt, $this->idTokenClaims($this->runFlow())['auth_time']);
    }

    private function runFlow(): string
    {
        $query = $this->queryOf($this->authorize());
        $this->assertArrayHasKey('code', $query, 'Ohne Code gibt es nichts zu prüfen.');

        $payload = json_decode($this->exchange($query['code'])->getBody()->__toString(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('id_token', $payload, 'Antwort: ' . json_encode($payload));

        return $payload['id_token'];
    }

    /** @param array<string, string> $overrides */
    private function authorize(array $overrides = []): ResponseInterface
    {
        $params = array_merge([
            'client_id' => (string) $this->client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => 'zustand',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], $overrides);

        return (new AuthorizeController(
            new OidcClientService(),
            new AuthorizationCodeService(),
            new UserQuery(new NameFormatterService()),
            new OidcSigningReadiness(fn (): OidcSigningKeyService => $this->keyService()),
            $this->rateLimiter
        ))->authorize(
            $this->makeRequest('GET', '/oidc/authorize', [], $params),
            $this->makeResponse()
        );
    }

    private function exchange(string $code): ResponseInterface
    {
        return (new TokenController(
            new OidcClientService(),
            new AuthorizationCodeService(),
            new AccessTokenService(),
            new IdTokenSigner($this->keyService()),
            new OidcClaimsBuilder(),
            new UserQuery(new NameFormatterService()),
            $this->rateLimiter
        ))->issue(
            $this->makeRequest('POST', '/oidc/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => self::REDIRECT_URI,
                'client_id' => (string) $this->client->client_id,
                'client_secret' => $this->clientSecret,
                'code_verifier' => $this->codeVerifier,
            ]),
            $this->makeResponse()
        );
    }

    private function keyService(): OidcSigningKeyService
    {
        return new OidcSigningKeyService(new SecretBoxCryptoService('OIDC_SIGNING_KEY_SECRET'));
    }

    /** @return array<string, string> */
    private function queryOf(ResponseInterface $response): array
    {
        $location = $response->getHeaderLine('Location');
        $this->assertNotSame('', $location, 'Keine Weiterleitung, Status ' . $response->getStatusCode());

        $query = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return array<string, mixed> */
    private function idTokenClaims(string $jwt): array
    {
        $payload = explode('.', $jwt)[1];
        $padded = strtr($payload, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = json_decode((string) base64_decode($padded, true), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
