<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FileControllerSupport;
use App\Models\FileFolder;
use App\Models\FileFolderShare;
use App\Models\FileVersion;
use App\Services\Files\FileFavoriteService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileResponseFactory;
use App\Services\Files\FileService;
use App\Services\NameFormatterService;
use App\Util\AttachmentPreview;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Aktionen an einzelnen Dateien: hochladen, ausliefern, Versionen, umbenennen,
 * verschieben, löschen, anheften. Die Rechte prüft FileService.
 */
final class FileController
{
    use FileControllerSupport;

    public function __construct(
        private readonly FileService $files,
        private readonly FileFavoriteService $favorites,
        private readonly FileResponseFactory $responses,
        private readonly NameFormatterService $nameFormatter
    ) {
    }

    /**
     * Eine Datei je Anfrage: Der Browser schickt die Dateien einzeln, damit jede
     * ihren eigenen Fortschritt und ihre eigene Fehlermeldung bekommt.
     */
    public function upload(Request $request, Response $response, array $args): Response
    {
        try {
            $folder = $this->liveFolder((int) $args['id']);
            $uploads = $request->getUploadedFiles();
            $upload = $uploads['file'] ?? null;
            if (is_array($upload)) {
                $upload = $upload[0] ?? null;
            }
            if ($upload === null) {
                // Häufigste Ursache: post_max_size überschritten, PHP verwirft dann
                // den ganzen Anfragekörper.
                throw new FileManagementException('Es ist keine Datei angekommen. Ist sie zu groß?', 413);
            }

            $result = $this->files->upload($this->actor(), $folder, $upload);

            return $this->json($response, [
                'ok' => true,
                'file' => ['id' => (int) $result->file->id, 'name' => (string) $result->file->name],
                'new_version' => $result->isNewVersion,
            ]);
        } catch (FileManagementException $exception) {
            return $this->json($response, ['ok' => false, 'error' => $exception->getMessage()], $exception->status);
        }
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        try {
            $file = $this->files->findReadable($this->actor(), (int) $args['id']);
            $version = $file->currentVersion ?? throw FileManagementException::notFound();

            return $this->responses->download($response, $version, (string) $file->name);
        } catch (FileManagementException) {
            return $this->notFound($response);
        }
    }

    public function preview(Request $request, Response $response, array $args): Response
    {
        try {
            $file = $this->files->findReadable($this->actor(), (int) $args['id']);
            $version = $file->currentVersion ?? throw FileManagementException::notFound();
        } catch (FileManagementException) {
            return $this->notFound($response);
        }

        // Nur Typen, die der Browser gefahrlos selbst darstellt - ein hochgeladenes
        // HTML oder SVG würde sonst im Kontext der Anwendung ausgeführt.
        if (!AttachmentPreview::isInlineServable((string) $version->mime_type)) {
            return $this->responses->download($response, $version, (string) $file->name);
        }

        return $this->responses->inline($response, $version, (string) $file->name, $request->getHeaderLine('Range'));
    }

    public function versions(Request $request, Response $response, array $args): Response
    {
        try {
            $file = $this->files->findReadable($this->actor(), (int) $args['id']);
        } catch (FileManagementException $exception) {
            return $this->json($response, ['ok' => false, 'error' => $exception->getMessage()], $exception->status);
        }

        $versions = FileVersion::query()->with('uploader')->where('file_id', $file->id)
            ->orderByDesc('version_number')->get();

        return $this->json($response, [
            'ok' => true,
            'current_version_id' => (int) $file->current_version_id,
            'versions' => $versions->map(fn (FileVersion $v): array => [
                'id' => (int) $v->id,
                'number' => (int) $v->version_number,
                'size' => (int) $v->size,
                'created_at' => $v->created_at?->format('d.m.Y H:i'),
                'uploaded_by' => $v->uploader !== null ? $this->nameFormatter->formatPerson($v->uploader) : null,
            ])->all(),
        ]);
    }

    public function downloadVersion(Request $request, Response $response, array $args): Response
    {
        try {
            $actor = $this->actor();
            $version = $this->files->findReadableVersion($actor, (int) $args['id']);
            $file = $this->files->findReadable($actor, (int) $version->file_id);
        } catch (FileManagementException) {
            return $this->notFound($response);
        }

        $name = pathinfo((string) $file->name, PATHINFO_FILENAME) . ' (Version ' . $version->version_number . ')';
        $extension = pathinfo((string) $file->name, PATHINFO_EXTENSION);

        return $this->responses->download($response, $version, $extension !== '' ? $name . '.' . $extension : $name);
    }

    public function restoreVersion(Request $request, Response $response, array $args): Response
    {
        $version = FileVersion::find((int) $args['id']);
        $backTo = '/files';

        return $this->formAction(
            $response,
            static function () use (&$backTo): string {
                return $backTo;
            },
            function () use ($version, &$backTo): string {
                if ($version === null) {
                    throw FileManagementException::notFound();
                }
                $file = $this->files->restoreVersion($this->actor(), $version);
                $backTo = $this->folderUrl((int) $file->folder_id);

                return 'Version ' . $version->version_number . ' ist wieder die aktuelle Fassung.';
            }
        );
    }

    public function rename(Request $request, Response $response, array $args): Response
    {
        $name = self::field($request->getParsedBody(), 'name');

        return $this->fileFormAction($response, (int) $args['id'], function ($file) use ($name): string {
            $this->files->renameFile($this->actor(), $file, $name);

            return 'Datei umbenannt.';
        });
    }

    public function move(Request $request, Response $response, array $args): Response
    {
        $targetId = (int) self::field($request->getParsedBody(), 'target_id');

        return $this->fileFormAction($response, (int) $args['id'], function ($file) use ($targetId): string {
            $target = FileFolder::find($targetId) ?? throw FileManagementException::notFound();
            $this->files->moveFile($this->actor(), $file, $target);

            return 'Datei verschoben.';
        });
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        return $this->fileFormAction($response, (int) $args['id'], function ($file): string {
            $this->files->trashFile($this->actor(), $file);

            return 'Datei in den Papierkorb gelegt.';
        });
    }

    public function toggleFavorite(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $type = self::field($body, 'type');
        $id = (int) self::field($body, 'id');

        try {
            $isFavorite = $this->favorites->toggle($this->actor(), $type, $id);
        } catch (FileManagementException $exception) {
            if ($this->wantsJson($request)) {
                return $this->json($response, ['ok' => false, 'error' => $exception->getMessage()], $exception->status);
            }
            $_SESSION['error'] = $exception->getMessage();

            return $this->redirect($response, '/files');
        }

        if ($this->wantsJson($request)) {
            return $this->json($response, ['ok' => true, 'favorite' => $isFavorite]);
        }

        $back = self::field($body, 'back');

        return $this->redirect($response, str_starts_with($back, '/files') ? $back : '/files');
    }

    /**
     * @param callable(\App\Models\StoredFile): string $action
     */
    private function fileFormAction(Response $response, int $fileId, callable $action): Response
    {
        $backTo = '/files';

        return $this->formAction(
            $response,
            static function () use (&$backTo): string {
                return $backTo;
            },
            function () use ($fileId, $action, &$backTo): string {
                $file = $this->files->findWithLevel($this->actor(), $fileId, FileFolderShare::LEVEL_READ);
                $backTo = $this->folderUrl((int) $file->folder_id);

                return $action($file);
            }
        );
    }
}
