<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use App\Models\FileFolder;
use App\Services\Files\FileActor;
use App\Services\Files\FileManagementException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Gemeinsames der Controller der Dateiverwaltung: wer handelt, wie eine
 * abgewiesene Aktion bei Formular und JSON ankommt.
 */
trait FileControllerSupport
{
    private function actor(): FileActor
    {
        $actor = FileActor::fromSession($_SESSION ?? []);
        if ($actor === null) {
            throw FileManagementException::forbidden();
        }

        return $actor;
    }

    private function liveFolder(int $id): FileFolder
    {
        $folder = FileFolder::find($id);
        if ($folder === null) {
            throw FileManagementException::notFound();
        }

        return $folder;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    /**
     * Formular-Aktion ausführen: Erfolg und Fehler landen als Flash-Meldung,
     * danach geht es zurück auf die Ordnerseite.
     *
     * @param string|\Closure(): string $backTo Ziel; als Closure erst nach der Aktion ausgewertet
     * @param callable(): ?string $action liefert die Erfolgsmeldung
     */
    private function formAction(Response $response, string|\Closure $backTo, callable $action): Response
    {
        try {
            $message = $action();
            if ($message !== null) {
                $_SESSION['success'] = $message;
            }
        } catch (FileManagementException $exception) {
            $_SESSION['error'] = $exception->getMessage();
            if ($exception->status === 404) {
                return $this->redirect($response, '/files');
            }
        }

        return $this->redirect($response, is_string($backTo) ? $backTo : $backTo());
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json')
            || $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }

    private function notFound(Response $response): Response
    {
        return $response->withStatus(404);
    }

    private function folderUrl(int $folderId): string
    {
        return '/files/folders/' . $folderId;
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

    /**
     * @param array<string, mixed>|object|null $body
     */
    private static function field($body, string $key): string
    {
        return is_array($body) ? trim((string) ($body[$key] ?? '')) : '';
    }
}
