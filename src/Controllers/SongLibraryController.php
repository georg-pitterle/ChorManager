<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use App\Models\Project;
use App\Models\Song;
use App\Models\Attachment;
use App\Models\SongResource;
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
        $queryParams = $request->getQueryParams();
        $search = trim((string) ($queryParams['search'] ?? ''));
        $categoryId = (int) ($queryParams['category'] ?? 0);

        $songQuery = Song::with([
            'categories',
            'attachments' => function ($query) {
                $query->orderBy('original_name', 'asc');
            },
            'projectAssignments.project',
        ])->orderBy('title', 'asc');

        if ($search !== '') {
            $songQuery->where(function ($query) use ($search) {
                $query->where('title', 'like', '%' . $search . '%')
                    ->orWhere('composer', 'like', '%' . $search . '%')
                    ->orWhere('arranger', 'like', '%' . $search . '%')
                    ->orWhere('publisher', 'like', '%' . $search . '%');
            });
        }

        if ($categoryId > 0) {
            $songQuery->whereHas('categories', function ($query) use ($categoryId) {
                $query->where('repertoire_categories.id', $categoryId);
            });
        }

        $songs = $songQuery->get();
        $categories = Category::orderBy('sort_order', 'asc')->orderBy('name', 'asc')->get();

        // Keep old view data available until templates are migrated in later tasks.
        $projects = Project::with([
            'assignedSongs' => function ($query) {
                $query->orderBy('title', 'asc');
            },
            'assignedSongs.attachments' => function ($query) {
                $query->orderBy('original_name', 'asc');
            }
        ])->orderBy('name', 'asc')->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'songs/manage.twig', [
            'songs' => $songs,
            'categories' => $categories,
            'search' => $search,
            'selected_category_id' => $categoryId,
            'projects' => $projects,
            'success' => $success,
            'error' => $error,
            'active_nav' => 'song_library',
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['id'] ?? 0);
        $song = Song::with([
            'categories' => function ($query) {
                $query->orderBy('sort_order', 'asc')->orderBy('name', 'asc');
            },
            'attachments' => function ($query) {
                $query->orderBy('original_name', 'asc');
            },
            'linkResources' => function ($query) {
                $query->where('resource_type', 'link')->orderBy('title', 'asc');
            },
            'projectAssignments.project',
            'sheetArchive.lineItems' => function ($query) {
                $query->orderBy('sort_order', 'asc');
            },
        ])->find($songId);

        if (!$song) {
            $_SESSION['error'] = 'Lied nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $allCategories = Category::orderBy('sort_order', 'asc')->orderBy('name', 'asc')->get();
        $allProjects = Project::orderBy('name', 'asc')->get();
        $assignedProjectIds = $song->projectAssignments->pluck('project_id')->toArray();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'songs/detail.twig', [
            'song' => $song,
            'archive' => $song->sheetArchive,
            'all_categories' => $allCategories,
            'all_projects' => $allProjects,
            'assigned_project_ids' => $assignedProjectIds,
            'success' => $success,
            'error' => $error,
            'active_nav' => 'song_library',
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $allCategories = Category::orderBy('sort_order', 'asc')->orderBy('name', 'asc')->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'songs/create.twig', [
            'all_categories' => $allCategories,
            'success' => $success,
            'error' => $error,
            'active_nav' => 'song_library',
        ]);
    }

    public function createSong(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $title = trim($data['title'] ?? '');

        if ($title === '') {
            $_SESSION['error'] = 'Der Liedtitel ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/song-library/create')->withStatus(302);
        }

        $song = Song::create([
            'title' => $title,
            'composer' => trim($data['composer'] ?? '') ?: null,
            'arranger' => trim($data['arranger'] ?? '') ?: null,
            'publisher' => trim($data['publisher'] ?? '') ?: null,
            'created_by_user_id' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
        ]);

        $categoryIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($data['category_ids'] ?? [])),
            static fn($id) => $id > 0
        )));
        if ($categoryIds !== []) {
            $song->categories()->sync($categoryIds);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $files = $uploadedFiles['attachments'] ?? [];
        if (!is_array($files)) {
            $files = [$files];
        }
        $files = array_values(array_filter(
            $files,
            static fn($f) => $f->getError() !== UPLOAD_ERR_NO_FILE
        ));

        if ($files !== []) {
            $uploadError = $this->persistAttachments((int) $song->id, $files);
            if ($uploadError !== null) {
                $_SESSION['error'] = $uploadError;
                return $response->withHeader('Location', '/song-library/' . $song->id)->withStatus(302);
            }
        }

        $_SESSION['success'] = 'Lied erfolgreich angelegt.';
        return $response->withHeader('Location', '/song-library/' . $song->id)->withStatus(302);
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
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        $song->update([
            'title' => $title,
            'composer' => trim($data['composer'] ?? '') ?: null,
            'arranger' => trim($data['arranger'] ?? '') ?: null,
            'publisher' => trim($data['publisher'] ?? '') ?: null,
        ]);

        $_SESSION['success'] = 'Lied erfolgreich aktualisiert.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
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

    public function syncCategories(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['id'] ?? 0);
        $song = Song::find($songId);

        if (!$song) {
            $_SESSION['error'] = 'Lied nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $categoryIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($data['category_ids'] ?? [])),
            static fn($id) => $id > 0
        )));

        $song->categories()->sync($categoryIds);

        $_SESSION['success'] = 'Kategorien erfolgreich aktualisiert.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
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
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        $files = $uploadedFiles['attachments'];
        if (!is_array($files)) {
            $files = [$files];
        }

        $uploadError = $this->persistAttachments($songId, $files);
        if ($uploadError !== null) {
            $_SESSION['error'] = $uploadError;
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        $_SESSION['success'] = 'Dateien erfolgreich hochgeladen.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
    }

    public function createLinkResource(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['id'] ?? 0);
        $song = Song::find($songId);

        if (!$song) {
            $_SESSION['error'] = 'Lied nicht gefunden.';
            return $response->withHeader('Location', '/song-library')->withStatus(302);
        }

        [$payload, $error] = $this->validateLinkResourcePayload((array) $request->getParsedBody());
        if ($error !== null) {
            $_SESSION['error'] = $error;
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        SongResource::create([
            'song_id' => $songId,
            'resource_type' => 'link',
            'title' => $payload['title'],
            'description' => $payload['description'],
            'url' => $payload['url'],
        ]);

        $_SESSION['success'] = 'Link erfolgreich hinzugefügt.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
    }

    public function updateLinkResource(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['song_id'] ?? 0);
        $resourceId = (int) ($args['resource_id'] ?? 0);

        $resource = SongResource::find($resourceId);
        if (!$resource || (int) $resource->song_id !== $songId || $resource->resource_type !== 'link') {
            $_SESSION['error'] = 'Link nicht gefunden.';
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        [$payload, $error] = $this->validateLinkResourcePayload((array) $request->getParsedBody());
        if ($error !== null) {
            $_SESSION['error'] = $error;
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        $resource->update([
            'title' => $payload['title'],
            'description' => $payload['description'],
            'url' => $payload['url'],
        ]);

        $_SESSION['success'] = 'Link erfolgreich aktualisiert.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
    }

    public function deleteLinkResource(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['song_id'] ?? 0);
        $resourceId = (int) ($args['resource_id'] ?? 0);

        $resource = SongResource::find($resourceId);
        if (!$resource || (int) $resource->song_id !== $songId || $resource->resource_type !== 'link') {
            $_SESSION['error'] = 'Link nicht gefunden.';
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        $resource->delete();
        $_SESSION['success'] = 'Link erfolgreich gelöscht.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
    }

    /**
     * @return array{0:array{title:string,url:string,description:?string},1:?string}
     */
    private function validateLinkResourcePayload(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $url = trim((string) ($data['url'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        if ($title === '') {
            return [[], 'Der Linktitel ist ein Pflichtfeld.'];
        }

        if ($url === '') {
            return [[], 'Die Link-URL ist ein Pflichtfeld.'];
        }

        if (!preg_match('/^https?:\/\//i', $url)) {
            return [[], 'Link-URLs müssen mit http:// oder https:// beginnen.'];
        }

        return [[
            'title' => $title,
            'url' => $url,
            'description' => $description !== '' ? $description : null,
        ], null];
    }

    private function persistAttachments(int $songId, array $files): ?string
    {
        foreach ($files as $file) {
            if ($file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $uploadError = UploadValidator::getUploadErrorMessage($file->getError(), 'Datei');
            if ($uploadError !== null) {
                return $uploadError;
            }

            $mimeType = trim((string) $file->getClientMediaType()) ?: 'application/octet-stream';
            $contents = $file->getStream()->getContents();
            $size = strlen($contents);
            if ($size <= 0) {
                return 'Leere Dateien sind nicht erlaubt.';
            }

            $validation = UploadValidator::validateFileSize($size, $mimeType);
            if (!$validation['valid']) {
                return $validation['error'];
            }

            $originalName = trim((string) $file->getClientFilename());
            if ($originalName === '') {
                return 'Dateiname fehlt.';
            }

            Attachment::create([
                'entity_type' => 'song',
                'entity_id' => $songId,
                'filename' => bin2hex(random_bytes(16)) . '_' . $originalName,
                'original_name' => $originalName,
                'mime_type' => UploadValidator::normalizeMimeType($mimeType),
                'file_size' => $size,
                'file_content' => $contents,
            ]);
        }

        return null;
    }

    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        $songId = (int) ($args['song_id'] ?? 0);
        $attachmentId = (int) ($args['attachment_id'] ?? 0);

        $attachment = Attachment::where('entity_type', 'song')->find($attachmentId);
        if (!$attachment || $attachment->entity_id !== $songId) {
            $_SESSION['error'] = 'Anhang nicht gefunden.';
            return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
        }

        $attachment->delete();
        $_SESSION['success'] = 'Anhang erfolgreich geloescht.';
        return $response->withHeader('Location', '/song-library/' . $songId)->withStatus(302);
    }
}
