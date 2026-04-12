<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Project;
use App\Models\Song;
use App\Models\Attachment;
use App\Util\UploadValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SongLibraryController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $projects = Project::with([
            'songs' => function ($query) {
                $query->orderBy('title', 'asc');
            },
            'songs.attachments' => function ($query) {
                $query->orderBy('original_name', 'asc');
            }
        ])->orderBy('name', 'asc')->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'songs/manage.twig', [
            'projects' => $projects,
            'success' => $success,
            'error' => $error,
            'active_nav' => 'song_library',
        ]);
    }

    public function createSong(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $projectId = (int) ($data['project_id'] ?? 0);
        $title = trim($data['title'] ?? '');

        if ($projectId <= 0 || !Project::find($projectId)) {
            $_SESSION['error'] = 'Bitte ein gueltiges Projekt auswaehlen.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        if ($title === '') {
            $_SESSION['error'] = 'Der Liedtitel ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        Song::create([
            'project_id' => $projectId,
            'title' => $title,
            'composer' => trim($data['composer'] ?? '') ?: null,
            'arranger' => trim($data['arranger'] ?? '') ?: null,
            'publisher' => trim($data['publisher'] ?? '') ?: null,
            'created_by_user_id' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
        ]);

        $_SESSION['success'] = 'Lied erfolgreich angelegt.';
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }

    public function updateSong(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['id'] ?? 0);
        $song = Song::find($songId);

        if (!$song) {
            $_SESSION['error'] = 'Lied nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $title = trim($data['title'] ?? '');

        if ($title === '') {
            $_SESSION['error'] = 'Der Liedtitel ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $song->update([
            'title' => $title,
            'composer' => trim($data['composer'] ?? '') ?: null,
            'arranger' => trim($data['arranger'] ?? '') ?: null,
            'publisher' => trim($data['publisher'] ?? '') ?: null,
        ]);

        $_SESSION['success'] = 'Lied erfolgreich aktualisiert.';
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }

    public function deleteSong(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['id'] ?? 0);
        $song = Song::find($songId);

        if (!$song) {
            $_SESSION['error'] = 'Lied nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        Attachment::where('entity_type', 'song')
            ->where('entity_id', $songId)
            ->delete();
        $song->delete();
        $_SESSION['success'] = 'Lied erfolgreich geloescht.';
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }

    public function uploadAttachments(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['id'] ?? 0);
        $song = Song::find($songId);

        if (!$song) {
            $_SESSION['error'] = 'Lied nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $uploadedFiles = $request->getUploadedFiles();
        if (!isset($uploadedFiles['attachments'])) {
            $_SESSION['error'] = 'Keine Dateien uebergeben.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $files = $uploadedFiles['attachments'];
        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $size = (int) $file->getSize();
            if ($size <= 0) {
                $_SESSION['error'] = 'Leere Dateien sind nicht erlaubt.';
                return $response->withHeader('Location', '/song-library')->withStatus(302);
            }

            $validation = UploadValidator::validateFileSize($size, $mimeType);
            if (!$validation['valid']) {
                $_SESSION['error'] = $validation['error'];
                return $response->withHeader('Location', '/song-library')->withStatus(302);
            }

            $originalName = trim((string) $file->getClientFilename());
            if ($originalName === '') {
                $_SESSION['error'] = 'Dateiname fehlt.';
                return $response->withHeader('Location', '/song-library')->withStatus(302);
            }

            $mimeType = trim((string) $file->getClientMediaType()) ?: 'application/octet-stream';

            Attachment::create([
                'entity_type' => 'song',
                'entity_id' => $songId,
                'filename' => bin2hex(random_bytes(16)) . '_' . $originalName,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'file_size' => $size,
                'file_content' => $file->getStream()->getContents(),
            ]);
        }

        $_SESSION['success'] = 'Dateien erfolgreich hochgeladen.';
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }

    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['song_id'] ?? 0);
        $attachmentId = (int) ($args['attachment_id'] ?? 0);

        $attachment = Attachment::where('entity_type', 'song')->find($attachmentId);
        if (!$attachment || $attachment->entity_id !== $songId) {
            $_SESSION['error'] = 'Anhang nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $attachment->delete();
        $_SESSION['success'] = 'Anhang erfolgreich geloescht.';
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }
}
