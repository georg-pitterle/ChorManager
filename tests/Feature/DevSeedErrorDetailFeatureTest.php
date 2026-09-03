<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DevSeedController;
use App\Services\DevSeedService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Fehlerantwort des Seed-Endpunkts.
 *
 * Bei einem unerwarteten Fehler stand die Meldung der Ausnahme wörtlich in der
 * Antwort - mit Dateipfaden und SQL-Bruchstücken. Erreichbar ist der Endpunkt
 * nur für die Mitgliederverwaltung, in die Antwort gehört so etwas trotzdem
 * nicht. Im Debug-Modus bleibt es sichtbar, denn genau dafür ist der Endpunkt da.
 *
 * Gemeint ist nur der unerwartete Fehler. Die RuntimeException des
 * Umgebungsriegels behält ihre Meldung: Sie sagt genau das, was die aufrufende
 * Person wissen muss.
 */
final class DevSeedErrorDetailFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = $_ENV['APP_ENV'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === null) {
            unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->previousEnv;
            $_SERVER['APP_ENV'] = $this->previousEnv;
        }

        parent::tearDown();
    }

    public function testProductionHidesTheExceptionMessage(): void
    {
        $payload = $this->runFailingSeed('production');

        $this->assertSame('error', $payload['status']);
        $this->assertArrayNotHasKey('detail', $payload);
        $this->assertStringNotContainsString('SQLSTATE', (string) json_encode($payload));
    }

    public function testDebugModeKeepsTheExceptionMessage(): void
    {
        $payload = $this->runFailingSeed('development');

        $this->assertSame('error', $payload['status']);
        $this->assertArrayHasKey('detail', $payload);
        $this->assertStringContainsString('SQLSTATE[42S02] Tabelle fehlt', $payload['detail']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runFailingSeed(string $environment): array
    {
        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;

        $seedService = $this->createStub(DevSeedService::class);
        $seedService->method('run')
            ->willThrowException(new \LogicException('SQLSTATE[42S02] Tabelle fehlt in /var/www/html/src/X.php'));

        $controller = new DevSeedController($seedService, new NullLogger());

        $response = $controller->run(
            $this->makeRequest('POST', '/dev/seed', ['mode' => 'append']),
            $this->makeResponse()
        );

        $this->assertSame(500, $response->getStatusCode());

        return (array) json_decode((string) $response->getBody(), true);
    }
}
