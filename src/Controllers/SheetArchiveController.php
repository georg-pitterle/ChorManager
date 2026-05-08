<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Song;
use App\Services\SheetArchiveService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class SheetArchiveController
{
    private SheetArchiveService $archiveService;
    private LoggerInterface $logger;

    public function __construct(ContainerInterface $container)
    {
        $this->archiveService = $container->get(SheetArchiveService::class);
        $this->logger = $container->get(LoggerInterface::class);
    }

    /**
     * Save archive data via AJAX
     */
    public function save(Request $request, Response $response, array $args): Response
    {
        try {
            $songId = (int) $args['songId'];
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                $rawBody = (string) $request->getBody();
                $decoded = json_decode($rawBody, true);
                $data = is_array($decoded) ? $decoded : [];
            }

            // Validate song exists
            $song = Song::find($songId);
            if (!$song) {
                $response->getBody()->write((string) json_encode(['error' => 'Song not found']));
                return $response
                    ->withStatus(404)
                    ->withHeader('Content-Type', 'application/json');
            }

            // Extract and validate input
            $archiveNumber = $data['archive_number'] ?? null;
            $location = $data['location'] ?? null;
            $lineItems = $data['line_items'] ?? [];

            if (!is_array($lineItems)) {
                $response->getBody()->write((string) json_encode(['error' => 'line_items must be an array']));
                return $response
                    ->withStatus(400)
                    ->withHeader('Content-Type', 'application/json');
            }

            // Save via service
            $archive = $this->archiveService->saveArchiveData(
                $songId,
                $archiveNumber,
                $location,
                $lineItems
            );

            $this->logger->info('Sheet archive saved', [
                'event' => 'sheet_archive.saved',
                'song_id' => $songId,
                'total_count' => $archive->getTotalCount(),
            ]);

            $response->getBody()->write((string) json_encode([
                'success' => true,
                'archive' => $this->formatArchiveResponse($archive),
            ]));
            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Sheet archive validation error', [
                'event' => 'sheet_archive.validation_error',
                'message' => $e->getMessage(),
            ]);

            $response->getBody()->write((string) json_encode(['error' => $e->getMessage()]));
            return $response
                ->withStatus(400)
                ->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $this->logger->error('Sheet archive save error', [
                'event' => 'sheet_archive.save_error',
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            $response->getBody()->write((string) json_encode(['error' => 'Failed to save archive data']));
            return $response
                ->withStatus(500)
                ->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get voice category suggestions
     */
    public function getVoiceCategories(Request $request, Response $response): Response
    {
        try {
            $categories = $this->archiveService->getAllVoiceCategories();

            $response->getBody()->write((string) json_encode(['categories' => $categories]));
            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch voice categories', [
                'event' => 'sheet_archive.fetch_categories_error',
                'exception' => $e,
            ]);

            $response->getBody()->write((string) json_encode(['error' => 'Failed to fetch categories']));
            return $response
                ->withStatus(500)
                ->withHeader('Content-Type', 'application/json');
        }
    }

    private function formatArchiveResponse($archive): array
    {
        return [
            'id' => $archive->id,
            'song_id' => $archive->song_id,
            'archive_number' => $archive->archive_number,
            'location' => $archive->location,
            'total_count' => $archive->getTotalCount(),
            'line_items' => $archive->lineItems->map(fn($item) => [
                'id' => $item->id,
                'voice_category' => $item->voice_category,
                'count' => $item->count,
            ])->toArray(),
        ];
    }
}
