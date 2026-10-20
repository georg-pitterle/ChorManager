<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Oidc\TokenController;
use App\Queries\UserQuery;
use App\Services\Oidc\AccessTokenService;
use App\Services\Oidc\AuthorizationCodeService;
use App\Services\Oidc\IdTokenSigner;
use App\Services\Oidc\OidcClaimsBuilder;
use App\Services\Oidc\OidcClientService;
use App\Services\Oidc\OidcSigningKeyService;
use App\Services\SecretBoxCryptoService;
use App\Services\RateLimiterService;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

/**
 * Die Bremse am Token-Endpunkt muss vor dem Ausweis greifen, nicht hinter ihm.
 *
 * `/oidc/token` ist öffentlich und ohne Sitzung erreichbar, und jeder Aufruf
 * kostet einen Durchlauf von `password_verify()` - auch der mit unbekannter
 * Client-Kennung, denn `OidcClientService::authenticate()` vergleicht dann
 * bewusst gegen `PasswordHasher::dummyHash()`, damit die Antwortdauer nichts
 * verrät. Ein bcrypt-Durchlauf kostet im Container rund 160 ms.
 *
 * Die Bremse stand hinter `authenticate()` und zählte auf
 * `oidc:token:{client_id}`. Beides zusammen hob sie für einen nicht
 * angemeldeten Aufrufer auf: Ein falscher Ausweis flog schon vorher heraus und
 * wurde nie gezählt, und wer die Kennung je Aufruf wechselte, bekam ohnehin
 * jedes Mal einen frischen Zählstand. Damit ließ sich die Maschine mit
 * beliebig vielen bcrypt-Durchläufen beschäftigen.
 *
 * AuthController::processLogin() und PasswordResetController::processReset()
 * machen es seit je anders herum - erst zählen, dann rechnen - und setzen den
 * Zählstand bei Erfolg zurück, damit der ehrliche Weg nie an die Grenze stößt.
 * Dieser Test hält dieselbe Reihenfolge für den Token-Endpunkt fest.
 */
final class OidcTokenEndpointRateLimitFeatureTest extends TestCase
{
    use OidcTestEnvironment;

    private const ATTACKER_IP = '203.0.113.77';

    private string $storeDir;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $this->enableOidcSigningSecret();

        $this->storeDir = sys_get_temp_dir() . '/oidc-token-limit-' . bin2hex(random_bytes(6));
        mkdir($this->storeDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);

        $this->restoreOidcSigningSecret();
        parent::tearDown();
    }

    /**
     * Der Kern: Eine je Aufruf gewechselte Client-Kennung darf die Bremse nicht
     * umgehen. Gezählt wird die Quelle, nicht der Name, den sie sich gibt.
     */
    public function testRotatingClientIdFromOneSourceStillHitsTheBrake(): void
    {
        $controller = $this->createController();

        $statuses = [];
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $response = $controller->issue(
                $this->tokenRequest('unbekannt-' . $attempt, 'falsches-geheimnis'),
                new Response()
            );
            $statuses[] = $response->getStatusCode();
        }

        $this->assertContains(
            429,
            $statuses,
            'Wechselnde Client-Kennungen von derselben Quelle liefen ungebremst durch.'
        );
    }

    /**
     * Die Bremse greift, bevor das Geheimnis geprüft wird. Sonst kostet jeder
     * abgewiesene Aufruf trotzdem seinen bcrypt-Durchlauf - der Grund, aus dem
     * die Bremse überhaupt dasteht.
     */
    public function testBrakeAnswersBeforeAnyPasswordHashIsComputed(): void
    {
        $controller = $this->createController();

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $controller->issue(
                $this->tokenRequest('unbekannt-' . $attempt, 'falsches-geheimnis'),
                new Response()
            );
        }

        // Ab hier ist der Zählstand erschöpft. Ein weiterer Aufruf darf nicht
        // mehr rechnen, also messbar schneller sein als ein bcrypt-Durchlauf.
        $start = hrtime(true);
        $response = $controller->issue(
            $this->tokenRequest('noch-eine-kennung', 'falsches-geheimnis'),
            new Response()
        );
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->assertSame(429, $response->getStatusCode());
        $this->assertLessThan(
            50.0,
            $elapsedMs,
            sprintf('Der abgebremste Aufruf brauchte %.1f ms - es wurde noch gehasht.', $elapsedMs)
        );
    }

    /**
     * Die Gegenprobe zur Grenze: Eine andere Quelle hat ihren eigenen
     * Zählstand. Ohne das sperrte ein einziger Angreifer den Endpunkt für alle
     * angeschlossenen Anwendungen - aus der Bremse wäre ein Hebel geworden.
     */
    public function testAnotherSourceKeepsItsOwnBudget(): void
    {
        $controller = $this->createController();

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $controller->issue(
                $this->tokenRequest('unbekannt-' . $attempt, 'falsches-geheimnis'),
                new Response()
            );
        }

        $response = $controller->issue(
            $this->tokenRequest('unbekannt-neu', 'falsches-geheimnis', '198.51.100.9'),
            new Response()
        );

        $this->assertNotSame(
            429,
            $response->getStatusCode(),
            'Die Bremse einer Quelle darf keine andere Quelle treffen.'
        );
    }

    private function createController(): TokenController
    {
        return new TokenController(
            new OidcClientService(),
            new AuthorizationCodeService(),
            new AccessTokenService(),
            new IdTokenSigner(new OidcSigningKeyService(new SecretBoxCryptoService('OIDC_SIGNING_KEY_SECRET'))),
            new OidcClaimsBuilder(new NameFormatterService()),
            new UserQuery(new NameFormatterService()),
            new RateLimiterService($this->storeDir)
        );
    }

    private function tokenRequest(
        string $clientId,
        string $clientSecret,
        string $remoteAddress = self::ATTACKER_IP
    ): ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/oidc/token', ['REMOTE_ADDR' => $remoteAddress])
            ->withParsedBody([
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => 'egal',
                'redirect_uri' => 'https://cloud.example.org/apps/user_oidc/code',
                'code_verifier' => 'egal',
            ]);
    }
}
