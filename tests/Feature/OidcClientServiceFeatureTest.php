<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OidcClient;
use App\Services\Oidc\OidcClientService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Türsteherprüfung des Providers: Wer fragt da, und darf der Code wirklich
 * dorthin zurückgeschickt werden?
 *
 * Der Vergleich der Rücksprungadresse ist der sicherheitstragende Teil. Ein
 * Präfixvergleich ließe einen angehängten Pfad oder Query-Teil durch - und
 * damit die Umleitung des frisch ausgestellten Codes an einen fremden
 * Endpunkt.
 */
final class OidcClientServiceFeatureTest extends TestCase
{
    private const REDIRECT_URI = 'https://cloud.example.org/apps/user_oidc/code';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        OidcClient::query()->delete();
    }

    protected function tearDown(): void
    {
        OidcClient::query()->delete();
        parent::tearDown();
    }

    public function testCreatedClientRevealsItsSecretExactlyOnceAndStoresOnlyTheHash(): void
    {
        $service = new OidcClientService();

        $created = $service->createClient('Nextcloud', [self::REDIRECT_URI]);

        $this->assertNotSame('', $created['secret']);
        $this->assertGreaterThanOrEqual(32, strlen($created['secret']));

        $stored = (string) OidcClient::query()
            ->where('client_id', $created['client']->client_id)
            ->value('client_secret_hash');

        $this->assertStringNotContainsString($created['secret'], $stored);
        $this->assertTrue(password_verify($created['secret'], $stored));
    }

    public function testAuthenticationAcceptsTheRightSecretAndRejectsEverythingElse(): void
    {
        $service = new OidcClientService();
        $created = $service->createClient('Nextcloud', [self::REDIRECT_URI]);
        $clientId = (string) $created['client']->client_id;

        $this->assertNotNull($service->authenticate($clientId, $created['secret']));
        $this->assertNull($service->authenticate($clientId, 'falsch'));
        $this->assertNull($service->authenticate('gibt-es-nicht', $created['secret']));
        $this->assertNull($service->authenticate($clientId, ''));
    }

    public function testInactiveClientIsNeitherFoundNorAuthenticated(): void
    {
        $service = new OidcClientService();
        $created = $service->createClient('Nextcloud', [self::REDIRECT_URI]);
        $created['client']->is_active = false;
        $created['client']->save();

        $clientId = (string) $created['client']->client_id;

        $this->assertNull($service->findActiveClient($clientId));
        $this->assertNull($service->authenticate($clientId, $created['secret']));
    }

    public function testUntrustedClientIsRejectedBecauseThereIsNoConsentScreen(): void
    {
        $service = new OidcClientService();
        $created = $service->createClient('Fremd', [self::REDIRECT_URI], [], false);

        $this->assertFalse($service->isUsableForLogin($created['client']));
    }

    /**
     * @return iterable<string, array{0:string}>
     */
    public static function forgedRedirectUriProvider(): iterable
    {
        yield 'angehängter Pfad' => [self::REDIRECT_URI . '/evil'];
        yield 'angehängter Query' => [self::REDIRECT_URI . '?next=https://evil.example'];
        yield 'angehängtes Fragment' => [self::REDIRECT_URI . '#evil'];
        yield 'anderer Host' => ['https://evil.example/apps/user_oidc/code'];
        yield 'anderes Schema' => ['http://cloud.example.org/apps/user_oidc/code'];
        yield 'abweichende Großschreibung im Pfad' => ['https://cloud.example.org/apps/User_Oidc/code'];
        yield 'leer' => [''];
        yield 'nur Präfix' => ['https://cloud.example.org/apps/'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forgedRedirectUriProvider')]
    public function testOnlyTheExactRegisteredRedirectUriIsAccepted(string $candidate): void
    {
        $service = new OidcClientService();
        $client = $service->createClient('Nextcloud', [self::REDIRECT_URI])['client'];

        $this->assertFalse($service->isRedirectUriAllowed($client, $candidate));
    }

    public function testTheRegisteredRedirectUriItselfIsAccepted(): void
    {
        $service = new OidcClientService();
        $client = $service->createClient('Nextcloud', [self::REDIRECT_URI, 'https://cloud.example.org/x'])['client'];

        $this->assertTrue($service->isRedirectUriAllowed($client, self::REDIRECT_URI));
        $this->assertTrue($service->isRedirectUriAllowed($client, 'https://cloud.example.org/x'));
    }

    public function testPostLogoutRedirectIsCheckedTheSameWay(): void
    {
        $service = new OidcClientService();
        $client = $service->createClient(
            'Nextcloud',
            [self::REDIRECT_URI],
            ['https://cloud.example.org/']
        )['client'];

        $this->assertTrue($service->isPostLogoutRedirectAllowed($client, 'https://cloud.example.org/'));
        $this->assertFalse($service->isPostLogoutRedirectAllowed($client, 'https://cloud.example.org/x'));
        $this->assertFalse($service->isPostLogoutRedirectAllowed($client, 'https://evil.example/'));
    }

    public function testInsecureOrRelativeRedirectUrisAreRefusedAtRegistrationTime(): void
    {
        $service = new OidcClientService();

        foreach (['/apps/user_oidc/code', 'javascript:alert(1)', 'ftp://cloud.example.org/x', ''] as $uri) {
            $this->assertFalse(
                $service->isRegistrableRedirectUri($uri),
                $uri . ' darf sich nicht eintragen lassen.'
            );
        }

        $this->assertTrue($service->isRegistrableRedirectUri(self::REDIRECT_URI));
        // http nur für die Schleife auf dem eigenen Rechner - sonst liefe der
        // Code im Klartext über das Netz.
        $this->assertTrue($service->isRegistrableRedirectUri('http://localhost:8080/apps/user_oidc/code'));
        $this->assertFalse($service->isRegistrableRedirectUri('http://cloud.example.org/apps/user_oidc/code'));
    }
}
