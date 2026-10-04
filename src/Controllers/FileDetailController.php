<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FileControllerSupport;
use App\Models\FileFolderShare;
use App\Models\FilePublicLink;
use App\Models\FileShare;
use App\Models\FileVersion;
use App\Services\Audience\AudienceDescriber;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileFavoriteService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileService;
use App\Services\Files\FileShareService;
use App\Util\AppUrlResolver;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Seite einer einzelnen Datei: Versionen, neue Version, Freigaben und
 * öffentliche Links. Sie ist der Einstieg für alle, die eine Datei über eine
 * Dateifreigabe bekommen haben und den Ordner nicht sehen.
 */
final class FileDetailController
{
    use FileControllerSupport;

    /** Der Klartext eines neuen Links steht genau einmal in der Sitzung. */
    private const NEW_LINK_SESSION_KEY = 'files_new_public_link';

    public function __construct(
        private readonly Twig $view,
        private readonly FileService $files,
        private readonly FileAccessService $access,
        private readonly FileShareService $shares,
        private readonly AudienceDescriber $describer,
        private readonly FileFavoriteService $favorites
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor();
        try {
            $file = $this->files->findReadable($actor, (int) $args['id']);
        } catch (FileManagementException) {
            $_SESSION['error'] = 'Die Datei existiert nicht oder ist nicht freigegeben.';

            return $this->redirect($response, '/files');
        }

        $level = $this->access->fileLevelFor($actor, $file);
        $folderLevel = $this->access->levelFor($actor, (int) $file->folder_id);
        $canManage = $level >= FileFolderShare::LEVEL_MANAGE;
        $shares = $canManage ? $this->describer->label(FileShare::query()->where('file_id', $file->id)->get()) : [];

        $newLink = $_SESSION[self::NEW_LINK_SESSION_KEY] ?? null;
        unset($_SESSION[self::NEW_LINK_SESSION_KEY]);
        if (!is_array($newLink) || (int) ($newLink['file_id'] ?? 0) !== (int) $file->id) {
            $newLink = null;
        }

        $folderPath = $folderLevel > 0
            ? ($this->access->pathsFor([(int) $file->folder_id])[(int) $file->folder_id] ?? [])
            : [];

        return $this->view->render($response, 'files/file.twig', [
            'file' => $file,
            'level' => $level,
            'level_labels' => FileFolderShare::LEVEL_LABELS,
            'file_level_labels' => FileShare::LEVELS,
            'can_edit' => $level >= FileFolderShare::LEVEL_EDIT,
            'can_manage' => $canManage,
            'folder_path' => $folderPath,
            'versions' => FileVersion::query()->with('uploader')->where('file_id', $file->id)
                ->orderByDesc('version_number')->get(),
            'is_favorite' => in_array((int) $file->id, $this->favorites->favoriteIds($actor, 'file'), true),
            'max_upload_bytes' => $this->files->maxUploadBytes(),
            'shares' => $shares,
            'share_options' => $canManage ? self::shareOptions($this->describer, $shares) : null,
            'links' => $canManage
                ? FilePublicLink::query()->where('file_id', $file->id)->orderByDesc('created_at')->get()
                : [],
            'new_link_url' => $newLink['url'] ?? null,
            'min_expiry' => Carbon::tomorrow()->format('Y-m-d'),
        ]);
    }

    public function replace(Request $request, Response $response, array $args): Response
    {
        $fileId = (int) $args['id'];
        $upload = $request->getUploadedFiles()['file'] ?? null;

        return $this->formAction($response, $this->fileUrl($fileId), function () use ($fileId, $upload): string {
            if ($upload === null || is_array($upload)) {
                throw new FileManagementException('Es ist keine Datei angekommen. Ist sie zu groß?', 413);
            }
            $this->files->replace($this->actor(), $fileId, $upload);

            return 'Neue Version gespeichert.';
        });
    }

    public function saveShares(Request $request, Response $response, array $args): Response
    {
        $fileId = (int) $args['id'];
        $body = $request->getParsedBody();
        $rows = is_array($body) && is_array($body['shares'] ?? null) ? $body['shares'] : [];

        return $this->formAction($response, $this->fileUrl($fileId), function () use ($fileId, $rows): string {
            $file = $this->files->findReadable($this->actor(), $fileId);
            $this->shares->setShares($this->actor(), $file, self::parseShareRows($rows));

            return 'Freigaben der Datei gespeichert.';
        });
    }

    public function createLink(Request $request, Response $response, array $args): Response
    {
        $fileId = (int) $args['id'];
        $body = $request->getParsedBody();
        $label = self::field($body, 'label');
        $expires = self::field($body, 'expires_at');
        $password = is_array($body) ? (string) ($body['password'] ?? '') : '';
        $baseUrl = AppUrlResolver::resolveBaseUrl($request);

        return $this->formAction(
            $response,
            $this->fileUrl($fileId),
            function () use ($fileId, $label, $expires, $password, $baseUrl): string {
                $file = $this->files->findReadable($this->actor(), $fileId);
                $expiresAt = null;
                if ($expires !== '') {
                    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
                    if ($parsed === false) {
                        throw new FileManagementException('Bitte ein gültiges Ablaufdatum angeben.', 422);
                    }
                    // Gültig bis zum Ende des gewählten Tages.
                    $expiresAt = Carbon::instance($parsed)->endOfDay();
                }

                [, $token] = $this->shares->createLink(
                    $this->actor(),
                    $file,
                    $label === '' ? null : $label,
                    $expiresAt,
                    $password === '' ? null : $password
                );
                $_SESSION[self::NEW_LINK_SESSION_KEY] = [
                    'file_id' => (int) $file->id,
                    'url' => $baseUrl . '/s/' . $token,
                ];

                return 'Öffentlicher Link angelegt. Kopiere ihn jetzt - er wird nur einmal angezeigt.';
            }
        );
    }

    public function revokeLink(Request $request, Response $response, array $args): Response
    {
        $linkId = (int) $args['id'];
        $link = FilePublicLink::find($linkId);
        $backTo = $link !== null ? $this->fileUrl((int) $link->file_id) : '/files';

        return $this->formAction($response, $backTo, function () use ($linkId): string {
            $this->shares->revokeLink($this->actor(), $linkId);

            return 'Link widerrufen.';
        });
    }

    private function fileUrl(int $fileId): string
    {
        return '/files/' . $fileId;
    }
}
