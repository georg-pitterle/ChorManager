<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Project;
use App\Models\Attachment;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Util\DownloadFileName;

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

        if ($userId <= 0) {
            return $this->view->render($response, 'songs/downloads.twig', [
                'projects' => [],
                'active_nav' => 'downloads',
            ]);
        }

        $projects = Project::query()
            ->select('projects.*')
            ->join('project_users', 'project_users.project_id', '=', 'projects.id')
            ->where('project_users.user_id', $userId)
            ->with([
                'assignedSongs' => function ($query) {
                    $query->orderBy('title', 'asc');
                },
                'assignedSongs.attachments' => function ($query) {
                    $query->orderBy('original_name', 'asc');
                },
                'assignedSongs.linkResources' => function ($query) {
                    $query->where('resource_type', 'link')->orderBy('title', 'asc');
                }
            ])
            ->distinct()
            ->chronological()
            ->get();

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

        $fileName = DownloadFileName::sanitize($attachment->original_name);
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
                ->withHeader(
                    'Content-Disposition',
                    'inline; filename="' . DownloadFileName::sanitize($attachment->original_name) . '"'
                );
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
            ->withHeader(
                'Content-Disposition',
                'inline; filename="' . DownloadFileName::sanitize($attachment->original_name) . '"'
            );
    }

    private function findMemberAttachment(int $userId, int $attachmentId): ?Attachment
    {
        if ($userId <= 0 || $attachmentId <= 0) {
            return null;
        }

        return Attachment::query()
            ->where('id', $attachmentId)
            ->where('entity_type', 'song')
            ->whereExists(function ($query) use ($userId) {
                $query->selectRaw('1')
                    ->from('project_song_assignments')
                    ->join('project_users', 'project_users.project_id', '=', 'project_song_assignments.project_id')
                    ->whereColumn('project_song_assignments.song_id', 'attachments.entity_id')
                    ->where('project_users.user_id', $userId);
            })
            ->first();
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

        $rawStart = $matches[1];
        $rawEnd = $matches[2];

        // RFC 7233 suffix-byte-range-spec: "bytes=-500" means the last 500 bytes.
        if ($rawStart === '' && $rawEnd !== '') {
            $suffixLength = (int) $rawEnd;
            if ($suffixLength <= 0) {
                return null;
            }

            $end = $fileSize - 1;
            $start = max(0, $fileSize - $suffixLength);
            return [$start, $end];
        }

        $start = $rawStart === '' ? 0 : (int) $rawStart;
        $end = $rawEnd === '' ? $fileSize - 1 : (int) $rawEnd;

        if ($start < 0 || $end < $start || $start >= $fileSize) {
            return null;
        }

        // RFC 7233, Abschnitt 2.1: Ein Ende jenseits der Datei ist kein Fehler,
        // es meint den Rest der Datei. Audio-Player fordern feste Blöcke an
        // ("bytes=0-1048575"); als 416 beantwortet, begann die Wiedergabe einer
        // kürzeren Datei gar nicht erst.
        $end = min($end, $fileSize - 1);

        return [$start, $end];
    }
}
