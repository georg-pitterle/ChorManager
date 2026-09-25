<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Oidc\DiscoveryController;
use App\Models\OidcSigningKey;
use App\Services\Oidc\IdTokenSigner;
use App\Services\Oidc\OidcSigningKeyService;
use App\Services\SecretBoxCryptoService;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Aushängeschild des Providers.
 *
 * `user_oidc` in Nextcloud liest ausschließlich dieses Dokument, um die
 * Endpunkte zu finden. Fehlt dort ein Eintrag oder steht ein Verfahren drin,
 * das wir gar nicht anbieten, scheitert die Anmeldung erst beim Benutzer.
 */
final class OidcDiscoveryFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use OidcTestEnvironment;

    private const ENV_KEYS = ['FEATURE_OIDC', 'APP_URL'];

    /** @var array<string, array{env: string|null, server: string|null, getenv: string|false}> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $this->enableOidcSigningSecret();

        foreach (self::ENV_KEYS as $key) {
            $this->envBackup[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                // Die dritte Quelle zählt mit: paratest reicht die Umgebung als
                // echte Prozessumgebung an seine Worker weiter, und EnvHelper
                // liest sie als Letztes. Ohne sie sah dieser Test im parallelen
                // Lauf das FEATURE_OIDC=true aus der .env.
                'getenv' => getenv($key),
            ];
        }

        $_ENV['APP_URL'] = 'https://chor.example.org';
        OidcSigningKey::query()->delete();
    }

    protected function tearDown(): void
    {
        OidcSigningKey::query()->delete();

        foreach (self::ENV_KEYS as $key) {
            $backup = $this->envBackup[$key];
            if ($backup['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $backup['env'];
            }

            if ($backup['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $backup['server'];
            }

            if ($backup['getenv'] === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $backup['getenv']);
            }
        }

        $this->restoreOidcSigningSecret();
        parent::tearDown();
    }

    public function testDiscoveryDocumentNamesEveryEndpointTheClientNeeds(): void
    {
        $response = $this->controller()->configuration(
            $this->makeRequest('GET', '/.well-known/openid-configuration'),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $document = $this->decode($response->getBody()->__toString());

        $this->assertSame('https://chor.example.org', $document['issuer']);
        $this->assertSame('https://chor.example.org/oidc/authorize', $document['authorization_endpoint']);
        $this->assertSame('https://chor.example.org/oidc/token', $document['token_endpoint']);
        $this->assertSame('https://chor.example.org/oidc/userinfo', $document['userinfo_endpoint']);
        $this->assertSame('https://chor.example.org/oidc/jwks.json', $document['jwks_uri']);
        $this->assertSame('https://chor.example.org/oidc/logout', $document['end_session_endpoint']);
    }

    public function testDiscoveryOffersOnlyTheCodeFlowWithPkceAndRs256(): void
    {
        $document = $this->decode(
            $this->controller()->configuration(
                $this->makeRequest('GET', '/.well-known/openid-configuration'),
                $this->makeResponse()
            )->getBody()->__toString()
        );

        $this->assertSame(['code'], $document['response_types_supported']);
        $this->assertSame(['S256'], $document['code_challenge_methods_supported']);
        $this->assertSame(['RS256'], $document['id_token_signing_alg_values_supported']);
        $this->assertSame(['authorization_code'], $document['grant_types_supported']);
        $this->assertContains('openid', $document['scopes_supported']);
        $this->assertContains('groups', $document['scopes_supported']);
        $this->assertContains('groups', $document['claims_supported']);

        // `plain` würde PKCE aushebeln: Der Verifier stünde dann unverschlüsselt
        // in der Authorize-Anfrage und wäre für jeden mitlesenden Zwischenschritt
        // dasselbe Geheimnis wie beim Einlösen.
        $this->assertNotContains('plain', $document['code_challenge_methods_supported']);
    }

    public function testJwksCarriesTheActiveKeyAndMayBeCachedBriefly(): void
    {
        $this->keyService()->generateKey();

        $response = $this->controller()->jwks(
            $this->makeRequest('GET', '/oidc/jwks.json'),
            $this->makeResponse()
        );

        $document = $this->decode($response->getBody()->__toString());

        $this->assertCount(1, $document['keys']);
        $this->assertSame('RSA', $document['keys'][0]['kty']);
        $this->assertStringContainsString('max-age=', $response->getHeaderLine('Cache-Control'));
    }

    public function testDiscoveryIsAlsoCacheableSoTheClientNeedNotAskEveryTime(): void
    {
        $response = $this->controller()->configuration(
            $this->makeRequest('GET', '/.well-known/openid-configuration'),
            $this->makeResponse()
        );

        $this->assertStringContainsString('max-age=', $response->getHeaderLine('Cache-Control'));
    }

    public function testSettingsExposeTheOidcFeatureFlagWithFalseDefault(): void
    {
        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        $builder = new ContainerBuilder();
        $defineSettings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $defineSettings($builder);

        $this->assertFalse($builder->build()->get('settings')['modules']['oidc']);
    }

    public function testOidcRoutesAreRegisteredOnlyInsideTheFeatureGate(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $gate = strpos($routes, "\$settings['modules']['oidc'] ?? false");
        $this->assertNotFalse($gate, 'Die OIDC-Routen brauchen ein Feature-Gate.');

        foreach (['DISCOVERY', 'JWKS', 'TOKEN', 'USERINFO', 'LOGOUT', 'AUTHORIZE'] as $endpoint) {
            $reference = 'OidcEndpoints::' . $endpoint;
            $position = strpos($routes, $reference);
            $this->assertNotFalse($position, $reference . ' ist nicht registriert.');
            $this->assertGreaterThan($gate, $position, $reference . ' steht außerhalb des Feature-Gates.');
        }
    }

    private function controller(): DiscoveryController
    {
        return new DiscoveryController(new IdTokenSigner($this->keyService()));
    }

    private function keyService(): OidcSigningKeyService
    {
        return new OidcSigningKeyService(new SecretBoxCryptoService('OIDC_SIGNING_KEY_SECRET'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'Antwort war kein JSON: ' . $json);

        return $decoded;
    }
}
