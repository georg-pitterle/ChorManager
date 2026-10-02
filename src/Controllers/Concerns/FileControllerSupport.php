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
     * Freigabe-Zeilen aus dem Formular: Stufe, "Alle Mitglieder", Bedingungen je
     * Kategorie. Geprüft wird im Service.
     *
     * @param array<mixed> $rows
     * @return list<array{level: int, all: bool, conditions: array<string, list<string>>}>
     */
    private static function parseShareRows(array $rows): array
    {
        $shares = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $conditions = [];
            foreach (is_array($row['conditions'] ?? null) ? $row['conditions'] : [] as $category => $values) {
                if (is_string($category) && is_array($values)) {
                    $conditions[$category] = array_values(array_map('strval', array_filter($values, 'is_scalar')));
                }
            }
            $shares[] = [
                'level' => (int) ($row['level'] ?? 0),
                'all' => !empty($row['all']),
                'conditions' => $conditions,
            ];
        }

        return $shares;
    }

    /**
     * Projekte, die in den beschriebenen Freigaben schon gewählt sind - sie
     * bleiben in der Auswahl, auch wenn sie inzwischen beendet sind.
     *
     * @param list<array{conditions: array<string, list<int>>}> $described
     * @return list<int>
     */
    private static function projectIdsOf(array $described): array
    {
        return self::selectedIdsOf($described, 'project');
    }

    /**
     * @param list<array{conditions: array<string, list<int>>}> $described
     * @return list<int>
     */
    private static function selectedIdsOf(array $described, string $category): array
    {
        $ids = [];
        foreach ($described as $share) {
            foreach ($share['conditions'][$category] ?? [] as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed>|object|null $body
     */
    private static function field($body, string $key): string
    {
        return is_array($body) ? trim((string) ($body[$key] ?? '')) : '';
    }
}
