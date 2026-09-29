<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolder;
use App\Models\FileFolderShare;
use App\Models\FileVersion;
use App\Models\StoredFile;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Log\LoggerInterface;

/**
 * Papierkorb der Dateiverwaltung.
 *
 * Aufgeführt werden nur Einträge, deren Behälter noch lebt. Eine Datei in
 * einem gelöschten Ordner steht nicht einzeln darin - sie kommt mit dem Ordner
 * zurück oder verschwindet mit ihm.
 *
 * Zurückholen darf, wer im Behälter bearbeiten darf; endgültig löschen nur, wer
 * ihn verwaltet. Bei Teamordnern ist der Behälter die Verwaltung selbst.
 */
final class FileTrashService
{
    public function __construct(
        private readonly FileAccessService $access,
        private readonly FileService $files,
        private readonly FileStorageRegistry $storages,
        private readonly LoggerInterface $logger,
        private readonly int $trashDays
    ) {
    }

    public function trashDays(): int
    {
        return $this->trashDays;
    }

    /**
     * @return list<array{type: string, id: int, name: string, deleted_at: Carbon, expires_at: Carbon,
     *                    container_id: ?int, can_purge: bool, size: int}>
     */
    public function listFor(FileActor $actor): array
    {
        $levels = $this->access->folderLevels($actor);
        $items = [];

        $folders = FileFolder::onlyTrashed()->orderByDesc('deleted_at')->get();
        foreach ($folders as $folder) {
            $level = $this->containerLevel($actor, $levels, $folder->parent_id);
            if ($level < FileFolderShare::LEVEL_EDIT) {
                continue;
            }
            $items[] = $this->item('folder', $folder, $folder->parent_id, $level, 0);
        }

        $files = StoredFile::onlyTrashed()
            ->whereIn('folder_id', array_keys($levels) ?: [0])
            ->orderByDesc('deleted_at')
            ->get();
        foreach ($files as $file) {
            $level = $levels[(int) $file->folder_id] ?? FileFolderShare::LEVEL_NONE;
            if ($level < FileFolderShare::LEVEL_EDIT) {
                continue;
            }
            $items[] = $this->item('file', $file, (int) $file->folder_id, $level, (int) $file->size);
        }

        usort($items, static fn (array $a, array $b): int => $b['deleted_at'] <=> $a['deleted_at']);

        return $items;
    }

    public function restoreFile(FileActor $actor, int $fileId): StoredFile
    {
        $file = StoredFile::onlyTrashed()->find($fileId) ?? throw FileManagementException::notFound();
        $this->requireContainerLevel($actor, (int) $file->folder_id, FileFolderShare::LEVEL_EDIT);

        $file->name = $this->freeName(
            $file->name,
            fn (string $name): bool => StoredFile::query()
                ->where('folder_id', $file->folder_id)->where('name', $name)->exists()
        );
        $file->deleted_by = null;
        $file->save();
        $file->restore();

        $this->logger->info('File restored from trash.', [
            'event' => 'files.restored',
            'file_id' => (int) $file->id,
            'user_id' => $actor->userId,
        ]);

        return $file;
    }

    public function restoreFolder(FileActor $actor, int $folderId): FileFolder
    {
        $folder = FileFolder::onlyTrashed()->find($folderId) ?? throw FileManagementException::notFound();
        $this->requireContainerLevel($actor, $folder->parent_id, FileFolderShare::LEVEL_EDIT);

        $folder->name = $this->freeName($folder->name, function (string $name) use ($folder): bool {
            $query = FileFolder::query()->where('name', $name);
            $folder->parent_id === null ? $query->whereNull('parent_id') : $query->where('parent_id', $folder->parent_id);

            return $query->exists();
        });
        $folder->deleted_by = null;
        $folder->save();
        $folder->restore();

        $this->logger->info('Folder restored from trash.', [
            'event' => 'files.restored',
            'folder_id' => (int) $folder->id,
            'user_id' => $actor->userId,
        ]);

        return $folder;
    }

