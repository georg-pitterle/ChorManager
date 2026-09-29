<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FileControllerSupport;
use App\Models\FileFolder;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileResponseFactory;
use App\Services\Files\FileZipService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Aktionen an Ordnern: anlegen, umbenennen, verschieben, löschen, Freigaben,
 * Kontingent, ZIP-Download. Die Rechte prüft FileFolderService.
 */
final class FileFolderController
{
    use FileControllerSupport;

    public function __construct(
        private readonly FileFolderService $folders,
        private readonly FileZipService $zip,
        private readonly FileResponseFactory $responses
    ) {
    }

    public function createRoot(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $backTo = '/files';

        return $this->formAction(
            $response,
            static function () use (&$backTo): string {
                return $backTo;
            },
            function () use ($body, &$backTo): string {
                $folder = $this->folders->createRoot(
                    $this->actor(),
                    self::field($body, 'name'),
                    self::megabytes(self::field($body, 'quota_mb'))
                );
                $backTo = $this->folderUrl((int) $folder->id);

                return 'Teamordner angelegt. Lege jetzt fest, wer ihn sehen darf.';
            }
        );
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $name = self::field($request->getParsedBody(), 'name');
        $parentId = (int) $args['id'];

        return $this->formAction($response, $this->folderUrl($parentId), function () use ($parentId, $name): string {
            $this->folders->create($this->actor(), $this->liveFolder($parentId), $name);

            return 'Ordner angelegt.';
        });
    }

    public function rename(Request $request, Response $response, array $args): Response
    {
        $name = self::field($request->getParsedBody(), 'name');
        $id = (int) $args['id'];

        return $this->formAction($response, $this->folderUrl($id), function () use ($id, $name): string {
            $this->folders->rename($this->actor(), $this->liveFolder($id), $name);

            return 'Ordner umbenannt.';
        });
    }

    public function move(Request $request, Response $response, array $args): Response
    {
        $targetId = (int) self::field($request->getParsedBody(), 'target_id');
        $id = (int) $args['id'];

        return $this->formAction($response, $this->folderUrl($id), function () use ($id, $targetId): string {
            $this->folders->move($this->actor(), $this->liveFolder($id), $this->liveFolder($targetId));

            return 'Ordner verschoben.';
        });
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $folder = FileFolder::find($id);
        $backTo = $folder?->parent_id !== null ? $this->folderUrl((int) $folder->parent_id) : '/files';

        return $this->formAction($response, $backTo, function () use ($id): string {
            $this->folders->trash($this->actor(), $this->liveFolder($id));

            return 'Ordner in den Papierkorb gelegt.';
        });
    }

    public function shares(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        $rawShares = is_array($body) && is_array($body['shares'] ?? null) ? self::parseShareRows($body['shares']) : [];
        $id = (int) $args['id'];

        return $this->formAction($response, $this->folderUrl($id), function () use ($id, $rawShares): string {
            $this->folders->setShares($this->actor(), $this->liveFolder($id), $rawShares);

            return 'Freigaben gespeichert.';
        });
    }

    public function quota(Request $request, Response $response, array $args): Response
    {
        $quota = self::megabytes(self::field($request->getParsedBody(), 'quota_mb'));
        $id = (int) $args['id'];

        return $this->formAction($response, $this->folderUrl($id), function () use ($id, $quota): string {
            $this->folders->setQuota($this->actor(), $this->liveFolder($id), $quota);

            return $quota === null ? 'Kontingent aufgehoben.' : 'Kontingent gespeichert.';
        });
    }

    public function zip(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        try {
            $folder = $this->liveFolder($id);
            $path = $this->zip->build($this->actor(), $folder);
        } catch (FileManagementException $exception) {
            $_SESSION['error'] = $exception->getMessage();

            return $this->redirect($response, $exception->status === 404 ? '/files' : $this->folderUrl($id));
        }

        // Die Temp-Datei ist geöffnet und wird gestreamt; auf Unix darf sie schon
        // jetzt aus dem Verzeichnis verschwinden, der Inhalt bleibt bis zum
        // Schließen lesbar. So bleibt nach einem Abbruch nichts liegen.
        $result = $this->responses->localFile($response, $path, $folder->name . '.zip', 'application/zip');
        @unlink($path);

        return $result;
    }

    /**
     * Das Formular schickt das Ziel als "typ:kennung" (eine Auswahl statt zwei
     * abhängiger Listen). Die Prüfung auf gültige Typen und Stufen macht der Service.
     *
     * @param array<mixed> $rows
     * @return list<array{type: string, reference_id: int, level: int}>
     */
    private static function parseShareRows(array $rows): array
    {
        $shares = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $target = explode(':', (string) ($row['target'] ?? ''), 2);
            if (count($target) !== 2) {
                continue;
            }
            $shares[] = [
                'type' => $target[0],
                'reference_id' => (int) $target[1],
                'level' => (int) ($row['level'] ?? 0),
            ];
        }

        return $shares;
    }

    private static function megabytes(string $value): ?int
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return (int) round((float) $value * 1024 * 1024);
    }
}
