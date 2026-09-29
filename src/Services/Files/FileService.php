<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolder;
use App\Models\FileFolderShare;
use App\Models\FileVersion;
use App\Models\StoredFile;
use App\Util\UploadValidator;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Dateien hochladen, versionieren, umbenennen, verschieben und in den
 * Papierkorb legen. Wie im Ordner-Service prüft jede Methode die Stufe selbst.
 *
 * Protokolliert werden Kennungen, nie Dateinamen: Der Name stammt vom
 * Hochladenden und hat in einer Logzeile nichts verloren.
 */
final class FileService
{
    /**
     * Endungen, die sich auf einem Server oder Rechner ausführen lassen. Geliefert
     * wird zwar nie inline, aber die Dateiverwaltung ist kein Verteilweg für
     * Programme.
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'phps',
        'exe', 'msi', 'com', 'scr', 'bat', 'cmd', 'ps1', 'vbs', 'vbe', 'jar',
        'sh', 'cgi', 'pl', 'dll', 'htaccess',
    ];

    private const BLOCKED_MIME_TYPES = [
        'application/x-dosexec',
        'application/x-msdownload',
        'application/x-executable',
        'application/x-sharedlib',
        'application/x-mach-binary',
        'application/x-httpd-php',
        'application/x-php',
        'text/x-php',
        'text/x-shellscript',
        'application/x-sh',
    ];

    public function __construct(
        private readonly FileAccessService $access,
        private readonly FileFolderService $folders,
        private readonly FileQuotaService $quota,
        private readonly FileStorageRegistry $storages,
        private readonly LoggerInterface $logger,
        private readonly int $maxUploadBytes,
        private readonly int $maxVersions
    ) {
    }

    public function maxUploadBytes(): int
    {
        return $this->maxUploadBytes;
    }

    public function upload(FileActor $actor, FileFolder $folder, UploadedFileInterface $upload): UploadResult
    {
        $this->folders->requireLevel($actor, $folder, FileFolderShare::LEVEL_UPLOAD);
        $this->assertUploadOk($upload);

        $name = FileNameCleaner::clean((string) $upload->getClientFilename());
        if ($name === null) {
            throw new FileManagementException('Die Datei hat keinen gültigen Namen.', 422);
        }
        $this->assertAllowedExtension($name);

        $existing = StoredFile::query()->where('folder_id', $folder->id)->where('name', $name)->first();
        if ($existing !== null && $this->access->fileLevelFor($actor, $existing) < FileFolderShare::LEVEL_EDIT) {
            throw new FileManagementException(
                'Eine Datei mit diesem Namen existiert bereits. Zum Ersetzen fehlt die Berechtigung.',
                409
            );
        }

        return $this->store($actor, $folder, $upload, $name, $existing);
    }

    /**
     * Neue Version einer bestimmten Datei, unabhängig vom Namen der
     * hochgeladenen Datei. Der Weg für alle, die die Datei über eine
     * Dateifreigabe bearbeiten dürfen, den Ordner aber nicht sehen.
     */
    public function replace(FileActor $actor, int $fileId, UploadedFileInterface $upload): UploadResult
    {
        $file = $this->findWithFileLevel($actor, $fileId, FileFolderShare::LEVEL_EDIT);
        $this->assertUploadOk($upload);
        $uploadedName = FileNameCleaner::clean((string) $upload->getClientFilename());
        if ($uploadedName !== null) {
            $this->assertAllowedExtension($uploadedName);
        }

        return $this->store($actor, $file->folder, $upload, (string) $file->name, $file);
    }