    public function purgeFile(FileActor $actor, int $fileId): void
    {
        $file = StoredFile::onlyTrashed()->find($fileId) ?? throw FileManagementException::notFound();
        $this->requireContainerLevel($actor, (int) $file->folder_id, FileFolderShare::LEVEL_MANAGE);

        $this->deleteFiles([(int) $file->id]);
        $this->logPurge('file', (int) $file->id, $actor->userId);
    }

    public function purgeFolder(FileActor $actor, int $folderId): void
    {
        $folder = FileFolder::onlyTrashed()->find($folderId) ?? throw FileManagementException::notFound();
        $this->requireContainerLevel($actor, $folder->parent_id, FileFolderShare::LEVEL_MANAGE);

        $this->deleteFolder((int) $folder->id);
        $this->logPurge('folder', (int) $folder->id, $actor->userId);
    }

    /**
     * Löscht alles endgültig, was länger als die Frist im Papierkorb liegt.
     *
     * @return array{files: int, folders: int}
     */
    public function purgeExpired(): array
    {
        $cutoff = Carbon::now()->subDays($this->trashDays);
        $result = ['files' => 0, 'folders' => 0];

        $folderIds = FileFolder::onlyTrashed()->where('deleted_at', '<', $cutoff)->pluck('id')->all();
        foreach ($folderIds as $folderId) {
            // Ein Unterordner kann schon mit seinem Vorfahren verschwunden sein.
            if (FileFolder::withTrashed()->whereKey($folderId)->exists()) {
                $this->deleteFolder((int) $folderId);
                $this->logPurge('folder', (int) $folderId, null);
                $result['folders']++;
            }
        }

        $fileIds = StoredFile::onlyTrashed()->where('deleted_at', '<', $cutoff)->pluck('id')->all();
        $existing = StoredFile::withTrashed()->whereIn('id', $fileIds ?: [0])->pluck('id')->all();
        if ($existing !== []) {
            $this->deleteFiles(array_map('intval', $existing));
            foreach ($existing as $fileId) {
                $this->logPurge('file', (int) $fileId, null);
            }
            $result['files'] = count($existing);
        }

        return $result;
    }

    /**
     * Gespeicherte Dateien, zu denen keine Version mehr gehört - etwa nach dem
     * Einspielen eines älteren Backups.
     *
     * Junge Dateien bleiben außen vor: Ein Upload legt die Datei ab, bevor seine
     * Datenbankzeile steht. Ohne Mindestalter könnte ein Lauf genau in diesem
     * Moment eine gerade hochgeladene Datei als verwaist löschen.
     *
     * @return list<string>
     */
    public function findOrphans(LocalFileStorage $storage, int $minAgeSeconds = 3600): array
    {
        $known = array_flip(
            FileVersion::query()->where('storage_driver', $storage->name())->pluck('storage_path')->all()
        );

        $orphans = [];
        $cutoff = time() - $minAgeSeconds;
        foreach ($storage->allPaths() as $path) {
            if (isset($known[$path])) {
                continue;
            }
            $modified = @filemtime((string) $storage->localPath($path));
            if ($minAgeSeconds > 0 && $modified !== false && $modified > $cutoff) {
                continue;
            }
            $orphans[] = $path;
        }
        sort($orphans);

        return $orphans;
    }

    public function deleteOrphans(LocalFileStorage $storage, int $minAgeSeconds = 3600): int
    {
        $count = 0;
        foreach ($this->findOrphans($storage, $minAgeSeconds) as $path) {
            $storage->delete($path);
            $count++;
        }

        if ($count > 0) {
            $this->logger->info('Orphaned stored files removed.', ['event' => 'files.orphans_purged', 'count' => $count]);
        }

        return $count;
    }

