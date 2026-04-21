<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Project;
use App\Models\ProjectSongAssignment;
use App\Models\Song;
use Illuminate\Database\QueryException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProjectSongAssignmentController
{
    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $songId = (int) ($data['song_id'] ?? 0);
        $projectId = (int) ($data['project_id'] ?? 0);
        $note = $this->normalizeNote($data['note'] ?? null);

        if ($songId <= 0 || !Song::find($songId)) {
            return $this->redirectError($response, 'Lied nicht gefunden.');
        }

        if ($projectId <= 0 || !Project::find($projectId)) {
            return $this->redirectError($response, 'Projekt nicht gefunden.');
        }

        if (ProjectSongAssignment::where('song_id', $songId)->where('project_id', $projectId)->exists()) {
            return $this->redirectError($response, 'Zuordnung existiert bereits.');
        }

        try {
            ProjectSongAssignment::create([
                'song_id' => $songId,
                'project_id' => $projectId,
                'note' => $note,
            ]);
        } catch (QueryException $exception) {
            return $this->redirectError($response, 'Zuordnung existiert bereits.');
        }

        return $this->redirectSuccess($response, 'Zuordnung erfolgreich angelegt.');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        if ($id <= 0) {
            return $this->redirectError($response, 'Zuordnung nicht gefunden.', $this->resolveReturnTo($data['return_to'] ?? null));
        }

        $model = ProjectSongAssignment::find($id);
        if (!$model) {
            return $this->redirectError($response, 'Zuordnung nicht gefunden.', $this->resolveReturnTo($data['return_to'] ?? null));
        }

        $model->update([
            'note' => $this->normalizeNote($data['note'] ?? null),
        ]);

        return $this->redirectSuccess(
            $response,
            'Zuordnung erfolgreich aktualisiert.',
            $this->resolveReturnTo($data['return_to'] ?? null, (int) $model->song_id)
        );
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->redirectError($response, 'Zuordnung nicht gefunden.');
        }

        $model = ProjectSongAssignment::find($id);
        if (!$model) {
            return $this->redirectError($response, 'Zuordnung nicht gefunden.');
        }

        $model->delete();

        return $this->redirectSuccess($response, 'Zuordnung erfolgreich geloescht.');
    }

    private function normalizeNote(mixed $value): ?string
    {
        $note = trim((string) ($value ?? ''));
        return $note === '' ? null : $note;
    }

    private function resolveReturnTo(mixed $value, ?int $songId = null): string
    {
        $target = trim((string) ($value ?? ''));
        if ($target !== '' && str_starts_with($target, '/song-library')) {
            return $target;
        }

        if ($songId !== null && $songId > 0) {
            return '/song-library/' . $songId;
        }

        return '/song-library';
    }

    private function redirectError(Response $response, string $message, string $target = '/song-library'): Response
    {
        $_SESSION['error'] = $message;
        return $response->withHeader('Location', $target)->withStatus(302);
    }

    private function redirectSuccess(Response $response, string $message, string $target = '/song-library'): Response
    {
        $_SESSION['success'] = $message;
        return $response->withHeader('Location', $target)->withStatus(302);
    }
}
