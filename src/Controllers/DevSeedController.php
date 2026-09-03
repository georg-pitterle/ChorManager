<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DevSeedService;
use App\Util\AppEnvironment;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

class DevSeedController
{
    private DevSeedService $seedService;
    private LoggerInterface $logger;

    public function __construct(DevSeedService $seedService, ?LoggerInterface $logger = null)
    {
        $this->seedService = $seedService;
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(Request $request, Response $response): Response
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $params = $request->getQueryParams();

        $mode = (string) ($data['mode'] ?? $params['mode'] ?? 'append');
        $years = (int) ($data['years'] ?? $params['years'] ?? 3);
        $seed = (int) ($data['seed'] ?? $params['seed'] ?? 20260321);

        try {
            $report = $this->seedService->run($mode, $years, $seed);
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $response->getBody()->write($payload ?: '{}');

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (RuntimeException $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        } catch (\Throwable $e) {
            $this->logger->error('Dev seed run failed.', [
                'event' => 'dev_seed.failed',
                'mode' => $mode,
                'exception' => $e,
            ]);

            // Die Meldung der Ausnahme trägt Dateipfade und SQL-Bruchstücke. Im
            // Debug-Modus bleibt sie in der Antwort, dafür ist der Endpunkt da;
            // sonst steht sie nur noch im Protokoll oben.
            $payload = ['status' => 'error', 'message' => 'Seed execution failed.'];
            if (AppEnvironment::isDebugEnabled()) {
                $payload['detail'] = $e->getMessage();
            }

            $response->getBody()->write(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
