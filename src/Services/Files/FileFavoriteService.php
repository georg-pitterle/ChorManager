<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFavorite;
use App\Models\FileFolder;
use App\Models\StoredFile;

/**
 * Angeheftete Dateien und Ordner. Ein Favorit, dessen Ziel der Nutzer nicht
 * mehr sehen darf, bleibt gespeichert, erscheint aber nicht - kommt die
 * Freigabe zurück, ist er wieder da.
 */
final class FileFavoriteService
{
    public const TYPE_FILE = 'file';
    public const TYPE_FOLDER = 'folder';

    public function __construct(private readonly FileAccessService $access)
    {
    }

    /**
     * @return bool ob der Eintrag jetzt Favorit ist
     */
    public function toggle(FileActor $actor, string $type, int $id): bool
    {
        $column = $this->assertVisible($actor, $type, $id);

        $existing = FileFavorite::query()->where('user_id', $actor->userId)->where($column, $id)->first();
        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        FileFavorite::create(['user_id' => $actor->userId, $column => $id]);

        return true;
    }

    /**
     * @return array{folders: list<FileFolder>, files: list<StoredFile>}
     */
    public function listFor(FileActor $actor): array
    {
        $visibleIds = array_keys($this->access->folderLevels($actor));
        $favorites = FileFavorite::query()->where('user_id', $actor->userId)->get();

        $folderIds = $favorites->pluck('folder_id')->filter()->all();
        $fileIds = $favorites->pluck('file_id')->filter()->all();

        $folders = FileFolder::query()
            ->whereIn('id', array_intersect($folderIds, $visibleIds) ?: [0])
            ->orderBy('name')
            ->get();
        $files = StoredFile::query()
            ->whereIn('id', $fileIds ?: [0])
            ->whereIn('folder_id', $visibleIds ?: [0])
            ->orderBy('name')
            ->get();

        return ['folders' => $folders->all(), 'files' => $files->all()];
    }

    /**
     * @return list<int>
     */
    public function favoriteIds(FileActor $actor, string $type): array
    {
        $column = $type === self::TYPE_FILE ? 'file_id' : 'folder_id';

        return FileFavorite::query()
            ->where('user_id', $actor->userId)
            ->whereNotNull($column)
            ->pluck($column)
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function assertVisible(FileActor $actor, string $type, int $id): string
    {
        if ($type === self::TYPE_FOLDER) {
            if ($this->access->levelFor($actor, $id) === 0) {
                throw FileManagementException::notFound();
            }

            return 'folder_id';
        }

        if ($type === self::TYPE_FILE) {
            $file = StoredFile::find($id);
            if ($file === null || $this->access->levelFor($actor, (int) $file->folder_id) === 0) {
                throw FileManagementException::notFound();
            }

            return 'file_id';
        }

        throw FileManagementException::notFound();
    }
}
