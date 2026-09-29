<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FileControllerSupport;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileTrashService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class FileTrashController
{
    use FileControllerSupport;

    public function __construct(
        private readonly Twig $view,
        private readonly FileTrashService $trash,
        private readonly FileAccessService $access
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $items = $this->trash->listFor($this->actor());
        $containerIds = array_values(array_unique(array_filter(array_column($items, 'container_id'))));

        return $this->view->render($response, 'files/trash.twig', [
            'items' => $items,
            'paths' => $this->access->pathsFor($containerIds),
            'trash_days' => $this->trash->trashDays(),
        ]);
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        $type = (string) $args['type'];
        $id = (int) $args['id'];

        return $this->formAction($response, '/files/trash', function () use ($type, $id): string {
            if ($type === 'file') {
                $file = $this->trash->restoreFile($this->actor(), $id);

                return '„' . $file->name . '“ wiederhergestellt.';
            }
            if ($type === 'folder') {
                $folder = $this->trash->restoreFolder($this->actor(), $id);

                return 'Ordner „' . $folder->name . '“ wiederhergestellt.';
            }
            throw FileManagementException::notFound();
        });
    }

    public function purge(Request $request, Response $response, array $args): Response
    {
        $type = (string) $args['type'];
        $id = (int) $args['id'];

        return $this->formAction($response, '/files/trash', function () use ($type, $id): string {
            match ($type) {
                'file' => $this->trash->purgeFile($this->actor(), $id),
                'folder' => $this->trash->purgeFolder($this->actor(), $id),
                default => throw FileManagementException::notFound(),
            };

            return 'Endgültig gelöscht.';
        });
    }
}
