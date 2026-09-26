<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Oidc\AuthorizeController;
use App\Controllers\Oidc\TokenController;
use App\Controllers\Oidc\UserinfoController;
use App\Logging\RequestContext;
use App\Models\OidcAccessToken;
use App\Models\OidcAuthCode;
use App\Models\OidcClient;
use App\Models\Role;
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
use App\Services\RememberLoginService;
use App\Services\SecretBoxCryptoService;
use App\Services\SessionAuthService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Der ganze Weg: anmelden, Code holen, Code eintauschen, Ansprüche abrufen.
 *
 * Die drei sicherheitstragenden Stellen stehen hier als eigene Tests: PKCE, die
 * Einmaligkeit des Codes und der Vergleich der Rücksprungadresse. Wird eine
 * davon im Produktivcode sabotiert, muss genau der zugehörige Test rot werden.
 */
final class OidcAuthorizationFlowFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use OidcTestEnvironment;

    private const REDIRECT_URI = 'https://cloud.example.org/apps/user_oidc/code';

    private User $user;
    private OidcClient $client;
    private string $clientSecret;
    private string $codeVerifier;
    private RateLimiterService $rateLimiter;
    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();
        \Tests\Unit\Bootstrap::setupTestDatabase();
        $this->enableOidcSigningSecret();

        $_ENV['APP_URL'] = 'https://chor.example.org';
        $_SESSION = [];

        Capsule::connection()->beginTransaction();

        $this->user = User::create([
            'email' => 'oidc-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Maria',
            'last_name' => 'Musterfrau',
            'is_active' => 1,
        ]);

        $withGroup = Role::create([
            'name' => 'Vorstand ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 50,
            'external_group' => 'vorstand',
        ]);
        $withoutGroup = Role::create([
            'name' => 'Mitglied ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $this->user->roles()->attach([$withGroup->id, $withoutGroup->id]);

        $created = (new OidcClientService())->createClient('Nextcloud', [self::REDIRECT_URI]);
        $this->client = $created['client'];
        $this->clientSecret = $created['secret'];

        $this->codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->keyService()->generateKey();

        // Eigener Ablageort je Test: Sonst zählte die Bremse über Testläufe
        // hinweg weiter und ein späterer Test fiele ohne eigenes Zutun aus.
        $this->rateLimiter = new RateLimiterService(
            sys_get_temp_dir() . '/oidc-rate-' . bin2hex(random_bytes(6))
        );

        [, $this->logHandler] = $this->logger();
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

    public function testHappyPathHandsOutACodeAndExchangesItForATokenSet(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;

        $response = $this->authorize(['state' => 'zustand-123', 'nonce' => 'einmalwert']);

        $this->assertSame(302, $response->getStatusCode());
        $query = $this->queryOf($response);
        $this->assertSame('zustand-123', $query['state'], 'Der state muss unverändert zurückkommen.');
        $this->assertArrayHasKey('code', $query);

        $tokenResponse = $this->exchange($query['code']);
        $this->assertSame(200, $tokenResponse->getStatusCode());

        $payload = $this->decode($tokenResponse);
        $this->assertSame('Bearer', $payload['token_type']);
        $this->assertNotSame('', $payload['access_token']);

        $claims = $this->idTokenClaims($payload['id_token']);
        $this->assertSame('cm-' . (int) $this->user->id, $claims['sub']);
        $this->assertSame('https://chor.example.org', $claims['iss']);
        $this->assertSame((string) $this->client->client_id, $claims['aud']);
        $this->assertSame('einmalwert', $claims['nonce']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertSame('Maria Musterfrau', $claims['name']);
        $this->assertSame((string) $this->user->email, $claims['email']);
        $this->assertSame(['vorstand'], $claims['groups']);
    }

    public function testExistingAccountKeepsItsOwnSubjectFromExternalUid(): void
    {
        $this->user->external_uid = 'mmusterfrau';
        $this->user->save();
        $_SESSION['user_id'] = (int) $this->user->id;

        $query = $this->queryOf($this->authorize());
        $claims = $this->idTokenClaims($this->decode($this->exchange($query['code']))['id_token']);

        $this->assertSame('mmusterfrau', $claims['sub']);
        $this->assertSame('mmusterfrau', $claims['preferred_username']);
    }

    public function testAuthorizeWithoutPkceIsRejected(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;

        $response = $this->authorize(['code_challenge' => '']);

        $this->assertSame('invalid_request', $this->queryOf($response)['error']);
        $this->assertSame(0, OidcAuthCode::query()->count());
    }

    public function testAuthorizeWithPlainChallengeMethodIsRejected(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;

        $response = $this->authorize([
            'code_challenge' => $this->codeVerifier,
            'code_challenge_method' => 'plain',
        ]);

        $this->assertSame('invalid_request', $this->queryOf($response)['error']);
        $this->assertSame(0, OidcAuthCode::query()->count());
    }

    public function testAuthorizeRefusesAnUnregisteredRedirectUriWithoutRedirecting(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;

        $response = $this->authorize(['redirect_uri' => self::REDIRECT_URI . '/evil']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Location'), 'Kein Sprung auf eine ungeprüfte Adresse.');
        $this->assertSame(0, OidcAuthCode::query()->count());
    }

    public function testAuthorizeRefusesAnUnknownClientWithoutRedirecting(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;

        $response = $this->authorize(['client_id' => 'gibt-es-nicht']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Location'));
    }

    public function testInactiveMemberGetsNoCode(): void
    {
        $this->user->is_active = 0;
        $this->user->save();
        $_SESSION['user_id'] = (int) $this->user->id;

        $response = $this->authorize();

        $this->assertSame('access_denied', $this->queryOf($response)['error']);
        $this->assertSame(0, OidcAuthCode::query()->count());
    }

    public function testWrongCodeVerifierIsRejected(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;
        $query = $this->queryOf($this->authorize());

        $response = $this->exchange($query['code'], ['code_verifier' => 'etwas-ganz-anderes']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_grant', $this->decode($response)['error']);
    }

    public function testExpiredCodeIsRejected(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;
        $query = $this->queryOf($this->authorize());

        OidcAuthCode::query()->update(['expires_at' => date('Y-m-d H:i:s', time() - 5)]);

        $this->assertSame('invalid_grant', $this->decode($this->exchange($query['code']))['error']);
    }

    public function testSecondRedemptionFailsAndBurnsTheTokensFromTheFirst(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;
        $query = $this->queryOf($this->authorize());

        $first = $this->decode($this->exchange($query['code']));
        $this->assertArrayHasKey('access_token', $first);

        $second = $this->exchange($query['code']);
        $this->assertSame('invalid_grant', $this->decode($second)['error']);

        $this->assertNull(
            (new AccessTokenService())->resolve($first['access_token']),
            'Das Token aus der ersten Einlösung muss widerrufen sein.'
        );
        $this->assertNotNull(OidcAccessToken::query()->whereNotNull('revoked_at')->first());
    }

    public function testDivergingRedirectUriAtTokenTimeIsRejected(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;
        $query = $this->queryOf($this->authorize());

        $response = $this->exchange($query['code'], ['redirect_uri' => 'https://cloud.example.org/anders']);

        $this->assertSame('invalid_grant', $this->decode($response)['error']);
    }

    public function testWrongClientSecretIsRejectedAsInvalidClient(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;
        $query = $this->queryOf($this->authorize());

        $response = $this->exchange($query['code'], ['client_secret' => 'falsch']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_client', $this->decode($response)['error']);
    }

    public function testCodeOfOneClientCannotBeRedeemedByAnother(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;
        $query = $this->queryOf($this->authorize());

        $other = (new OidcClientService())->createClient('Zweiter', [self::REDIRECT_URI]);

        $response = $this->exchange($query['code'], [
            'client_id' => (string) $other['client']->client_id,
            'client_secret' => $other['secret'],
        ]);

        $this->assertSame('invalid_grant', $this->decode($response)['error']);
    }

    public function testUserinfoReturnsTheSameClaimsAndRefusesARevokedToken(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;
        $query = $this->queryOf($this->authorize());
        $tokens = $this->decode($this->exchange($query['code']));

        $response = $this->userinfo($tokens['access_token']);
        $this->assertSame(200, $response->getStatusCode());

        $claims = $this->decode($response);
        $this->assertSame('cm-' . (int) $this->user->id, $claims['sub']);
        $this->assertSame(['vorstand'], $claims['groups']);

        OidcAccessToken::query()->update(['revoked_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(401, $this->userinfo($tokens['access_token'])->getStatusCode());
    }

    public function testUserinfoRefusesAnExpiredTokenAndAnEmptyHeader(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;
        $query = $this->queryOf($this->authorize());
        $tokens = $this->decode($this->exchange($query['code']));

        $this->assertSame(401, $this->userinfo('')->getStatusCode());

        OidcAccessToken::query()->update(['expires_at' => date('Y-m-d H:i:s', time() - 5)]);
        $this->assertSame(401, $this->userinfo($tokens['access_token'])->getStatusCode());
    }

    public function testRevokingForAUserInvalidatesCodesAndTokens(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;
        $query = $this->queryOf($this->authorize());
        $tokens = $this->decode($this->exchange($query['code']));

        $revoked = (new AuthorizationCodeService())->revokeForUser((int) $this->user->id);

        $this->assertSame(0, $revoked['codes'], 'Der eingelöste Code war bereits verbraucht.');
        $this->assertSame(1, $revoked['tokens']);
        $this->assertNull((new AccessTokenService())->resolve($tokens['access_token']));
    }

    public function testLogoutAlsoInvalidatesTheRememberMeToken(): void
    {
        $cookieValue = (new RememberLoginService())->issueForUser(
            (int) $this->user->id,
            $this->makeRequest('POST', '/login')
        );
        $_COOKIE[RememberLoginService::COOKIE_NAME] = $cookieValue;

        try {
            $this->userinfoController()->logout(
                $this->makeRequest('GET', '/oidc/logout'),
                $this->makeResponse()
            );

            $this->assertNull(
                (new RememberLoginService())->validateCookieValue($cookieValue),
                'Ohne Entwerten des Cookies wäre die Sitzung bei der nächsten Anfrage wieder da.'
            );
        } finally {
            unset($_COOKIE[RememberLoginService::COOKIE_NAME]);
        }
    }

    public function testLogoutOnlyRedirectsToARegisteredAddress(): void
    {
        $withLogout = (new OidcClientService())->createClient(
            'Nextcloud',
            [self::REDIRECT_URI],
            ['https://cloud.example.org/']
        )['client'];

        $allowed = $this->userinfoController()->logout(
            $this->makeRequest('GET', '/oidc/logout', [], [
                'client_id' => (string) $withLogout->client_id,
                'post_logout_redirect_uri' => 'https://cloud.example.org/',
            ]),
            $this->makeResponse()
        );
        $this->assertSame('https://cloud.example.org/', $allowed->getHeaderLine('Location'));

        $forged = $this->userinfoController()->logout(
            $this->makeRequest('GET', '/oidc/logout', [], [
                'client_id' => (string) $withLogout->client_id,
                'post_logout_redirect_uri' => 'https://evil.example/',
            ]),
            $this->makeResponse()
        );
        $this->assertSame('/login', $forged->getHeaderLine('Location'));
    }

    // ---------------------------------------------------------------- Helfer

    /**
     * @param array<string, string> $overrides
     */
    private function authorize(array $overrides = []): ResponseInterface
    {
        $params = array_merge([
            'client_id' => (string) $this->client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'openid profile email groups',
            'state' => 'zustand',
            'code_challenge' => $this->challenge(),
            'code_challenge_method' => 'S256',
        ], $overrides);

        return $this->authorizeController()->authorize(
            $this->makeRequest('GET', '/oidc/authorize', [], $params),
            $this->makeResponse()
        );
    }

    /**
     * @param array<string, string> $overrides
     */
    private function exchange(string $code, array $overrides = []): ResponseInterface
    {
        $body = array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'client_id' => (string) $this->client->client_id,
            'client_secret' => $this->clientSecret,
            'code_verifier' => $this->codeVerifier,
        ], $overrides);

        return $this->tokenController()->issue(
            $this->makeRequest('POST', '/oidc/token', $body),
            $this->makeResponse()
        );
    }

    private function userinfo(string $token): ResponseInterface
    {
        $headers = $token === '' ? [] : ['Authorization' => 'Bearer ' . $token];

        return $this->userinfoController()->claims(
            $this->makeRequest('GET', '/oidc/userinfo', [], [], $headers),
            $this->makeResponse()
        );
    }

    private function authorizeController(): AuthorizeController
    {
        return new AuthorizeController(
            new OidcClientService(),
            new AuthorizationCodeService(),
            $this->userQuery(),
            new OidcSigningReadiness(fn(): OidcSigningKeyService => $this->keyService()),
            $this->rateLimiter
        );
    }

    private function tokenController(): TokenController
    {
        return new TokenController(
            new OidcClientService(),
            new AuthorizationCodeService(),
            new AccessTokenService(),
            new IdTokenSigner($this->keyService()),
            new OidcClaimsBuilder(),
            $this->userQuery(),
            $this->rateLimiter
        );
    }

    private function userinfoController(): UserinfoController
    {
        return new UserinfoController(
            new AccessTokenService(),
            new OidcClaimsBuilder(),
            new OidcClientService(),
            $this->userQuery(),
            new SessionAuthService(new NameFormatterService(), new RequestContext()),
            new RememberLoginService()
        );
    }

    private function userQuery(): UserQuery
    {
        return new UserQuery(new NameFormatterService());
    }

    private function keyService(): OidcSigningKeyService
    {
        return new OidcSigningKeyService(new SecretBoxCryptoService('OIDC_SIGNING_KEY_SECRET'));
    }

    private function challenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $this->codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(ResponseInterface $response): array
    {
        $location = $response->getHeaderLine('Location');
        $query = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode($response->getBody()->__toString(), true);
        $this->assertIsArray($decoded, 'Antwort war kein JSON: ' . $response->getBody()->__toString());

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
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