    private function assertUploadOk(UploadedFileInterface $upload): void
    {
        $uploadError = UploadValidator::getUploadErrorMessage($upload->getError());
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new FileManagementException($uploadError ?? 'Es wurde keine Datei übertragen.', 422);
        }
    }

    private function store(
        FileActor $actor,
        FileFolder $folder,
        UploadedFileInterface $upload,
        string $name,
        ?StoredFile $existing
    ): UploadResult {
        [$sourcePath, $isTemporary] = $this->localSource($upload);
        try {
            $size = (int) filesize($sourcePath);
            if ($size > $this->maxUploadBytes) {
                throw new FileManagementException(sprintf(
                    'Die Datei ist zu groß (höchstens %d MB).',
                    intdiv($this->maxUploadBytes, 1024 * 1024) ?: 1
                ), 413);
            }

            $mimeType = $this->detectMimeType($sourcePath, $upload);
            if (in_array($mimeType, self::BLOCKED_MIME_TYPES, true)) {
                $this->logger->warning('File upload rejected.', [
                    'event' => 'security.upload.rejected',
                    'reason' => 'blocked_mime_type',
                    'mime_type' => $mimeType,
                    'user_id' => $actor->userId,
                ]);
                throw new FileManagementException('Dieser Dateityp ist nicht erlaubt.', 422);
            }

            $this->assertQuota($folder, $size);

            $sha256 = (string) hash_file('sha256', $sourcePath);
            $storage = $this->storages->default();
            $storagePath = $storage->put($sourcePath);
        } finally {
            if ($isTemporary) {
                @unlink($sourcePath);
            }
        }

        try {
            $file = DB::connection()->transaction(function () use (
                $actor,
                $folder,
                $existing,
                $name,
                $size,
                $mimeType,
                $sha256,
                $storage,
                $storagePath
            ): StoredFile {
                $file = $existing ?? StoredFile::create([
                    'folder_id' => (int) $folder->id,
                    'name' => $name,
                    'size' => $size,
                    'mime_type' => $mimeType,
                    'created_by' => $actor->userId,
                    'updated_by' => $actor->userId,
                ]);

                $this->addVersion($file, $actor, [
                    'storage_driver' => $storage->name(),
                    'storage_path' => $storagePath,
                    'size' => $size,
                    'mime_type' => $mimeType,
                    'sha256' => $sha256,
                ]);

                return $file;
            });
        } catch (\Throwable $exception) {
            // Ohne Datenbankzeile gehört die Datei niemandem - gleich wieder weg.
            $storage->delete($storagePath);
            throw $exception;
        }

        $this->pruneVersions($file);

        $this->logger->info($existing !== null ? 'File version created.' : 'File uploaded.', [
            'event' => $existing !== null ? 'files.version_created' : 'files.uploaded',
            'file_id' => (int) $file->id,
            'folder_id' => (int) $folder->id,
            'size' => $size,
            'user_id' => $actor->userId,
        ]);

        return new UploadResult($file->fresh(['currentVersion']) ?? $file, $existing !== null);
    }

    public function restoreVersion(FileActor $actor, FileVersion $version): StoredFile
    {
        $file = $this->findWithFileLevel($actor, (int) $version->file_id, FileFolderShare::LEVEL_EDIT);
        if ((int) $file->current_version_id === (int) $version->id) {
            return $file;
        }

        $this->assertQuota($file->folder, 0);

        DB::connection()->transaction(function () use ($actor, $file, $version): void {
            // Kein Kopieren: Die alte Fassung ist unveränderlich, die neue Version
            // zeigt auf denselben Pfad. Gelöscht wird ein Pfad erst, wenn keine
            // Version mehr auf ihn zeigt.
            $this->addVersion($file, $actor, [
                'storage_driver' => $version->storage_driver,
                'storage_path' => $version->storage_path,
                'size' => $version->size,
                'mime_type' => $version->mime_type,
                'sha256' => $version->sha256,
            ]);
        });
        $this->pruneVersions($file);

        $this->logger->info('File version restored.', [
            'event' => 'files.version_restored',
            'file_id' => (int) $file->id,
            'version_id' => (int) $version->id,
            'user_id' => $actor->userId,
        ]);

        return $file->fresh(['currentVersion']) ?? $file;
    }

    public function renameFile(FileActor $actor, StoredFile $file, string $name): StoredFile
    {
        $this->findWithLevel($actor, (int) $file->id, FileFolderShare::LEVEL_EDIT);

        $clean = FileNameCleaner::clean($name);
        if ($clean === null) {
            throw new FileManagementException('Bitte einen gültigen Namen angeben.', 422);
        }
        $this->assertAllowedExtension($clean);

        if ($clean !== $file->name) {
            $this->assertFileNameFree((int) $file->folder_id, $clean, (int) $file->id);
            $file->name = $clean;
            $file->updated_by = $actor->userId;
            $file->save();
        }

        return $file;
    }

    public function moveFile(FileActor $actor, StoredFile $file, FileFolder $target): StoredFile
    {
        $this->findWithLevel($actor, (int) $file->id, FileFolderShare::LEVEL_EDIT);
        $this->folders->requireLevel($actor, $target, FileFolderShare::LEVEL_EDIT);
        if ((int) $file->folder_id === (int) $target->id) {
            return $file;
        }

        $this->assertFileNameFree((int) $target->id, $file->name, (int) $file->id);
        if ($this->access->rootIdOf((int) $file->folder_id) !== $this->access->rootIdOf($target)) {
            $this->quota->assertFits($this->access->rootIdOf($target), $this->bytesOf($file));
        }

        $file->folder_id = (int) $target->id;
        $file->updated_by = $actor->userId;
        $file->save();

        $this->logger->info('File moved.', [
            'event' => 'files.file_moved',
            'file_id' => (int) $file->id,
            'target_folder_id' => (int) $target->id,
            'user_id' => $actor->userId,
        ]);

        return $file;
    }

    public function trashFile(FileActor $actor, StoredFile $file): void
    {
        $this->findWithLevel($actor, (int) $file->id, FileFolderShare::LEVEL_EDIT);

        $file->deleted_by = $actor->userId;
        $file->save();
        $file->delete();

        $this->logger->info('File moved to trash.', [
            'event' => 'files.deleted',
            'file_id' => (int) $file->id,
            'user_id' => $actor->userId,
        ]);
    }

    public function findReadable(FileActor $actor, int $fileId): StoredFile
    {
        return $this->findWithFileLevel($actor, $fileId, FileFolderShare::LEVEL_READ);
    }

    /**
     * Stufe der Datei selbst: Ordnerstufe oder Dateifreigabe, je nachdem, was
     * höher ist. Für alles, was nur die Datei betrifft (lesen, neue Version).
     */
    public function findWithFileLevel(FileActor $actor, int $fileId, int $level): StoredFile
    {
        $file = StoredFile::find($fileId);
        if ($file === null) {
            throw FileManagementException::notFound();
        }

        $actual = $this->access->fileLevelFor($actor, $file);
        if ($actual === FileFolderShare::LEVEL_NONE) {
            throw FileManagementException::notFound();
        }
        if ($actual < $level) {
            throw FileManagementException::forbidden();
        }

        return $file;
    }

    public function findReadableVersion(FileActor $actor, int $versionId): FileVersion
    {
        $version = FileVersion::find($versionId);
        if ($version === null) {
            throw FileManagementException::notFound();
        }
        $this->findReadable($actor, (int) $version->file_id);

        return $version;
    }

    /**
     * Stufe im Ordner der Datei. Für alles, was den Ordner verändert
     * (umbenennen, verschieben, in den Papierkorb) - eine Dateifreigabe reicht
     * dafür nicht.
     */
    public function findWithLevel(FileActor $actor, int $fileId, int $level): StoredFile
    {
        $file = StoredFile::find($fileId);
        if ($file === null) {
            throw FileManagementException::notFound();
        }
        $this->folders->requireLevel($actor, $file->folder, $level);

        return $file;
    }

    /**
     * Speicherpfade entfernen, auf die keine Version mehr zeigt.
     *
     * @param iterable<array{storage_driver: string, storage_path: string}> $candidates
     */
    public function deleteUnreferencedStorage(iterable $candidates): void
    {
        foreach ($candidates as $candidate) {
            $stillUsed = FileVersion::query()
                ->where('storage_driver', $candidate['storage_driver'])
                ->where('storage_path', $candidate['storage_path'])
                ->exists();
            if (!$stillUsed) {
                $this->storages->for($candidate['storage_driver'])->delete($candidate['storage_path']);
            }
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function addVersion(StoredFile $file, FileActor $actor, array $attributes): FileVersion
    {
        $next = (int) FileVersion::query()->where('file_id', $file->id)->lockForUpdate()->max('version_number') + 1;

        $version = FileVersion::create($attributes + [
            'file_id' => (int) $file->id,
            'version_number' => $next,
            'uploaded_by' => $actor->userId,
        ]);

        $file->current_version_id = (int) $version->id;
        $file->size = (int) $attributes['size'];
        $file->mime_type = (string) $attributes['mime_type'];
        $file->updated_by = $actor->userId;
        $file->save();

        return $version;
    }

    private function pruneVersions(StoredFile $file): void
    {
        $excess = FileVersion::query()
            ->where('file_id', $file->id)
            ->orderByDesc('version_number')
            ->skip($this->maxVersions)
            ->take(PHP_INT_MAX)
            ->get();
        if ($excess->isEmpty()) {
            return;
        }

        $candidates = $excess->map(fn (FileVersion $v): array => [
            'storage_driver' => $v->storage_driver,
            'storage_path' => $v->storage_path,
        ])->all();

        FileVersion::query()->whereIn('id', $excess->pluck('id')->all())->delete();
        $this->deleteUnreferencedStorage($candidates);
    }

    private function assertQuota(FileFolder $folder, int $additionalBytes): void
    {
        try {
            $this->quota->assertFits($this->access->rootIdOf($folder), $additionalBytes);
        } catch (FileManagementException $exception) {
            $this->logger->notice('Upload rejected by quota.', [
                'event' => 'files.quota_exceeded',
                'folder_id' => (int) $folder->id,
                'size' => $additionalBytes,
            ]);
            throw $exception;
        }
    }

    private function bytesOf(StoredFile $file): int
    {
        return (int) DB::query()
            ->fromSub(
                DB::table('file_versions')->where('file_id', $file->id)
                    ->select('storage_path', 'size')->distinct(),
                'distinct_versions'
            )
            ->sum('size');
    }

    private function assertFileNameFree(int $folderId, string $name, int $exceptId): void
    {
        $taken = StoredFile::query()
            ->where('folder_id', $folderId)
            ->where('name', $name)
            ->where('id', '!=', $exceptId)
            ->exists();
        if ($taken) {
            throw new FileManagementException('Eine Datei mit diesem Namen existiert dort bereits.', 409);
        }
    }

    private function assertAllowedExtension(string $name): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $isDotFile = str_starts_with($name, '.') && strtolower(ltrim($name, '.')) === 'htaccess';
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true) || $isDotFile) {
            throw new FileManagementException('Dieser Dateityp ist nicht erlaubt.', 422);
        }
    }

    /**
     * Pfad einer lesbaren Datei mit dem Upload-Inhalt. Ein echter Upload liegt
     * schon als Temp-Datei vor; ein Stream ohne Datei dahinter (etwa im Test)
     * wird in eine eigene Temp-Datei geschrieben.
     *
     * @return array{0: string, 1: bool} Pfad und ob er danach zu löschen ist
     */
    private function localSource(UploadedFileInterface $upload): array
    {
        $stream = $upload->getStream();
        $uri = $stream->getMetadata('uri');
        if (is_string($uri) && $uri !== '' && is_file($uri)) {
            return [$uri, false];
        }

        $temp = tempnam(sys_get_temp_dir(), 'upl');
        if ($temp === false) {
            throw new \RuntimeException('Could not create temporary file.');
        }
        $stream->rewind();
        $out = fopen($temp, 'wb');
        while (!$stream->eof()) {
            fwrite($out, $stream->read(1024 * 1024));
        }
        fclose($out);

        return [$temp, true];
    }

    private function detectMimeType(string $path, UploadedFileInterface $upload): string
    {
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (is_string($detected) && $detected !== '') {
            return UploadValidator::normalizeMimeType($detected);
        }

        return UploadValidator::normalizeMimeType($upload->getClientMediaType() ?: 'application/octet-stream');
    }
}
