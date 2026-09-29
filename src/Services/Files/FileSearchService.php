<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolder;
use App\Models\StoredFile;

/**
 * Namenssuche über alle Ordner, die der Nutzer sehen darf. Gefiltert wird über
 * die erreichbaren Ordner aus FileAccessService - eine Suche darf nie verraten,
 * dass es eine Datei in einem fremden Ordner gibt.
 */
final class FileSearchService
{
    public const MIN_LENGTH = 2;
    public const LIMIT = 50;

    public function __construct(private readonly FileAccessService $access)
    {
    }

    /**
     * @return array{
     *     folders: list<array{id: int, name: string, path: list<array{id: int, name: string}>}>,
     *     files: list<array{id: int, name: string, size: int, mime_type: string, folder_id: int,
     *                       updated_at: mixed, path: list<array{id: int, name: string}>}>
     * }
     */
    public function search(FileActor $actor, string $term): array
    {
        $term = trim($term);
        $empty = ['folders' => [], 'files' => []];
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return $empty;
        }

        $levels = $this->access->folderLevels($actor);
        if ($levels === []) {
            return $empty;
        }
        $visibleIds = array_keys($levels);
        $pattern = '%' . addcslashes($term, '%_\\') . '%';

        $folders = FileFolder::query()
            ->whereIn('id', $visibleIds)
            ->where('name', 'like', $pattern)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'parent_id']);

        $files = StoredFile::query()
            ->whereIn('folder_id', $visibleIds)
            ->where('name', 'like', $pattern)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'size', 'mime_type', 'folder_id', 'updated_at']);

        $paths = $this->access->pathsFor(array_values(array_unique(array_merge(
            $folders->pluck('parent_id')->filter()->map(fn ($id): int => (int) $id)->all(),
            $files->pluck('folder_id')->map(fn ($id): int => (int) $id)->all()
        ))));

        return [
            'folders' => $folders->map(fn (FileFolder $folder): array => [
                'id' => (int) $folder->id,
                'name' => (string) $folder->name,
                'path' => $folder->parent_id === null ? [] : ($paths[(int) $folder->parent_id] ?? []),
            ])->values()->all(),
            'files' => $files->map(fn (StoredFile $file): array => [
                'id' => (int) $file->id,
                'name' => (string) $file->name,
                'size' => (int) $file->size,
                'mime_type' => (string) $file->mime_type,
                'folder_id' => (int) $file->folder_id,
                'updated_at' => $file->updated_at,
                'path' => $paths[(int) $file->folder_id] ?? [],
            ])->values()->all(),
        ];
    }
}
