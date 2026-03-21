<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DevSeedService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

class DevSeedController
{
    private DevSeedService $seedService;

    public function __construct(DevSeedService $seedService)
    {
        $this->seedService = $seedService;
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
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Seed execution failed.',
                'detail' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
