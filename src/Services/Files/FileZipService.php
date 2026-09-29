<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolder;
use App\Models\FileFolderShare;
use App\Models\FileVersion;
use App\Models\StoredFile;

/**
 * Ein Ordner samt lebender Unterordner als ZIP. Das Archiv entsteht in einer
 * Temp-Datei, die der Aufrufer nach dem Ausliefern löscht.
 *
 * Wer einen Ordner lesen darf, darf auch alles darunter lesen (Freigaben
 * erweitern nur) - eine Prüfung je Unterordner ist deshalb nicht nötig.
 */
final class FileZipService
{
    public function __construct(
        private readonly FileAccessService $access,
        private readonly FileFolderService $folders,
        private readonly FileStorageRegistry $storages,
        private readonly int $maxZipBytes
    ) {
    }

    public function build(FileActor $actor, FileFolder $folder): string
    {
        $this->folders->requireLevel($actor, $folder, FileFolderShare::LEVEL_READ);

        $levels = $this->access->folderLevels($actor);
        $folderIds = array_values(array_filter(
            $this->access->subtreeIds((int) $folder->id),
            static fn (int $id): bool => isset($levels[$id])
        ));

        $files = StoredFile::query()->whereIn('folder_id', $folderIds ?: [0])->get();
        $total = (int) $files->sum('size');
        if ($total > $this->maxZipBytes) {
            throw new FileManagementException(sprintf(
                'Der Ordner ist für einen ZIP-Download zu groß (höchstens %d MB).',
                max(1, intdiv($this->maxZipBytes, 1024 * 1024))
            ), 413);
        }

        $paths = $this->access->pathsFor($folderIds);
        $prefixLength = count($paths[(int) $folder->id] ?? []) - 1;
        $relative = [];
        foreach ($paths as $id => $chain) {
            $names = array_map(
                static fn (array $node): string => self::zipSafe($node['name']),
                array_slice($chain, max(0, $prefixLength))
            );
            $relative[$id] = implode('/', $names);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        if ($zipPath === false) {
            throw new \RuntimeException('Could not create temporary ZIP file.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not open ZIP archive.');
        }

        foreach ($folderIds as $id) {
            if ($id !== (int) $folder->id) {
                $zip->addEmptyDir($relative[$id]);
            }
        }

        $versions = FileVersion::query()
            ->whereIn('id', $files->pluck('current_version_id')->filter()->all() ?: [0])
            ->get()
            ->keyBy('id');
        foreach ($files as $file) {
            $version = $versions[(int) $file->current_version_id] ?? null;
            if ($version === null) {
                continue;
            }
            $entry = $relative[(int) $file->folder_id] . '/' . self::zipSafe((string) $file->name);
            $storage = $this->storages->for((string) $version->storage_driver);
            $local = $storage->localPath((string) $version->storage_path);
            if ($local !== null) {
                $zip->addFile($local, $entry);
            } else {
                $zip->addFromString($entry, $storage->read((string) $version->storage_path));
            }
        }

        if ($zip->numFiles === 0) {
            // Ein leeres Archiv lässt sich sonst nicht schreiben.
            $zip->addEmptyDir($relative[(int) $folder->id]);
        }
        $zip->close();

        return $zipPath;
    }

    private static function zipSafe(string $name): string
    {
        return str_replace(['/', '\\'], '_', $name);
    }
}