    private function deleteFolder(int $folderId): void
    {
        $folderIds = $this->access->subtreeIds($folderId);
        $candidates = $this->storageCandidates(
            FileVersion::query()->whereIn('file_id', StoredFile::withTrashed()->whereIn('folder_id', $folderIds)->select('id'))
        );

        // Die Fremdschlüssel räumen Unterordner, Dateien, Versionen, Freigaben und
        // Favoriten mit ab.
        DB::connection()->transaction(static function () use ($folderId): void {
            FileFolder::withTrashed()->whereKey($folderId)->forceDelete();
        });

        $this->files->deleteUnreferencedStorage($candidates);
    }

    /**
     * @param list<int> $fileIds
     */
    private function deleteFiles(array $fileIds): void
    {
        $candidates = $this->storageCandidates(FileVersion::query()->whereIn('file_id', $fileIds));

        DB::connection()->transaction(static function () use ($fileIds): void {
            StoredFile::withTrashed()->whereIn('id', $fileIds)->forceDelete();
        });

        $this->files->deleteUnreferencedStorage($candidates);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<FileVersion> $query
     * @return list<array{storage_driver: string, storage_path: string}>
     */
    private function storageCandidates($query): array
    {
        return $query->get(['storage_driver', 'storage_path'])
            ->map(static fn (FileVersion $v): array => [
                'storage_driver' => (string) $v->storage_driver,
                'storage_path' => (string) $v->storage_path,
            ])
            ->unique(static fn (array $c): string => $c['storage_driver'] . ':' . $c['storage_path'])
            ->values()
            ->all();
    }

    /**
     * @param array<int, int> $levels
     */
    private function containerLevel(FileActor $actor, array $levels, ?int $containerId): int
    {
        if ($containerId === null) {
            return $actor->isFileAdmin ? FileFolderShare::LEVEL_MANAGE : FileFolderShare::LEVEL_NONE;
        }

        return $levels[$containerId] ?? FileFolderShare::LEVEL_NONE;
    }

    private function requireContainerLevel(FileActor $actor, ?int $containerId, int $level): void
    {
        $actual = $this->containerLevel($actor, $this->access->folderLevels($actor), $containerId);
        if ($actual === FileFolderShare::LEVEL_NONE) {
            throw FileManagementException::notFound();
        }
        if ($actual < $level) {
            throw FileManagementException::forbidden();
        }
    }

    /**
     * @param callable(string): bool $taken
     */
    private function freeName(string $name, callable $taken): string
    {
        if (!$taken($name)) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
        $suffix = $extension === '' ? '' : '.' . $extension;

        for ($i = 1; $i < 100; $i++) {
            $label = $i === 1 ? ' (wiederhergestellt)' : ' (wiederhergestellt ' . $i . ')';
            $candidate = FileNameCleaner::clean($stem . $label . $suffix) ?? $name;
            if (!$taken($candidate)) {
                return $candidate;
            }
        }

        throw new FileManagementException('Für den Eintrag ließ sich kein freier Name finden.', 409);
    }

    /**
     * @return array{type: string, id: int, name: string, deleted_at: Carbon, expires_at: Carbon,
     *               container_id: ?int, can_purge: bool, size: int}
     */
    private function item(string $type, FileFolder|StoredFile $model, ?int $containerId, int $level, int $size): array
    {
        $deletedAt = Carbon::parse($model->deleted_at);

        return [
            'type' => $type,
            'id' => (int) $model->id,
            'name' => (string) $model->name,
            'deleted_at' => $deletedAt,
            'expires_at' => $deletedAt->copy()->addDays($this->trashDays),
            'container_id' => $containerId,
            'can_purge' => $level >= FileFolderShare::LEVEL_MANAGE,
            'size' => $size,
        ];
    }

    private function logPurge(string $type, int $id, ?int $userId): void
    {
        $this->logger->info('Trash entry purged.', [
            'event' => 'files.purged',
            'type' => $type,
            'id' => $id,
            'user_id' => $userId,
        ]);
    }
}
