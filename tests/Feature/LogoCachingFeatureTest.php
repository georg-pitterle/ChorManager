<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AppSettingController;
use App\Middleware\SecurityHeadersMiddleware;
use App\Models\AppSetting;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Das Vereinslogo hängt in der Navigationsleiste jeder Seite.
 *
 * Die SecurityHeadersMiddleware setzt `no-store` auf alles, was nicht selbst
 * einen `Cache-Control`-Kopf mitbringt - richtig für Mitgliederdaten, die nach
 * einem Gerätewechsel nicht im Zwischenspeicher liegen bleiben sollen. `/logo`
 * brachte keinen mit und wurde deshalb bei jedem einzelnen Seitenaufruf neu
 * übertragen, obwohl es sich fast nie ändert und nichts Persönliches zeigt.
 *
 * Der Kopf `private, max-age=300` erlaubt nur den Zwischenspeicher des Browsers,
 * nicht den eines gemeinsam genutzten Proxys; das `ETag` sorgt dafür, dass ein
 * neu hochgeladenes Logo sofort durchschlägt, sobald der Browser nachfragt.
 */
final class LogoCachingFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private const CACHE_CONTROL = 'private, max-age=300';

    protected function setUp(): void
    {
        parent::setUp();

        Bootstrap::setupTestDatabase();
        $this->forgetLogo();
    }

    protected function tearDown(): void
    {
        $this->forgetLogo();

        parent::tearDown();
    }

    public function testStoredLogoIsCacheableAndCarriesAnETag(): void
    {
        $this->storeLogo('erste-fassung');

        $response = $this->requestLogo();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::CACHE_CONTROL, $response->getHeaderLine('Cache-Control'));
        $this->assertNotSame('', $response->getHeaderLine('ETag'));
    }

    public function testUnchangedLogoAnswersWithNotModified(): void
    {
        $this->storeLogo('erste-fassung');

        $etag = $this->requestLogo()->getHeaderLine('ETag');
        $response = $this->requestLogo($etag);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame(
            self::CACHE_CONTROL,
            $response->getHeaderLine('Cache-Control'),
            'Auch die 304 muss die Zwischenspeicher-Regel tragen, sonst gilt sie nur beim ersten Mal.'
        );
    }

    public function testANewLogoGetsANewETagAndIsDeliveredAgain(): void
    {
        $this->storeLogo('erste-fassung');
        $firstEtag = $this->requestLogo()->getHeaderLine('ETag');

        $this->storeLogo('zweite-fassung');
        $response = $this->requestLogo($firstEtag);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame($firstEtag, $response->getHeaderLine('ETag'));
        $this->assertSame('zweite-fassung', (string) $response->getBody());
    }

    /**
     * Ohne hochgeladenes Logo liefert der Controller das mitgelieferte Symbol.
     * Auch das ist eine Datei, die sich nicht bei jedem Aufruf ändert.
     */
    public function testFallbackLogoIsCacheableToo(): void
    {
        $response = $this->requestLogo();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::CACHE_CONTROL, $response->getHeaderLine('Cache-Control'));
        $this->assertNotSame('', $response->getHeaderLine('ETag'));
    }

    /**
     * Die Middleware darf den Kopf des Controllers nicht überschreiben - sonst
     * stünde am Ende doch wieder `no-store` auf der Antwort.
     */
    public function testSecurityHeadersMiddlewareKeepsTheControllersCachePolicy(): void
    {
        $this->storeLogo('erste-fassung');

        $controllerResponse = $this->requestLogo();
        $middleware = new SecurityHeadersMiddleware();

        $response = $middleware->process(
            $this->makeRequest('GET', 'http://localhost/logo'),
            new class ($controllerResponse) implements RequestHandlerInterface {
                public function __construct(private readonly ResponseInterface $response)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->response;
                }
            }
        );

        $this->assertSame(self::CACHE_CONTROL, $response->getHeaderLine('Cache-Control'));
        $this->assertFalse($response->hasHeader('Pragma'));
    }

    private function requestLogo(string $ifNoneMatch = ''): ResponseInterface
    {
        $controller = new AppSettingController($this->createStub(Twig::class), new NullLogger());

        $headers = $ifNoneMatch === '' ? [] : ['If-None-Match' => $ifNoneMatch];

        return $controller->logo(
            $this->makeRequest('GET', 'http://localhost/logo', [], [], $headers),
            $this->makeResponse()
        );
    }

    private function storeLogo(string $content): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => 'app_logo'],
            [
                'setting_value' => 'logo.png',
                'binary_content' => $content,
                'mime_type' => 'image/png',
            ]
        );
    }

    private function forgetLogo(): void
    {
        AppSetting::query()->where('setting_key', 'app_logo')->delete();
    }
}
