<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Middleware\CsrfMiddleware;
use App\Services\WebdavAccessService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Die datenbankfreien Zusicherungen rund um den Zugang zum Noten-Ordner.
 *
 * Form des Tokens und Form des Hashes sind Teil des Vertrags zwischen
 * Anmeldung, Oberfläche und Schema (`token_hash char(64)`).
 */
final class WebdavAccessTokenTest extends TestCase
{
    public function testHashIsDeterministicSoTheLoginCanUseAnIndex(): void
    {
        $token = str_repeat('a1b2c3d4', 8);

        $this->assertSame(
            WebdavAccessService::hashToken($token),
            WebdavAccessService::hashToken($token)
        );
    }

    public function testHashFitsTheSchemaColumn(): void
    {
        $hash = WebdavAccessService::hashToken(str_repeat('f', 64));

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testHashIsNotTheTokenItself(): void
    {
        $token = str_repeat('0123abcd', 8);

        $this->assertNotSame($token, WebdavAccessService::hashToken($token));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedTokenProvider(): array
    {
        return [
            'zu kurz' => [str_repeat('a', 63)],
            'zu lang' => [str_repeat('a', 65)],
            'Großbuchstaben' => [str_repeat('A', 64)],
            'kein Hex' => [str_repeat('z', 64)],
            'abschließender Umbruch' => [str_repeat('a', 64) . "\n"],
            'leer' => [''],
        ];
    }

    #[DataProvider('rejectedTokenProvider')]
    public function testOnlyWellFormedTokensAreAccepted(string $candidate): void
    {
        $this->assertSame(0, preg_match(WebdavAccessService::TOKEN_PATTERN, $candidate));
    }

    /**
     * PROPFIND ist keine der Methoden, die der CSRF-Schutz durchwinkt. Ohne die
     * Präfix-Ausnahme für /webdav hätte er jedes Auflisten eines Ordners mit 403
     * abgewiesen, noch bevor der Controller die Zugangsdaten gesehen hat.
     */
    public function testAPropfindOnTheSheetMusicFolderPassesTheCsrfGuard(): void
    {
        $response = $this->runCsrf('PROPFIND', '/webdav/Herbstkonzert/Ave%20Maria');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAPropfindElsewhereIsStillRefused(): void
    {
        $response = $this->runCsrf('PROPFIND', '/events');

        $this->assertNotSame(200, $response->getStatusCode());
    }

    /**
     * Die Ausnahme darf nicht auf einen Pfad durchschlagen, der nur so anfängt:
     * `/webdavsomething` ist ein anderer Endpunkt.
     */
    public function testASimilarLookingPathIsNotExempt(): void
    {
        $response = $this->runCsrf('POST', '/webdav-admin');

        $this->assertNotSame(200, $response->getStatusCode());
    }

    private function runCsrf(string $method, string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        return (new CsrfMiddleware())->process($request, $handler);
    }
}
