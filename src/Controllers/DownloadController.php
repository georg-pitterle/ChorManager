<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Project;
use App\Models\SongAttachment;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DownloadController
{
    private Twig $view;
    private array $streamableMimeTypes = [
        'audio/mpeg',
        'audio/midi',
        'audio/x-midi',
        'application/x-midi',
    ];

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $projects = Project::whereHas('users', function ($query) use ($userId) {
            $query->where('users.id', $userId);
        })->with([
            'songs' => function ($query) {
                $query->orderBy('title', 'asc');
            },
            'songs.attachments' => function ($query) {
                $query->orderBy('original_name', 'asc');
            }
        ])->orderBy('name', 'asc')->get();

        return $this->view->render($response, 'songs/downloads.twig', [
            'projects' => $projects,
            'active_nav' => 'downloads',
        ]);
    }

    public function downloadAttachment(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $attachmentId = (int) ($args['attachment_id'] ?? 0);

        $attachment = $this->findMemberAttachment($userId, $attachmentId);
        if (!$attachment) {
            $response->getBody()->write('Datei nicht gefunden oder kein Zugriff.');
            return $response->withStatus(404);
        }

        $fileName = $this->safeFileName($attachment->original_name);
        $response->getBody()->write($attachment->file_content);

        return $response
            ->withHeader('Content-Type', $attachment->mime_type ?: 'application/octet-stream')
            ->withHeader('Content-Length', (string) strlen($attachment->file_content))
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $fileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName)
            );
    }

    public function streamAttachment(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $attachmentId = (int) ($args['attachment_id'] ?? 0);

        $attachment = $this->findMemberAttachment($userId, $attachmentId);
        if (!$attachment) {
            $response->getBody()->write('Datei nicht gefunden oder kein Zugriff.');
            return $response->withStatus(404);
        }

        $mimeType = strtolower(trim((string) $attachment->mime_type));
        if (!in_array($mimeType, $this->streamableMimeTypes, true)) {
            $response->getBody()->write('Dateityp nicht fuer Streaming freigegeben.');
            return $response->withStatus(415);
        }

        $content = $attachment->file_content;
        $fileSize = strlen($content);
        $rangeHeader = trim($request->getHeaderLine('Range'));

        if ($rangeHeader === '') {
            $response->getBody()->write($content);
            return $response
                ->withHeader('Content-Type', $mimeType)
                ->withHeader('Content-Length', (string) $fileSize)
                ->withHeader('Accept-Ranges', 'bytes')
                ->withHeader('Content-Disposition', 'inline; filename="' . $this->safeFileName($attachment->original_name) . '"');
        }

        $range = self::parseRangeHeader($rangeHeader, $fileSize);
        if ($range === null) {
            return $response->withStatus(416)->withHeader('Content-Range', 'bytes */' . $fileSize);
        }

        [$start, $end] = $range;

        $length = $end - $start + 1;
        $chunk = substr($content, $start, $length);

        $response->getBody()->write($chunk);

        return $response
            ->withStatus(206)
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string) $length)
            ->withHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $fileSize)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Content-Disposition', 'inline; filename="' . $this->safeFileName($attachment->original_name) . '"');
    }

    private function findMemberAttachment(int $userId, int $attachmentId): ?SongAttachment
    {
        if ($userId <= 0 || $attachmentId <= 0) {
            return null;
        }

        return SongAttachment::where('id', $attachmentId)
            ->whereHas('song.project.users', function ($query) use ($userId) {
                $query->where('users.id', $userId);
            })
            ->first();
    }

    private function safeFileName(string $name): string
    {
        return self::normalizeFileName($name);
    }

    public static function normalizeFileName(string $name): string
    {
        $safe = str_replace(["\r", "\n", '"', '\\', '/'], '_', $name);
        $trimmed = trim($safe);
        return $trimmed !== '' ? $trimmed : 'download';
    }

    /**
     * @return array{0:int,1:int}|null
     */
    public static function parseRangeHeader(string $rangeHeader, int $fileSize): ?array
    {
        if ($fileSize <= 0) {
            return null;
        }

        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches)) {
            return null;
        }

        $start = $matches[1] === '' ? 0 : (int) $matches[1];
        $end = $matches[2] === '' ? $fileSize - 1 : (int) $matches[2];

        if ($start < 0 || $end < $start || $start >= $fileSize || $end >= $fileSize) {
            return null;
        }

        return [$start, $end];
    }
}
