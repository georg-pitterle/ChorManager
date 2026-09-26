<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Oidc\AuthorizeController;
use App\Models\OidcAuthCode;
use App\Models\OidcClient;
use App\Models\OidcSigningKey;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use App\Services\Oidc\AuthorizationCodeService;
use App\Services\Oidc\OidcClientService;
use App\Services\Oidc\OidcSigningKeyService;
use App\Services\Oidc\OidcSigningReadiness;
use App\Services\RateLimiterService;
use App\Services\SecretBoxCryptoService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Was geschieht, wenn der Provider halb eingerichtet ist.
 *
 * Das Modul lässt sich über FEATURE_OIDC einschalten, ohne dass
 * OIDC_SIGNING_KEY_SECRET gesetzt oder je ein Schlüssel erzeugt worden wäre.
 * Der Signierschlüssel wird aber erst beim Tausch am Token-Endpunkt gebraucht -
 * ohne Prüfung liefe die Anmeldung bis dahin durch: Das Mitglied meldet sich an,
 * springt zur anderen Anwendung zurück, ein Code liegt in der Datenbank, und
 * erst dann bricht es mit 500 ab. Der Fehler erschiene am unverständlichsten
 * Punkt, und jeder Versuch hinterließe eine Zeile.
 *
 * Deshalb wird schon am Authorize-Endpunkt geprüft, ob überhaupt signiert
 * werden kann.
 */
final class OidcMisconfigurationFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use OidcTestEnvironment;

    private const REDIRECT_URI = 'https://cloud.example.org/apps/user_oidc/code';

    /** Ein PKCE-Verifier bleibt technisch ASCII. naming:ascii */
    private const CODE_VERIFIER = 'test-code-verifier-0123456789abcdefghijk';

    private User $user;
    private OidcClient $client;
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
            'email' => 'halb-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Halb',
            'last_name' => 'Eingerichtet',
            'is_active' => 1,
        ]);

        $this->client = (new OidcClientService())->createClient('Nextcloud', [self::REDIRECT_URI])['client'];
        $this->rateLimiter = new RateLimiterService(
            sys_get_temp_dir() . '/oidc-misconfig-' . bin2hex(random_bytes(6))
        );

        $_SESSION['user_id'] = (int) $this->user->id;
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

    public function testWithAUsableKeyTheCodeIsHandedOutAsBefore(): void
    {
        $this->generateKey();

        $query = $this->queryOf($this->authorize($this->readyReadiness()));

        $this->assertArrayHasKey('code', $query);
        $this->assertSame(1, OidcAuthCode::query()->count());
    }

    public function testWithoutAnySigningKeyNoCodeIsHandedOut(): void
    {
        OidcSigningKey::query()->delete();

        $response = $this->authorize($this->readyReadiness());

        $this->assertSame('temporarily_unavailable', $this->queryOf($response)['error']);
        $this->assertSame(
            0,
            OidcAuthCode::query()->count(),
            'Ein Code, der sich nie eintauschen lässt, darf gar nicht erst entstehen.'
        );
    }

    public function testAnUnreadableKeyIsTreatedLikeAMissingOne(): void
    {
        $this->generateKey();

        // Der Schlüssel steht da, lässt sich aber nicht öffnen - genau die Lage
        // bei fehlendem oder vertauschtem OIDC_SIGNING_KEY_SECRET.
        $brokenReadiness = new OidcSigningReadiness(static function (): never {
            throw new RuntimeException('OIDC_SIGNING_KEY_SECRET is not configured correctly');
        });

        $response = $this->authorize($brokenReadiness);

        $this->assertSame('temporarily_unavailable', $this->queryOf($response)['error']);
        $this->assertSame(0, OidcAuthCode::query()->count());
    }

    public function testTheStateSurvivesTheRefusalSoTheClientCanMatchItUp(): void
    {
        OidcSigningKey::query()->delete();

        $query = $this->queryOf($this->authorize($this->readyReadiness(), ['state' => 'zustand-77']));

        $this->assertSame('zustand-77', $query['state']);
    }

    public function testTheRefusalGoesBackToTheClientAndNotToAForgedAddress(): void
    {
        OidcSigningKey::query()->delete();

        $response = $this->authorize($this->readyReadiness(), ['redirect_uri' => self::REDIRECT_URI . '/evil']);

        // Erst der Client, dann die Betriebsbereitschaft: Eine ungeprüfte
        // Rücksprungadresse bekommt gar keine Antwort, auch keine Fehlermeldung.
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Location'));
    }

    // ----------------------------------------------- bin/oidc_admin.php

    public function testTheAdminScriptExplainsAMissingSecretInsteadOfCrashing(): void
    {
        [$output, $exitCode] = $this->invokeAdmin('key:generate', ['OIDC_SIGNING_KEY_SECRET' => '']);

        $this->assertSame(1, $exitCode, 'Ein fehlgeschlagener Befehl muss das auch am Rückgabewert zeigen.');
        $this->assertStringContainsString('OIDC_SIGNING_KEY_SECRET', $output);
        $this->assertStringNotContainsString('Fatal error', $output);
        $this->assertStringNotContainsString('Stack trace', $output);
    }

    public function testSubcommandsWithoutKeyBusinessRunWithoutTheSecret(): void
    {
        [$output, $exitCode] = $this->invokeAdmin('group:list', ['OIDC_SIGNING_KEY_SECRET' => '']);

        $this->assertSame(
            0,
            $exitCode,
            'group:list hat mit dem Signierschlüssel nichts zu tun und darf ohne ihn laufen.'
        );
        $this->assertStringNotContainsString('Fatal error', $output);
    }

    // ---------------------------------------------------------- Helfer

    private function generateKey(): void
    {
        (new OidcSigningKeyService(new SecretBoxCryptoService('OIDC_SIGNING_KEY_SECRET')))->generateKey();
    }

    private function readyReadiness(): OidcSigningReadiness
    {
        return new OidcSigningReadiness(
            fn(): OidcSigningKeyService => new OidcSigningKeyService(
                new SecretBoxCryptoService('OIDC_SIGNING_KEY_SECRET')
            )
        );
    }

    /**
     * @param array<string, string> $overrides
     */
    private function authorize(OidcSigningReadiness $readiness, array $overrides = []): ResponseInterface
    {
        $challenge = rtrim(
            strtr(base64_encode(hash('sha256', self::CODE_VERIFIER, true)), '+/', '-_'),
            '='
        );

        $params = array_merge([
            'client_id' => (string) $this->client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'openid profile email groups',
            'state' => 'zustand',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], $overrides);

        $controller = new AuthorizeController(
            new OidcClientService(),
            new AuthorizationCodeService(),
            new UserQuery(new NameFormatterService()),
            $readiness,
            $this->rateLimiter
        );

        return $controller->authorize(
            $this->makeRequest('GET', '/oidc/authorize', [], $params),
            $this->makeResponse()
        );
    }

    /**
     * @param array<string, string> $env
     * @return array{0:string, 1:int}
     */
    private function invokeAdmin(string $command, array $env): array
    {
        $script = dirname(__DIR__, 2) . '/bin/oidc_admin.php';

        $assignments = '';
        foreach ($env as $name => $value) {
            $assignments .= $name . '=' . escapeshellarg($value) . ' ';
        }

        $invocation = sprintf(
            '%s%s %s %s 2>&1',
            $assignments,
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($command)
        );

        $output = [];
        $status = 0;
        exec($invocation, $output, $status);

        return [implode("\n", $output), $status];
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(ResponseInterface $response): array
    {
        $query = [];
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }
}
