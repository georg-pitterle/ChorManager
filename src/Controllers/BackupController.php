<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\BackupLimitReachedException;
use App\Services\BackupService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response as SlimResponse;
use Slim\Psr7\Stream;
use Slim\Views\Twig;

class BackupController
{
    public function __construct(
        private readonly Twig $view,
        private readonly BackupService $backupService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $backups = $this->backupService->list();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'backups/index.twig', [
            'backups' => $backups,
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        try {
            $this->backupService->create(BackupService::TYPE_MANUAL, $userId);
            $_SESSION['success'] = 'Backup erfolgreich erstellt.';
        } catch (BackupLimitReachedException $exception) {
            $_SESSION['error'] = $exception->getMessage();
        } catch (\Throwable $exception) {
            $this->logger->error('Manual backup creation failed.', [
                'event' => 'backup.create.failed',
                'user_id' => $userId,
                'exception' => $exception,
            ]);
            $_SESSION['error'] = 'Backup konnte nicht erstellt werden.';
        }

        return $response->withHeader('Location', '/backups')->withStatus(302);
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        $id = (string) $args['id'];
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        try {
            $this->backupService->restore($id);
        } catch (\Throwable $exception) {
            $this->logger->error('Backup restore failed.', [
                'event' => 'backup.restore.failed',
                'id' => $id,
                'user_id' => $userId,
                'exception' => $exception,
            ]);
            $_SESSION['error'] = 'Wiederherstellung fehlgeschlagen.';

            return $response->withHeader('Location', '/backups')->withStatus(302);
        }

        $this->logger->info('Backup restored, ending own session.', [
            'event' => 'backup.restore.session_cleared',
            'id' => $id,
            'user_id' => $userId,
        ]);

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $redirectResponse = new SlimResponse();
        return $redirectResponse->withHeader('Location', '/login')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (string) $args['id'];

        try {
            $this->backupService->delete($id);
            $_SESSION['success'] = 'Backup gelöscht.';
        } catch (\Throwable $exception) {
            $this->logger->error('Backup delete failed.', [
                'event' => 'backup.delete.failed',
                'id' => $id,
                'exception' => $exception,
            ]);
            $_SESSION['error'] = 'Backup konnte nicht gelöscht werden.';
        }

        return $response->withHeader('Location', '/backups')->withStatus(302);
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $id = (string) $args['id'];

        try {
            $file = $this->backupService->getFile($id);
        } catch (\Throwable $exception) {
            $response->getBody()->write('Backup nicht gefunden.');
            return $response->withStatus(404);
        }

        $stream = fopen($file['path'], 'rb');
        if ($stream === false) {
            $response->getBody()->write('Backup nicht gefunden.');
            return $response->withStatus(404);
        }
        $body = new Stream($stream);

        return $response
            ->withBody($body)
            ->withHeader('Content-Type', 'application/gzip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->withHeader('Content-Length', (string) $file['size']);
    }
}
