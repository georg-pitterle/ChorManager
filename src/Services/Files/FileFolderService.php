<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolder;
use App\Models\FileFolderShare;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Log\LoggerInterface;

/**
 * Ordner anlegen, umbenennen, verschieben, in den Papierkorb legen, und die
 * Freigaben eines Ordners setzen. Jede Methode prüft die nötige Stufe selbst,
 * damit kein Controller eine Prüfung vergessen kann.
 */
final class FileFolderService
{
    public function __construct(
        private readonly FileAccessService $access,
        private readonly FileQuotaService $quota,
        private readonly LoggerInterface $logger
    ) {
    }

    public function createRoot(FileActor $actor, string $name, ?int $quotaBytes): FileFolder
    {
        if (!$actor->isFileAdmin) {
            throw FileManagementException::forbidden();
        }

        $clean = $this->cleanName($name);
        $this->assertNameFree(null, $clean);

        $folder = FileFolder::create([
            'parent_id' => null,
            'name' => $clean,
            'quota_bytes' => $this->normalizeQuota($quotaBytes),
            'created_by' => $actor->userId,
        ]);

        $this->logger->info('Team folder created.', [
            'event' => 'files.folder_created',
            'folder_id' => (int) $folder->id,
            'root' => true,
            'user_id' => $actor->userId,
        ]);

        return $folder;
    }

    public function create(FileActor $actor, FileFolder $parent, string $name): FileFolder
    {
        $this->requireLevel($actor, $parent, FileFolderShare::LEVEL_UPLOAD);

        $clean = $this->cleanName($name);
        $this->assertNameFree((int) $parent->id, $clean);

        $folder = FileFolder::create([
            'parent_id' => (int) $parent->id,
            'name' => $clean,
            'created_by' => $actor->userId,
        ]);

        $this->logger->info('Folder created.', [
            'event' => 'files.folder_created',
            'folder_id' => (int) $folder->id,
            'root' => false,
            'user_id' => $actor->userId,
        ]);

        return $folder;
    }

    public function rename(FileActor $actor, FileFolder $folder, string $name): FileFolder
    {
        $this->requireLevel($actor, $folder, FileFolderShare::LEVEL_EDIT);
        if ($folder->isRoot() && !$actor->isFileAdmin) {
            throw FileManagementException::forbidden();
        }

        $clean = $this->cleanName($name);
        if ($clean !== $folder->name) {
            $this->assertNameFree($folder->parent_id, $clean, (int) $folder->id);
            $folder->name = $clean;
            $folder->save();
        }

        return $folder;
    }

    public function move(FileActor $actor, FileFolder $folder, FileFolder $target): FileFolder
    {
        if ($folder->isRoot()) {
            throw new FileManagementException('Teamordner lassen sich nicht verschieben.', 422);
        }
        $this->requireLevel($actor, $folder, FileFolderShare::LEVEL_EDIT);
        $this->requireLevel($actor, $target, FileFolderShare::LEVEL_EDIT);

        if (in_array((int) $folder->id, $this->access->ancestry($target), true)) {
            throw new FileManagementException('Ein Ordner kann nicht in sich selbst verschoben werden.', 422);
        }
        if ((int) $folder->parent_id === (int) $target->id) {
            return $folder;
        }

        $this->assertNameFree((int) $target->id, $folder->name, (int) $folder->id);
        $this->assertQuotaForMove($folder, $target);

        $folder->parent_id = (int) $target->id;
        $folder->save();

        $this->logger->info('Folder moved.', [
            'event' => 'files.folder_moved',
            'folder_id' => (int) $folder->id,
            'target_folder_id' => (int) $target->id,
            'user_id' => $actor->userId,
        ]);

        return $folder;
    }

    public function trash(FileActor $actor, FileFolder $folder): void
    {
        if ($folder->isRoot() && !$actor->isFileAdmin) {
            throw FileManagementException::forbidden();
        }
        $this->requireLevel($actor, $folder, FileFolderShare::LEVEL_EDIT);

        $folder->deleted_by = $actor->userId;
        $folder->save();
        $folder->delete();

        $this->logger->info('Folder moved to trash.', [
            'event' => 'files.deleted',
            'folder_id' => (int) $folder->id,
            'user_id' => $actor->userId,
        ]);
    }

    public function setQuota(FileActor $actor, FileFolder $folder, ?int $quotaBytes): void
    {
        if (!$actor->isFileAdmin) {
            throw FileManagementException::forbidden();
        }
        if (!$folder->isRoot()) {
            throw new FileManagementException('Ein Kontingent gibt es nur für Teamordner.', 422);
        }

        $folder->quota_bytes = $this->normalizeQuota($quotaBytes);
        $folder->save();
    }

    /**
     * Ersetzt alle Freigaben des Ordners. Unbekannte Typen und Stufen fallen
     * weg; doppelte Ziele behalten die höchste Stufe.
     *
     * @param array<int, mixed> $rawShares Einträge mit type, reference_id, level
     */
    public function setShares(FileActor $actor, FileFolder $folder, array $rawShares): void
    {
        $this->requireLevel($actor, $folder, FileFolderShare::LEVEL_MANAGE);
        $shares = $this->normalizeShares($rawShares);

        DB::connection()->transaction(function () use ($actor, $folder, $shares): void {
            FileFolderShare::query()->where('folder_id', $folder->id)->delete();
            foreach ($shares as $share) {
                FileFolderShare::create([
                    'folder_id' => (int) $folder->id,
                    'target_type' => $share['type'],
                    'reference_id' => $share['reference_id'],
                    'level' => $share['level'],
                    'created_by' => $actor->userId,
                ]);
            }

            // Wer hier verwaltet, soll sich nicht versehentlich selbst aussperren -
            // sonst bliebe der Ordner bis zum Eingreifen der Verwaltung verwaist.
            if (!$this->access->can($actor, $folder, FileFolderShare::LEVEL_MANAGE)) {
                throw new FileManagementException(
                    'Diese Freigaben würden dir selbst die Verwaltung entziehen.',
                    422
                );
            }
        });

        $this->logger->info('Folder shares changed.', [
            'event' => 'files.share_changed',
            'folder_id' => (int) $folder->id,
            'share_count' => count($shares),
            'user_id' => $actor->userId,
        ]);
    }

    /**
     * @param array<int, mixed> $rawShares
     * @return list<array{type: string, reference_id: int, level: int}>
     */
    public function normalizeShares(array $rawShares): array
    {
        $byTarget = [];
        foreach ($rawShares as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $type = (string) ($raw['type'] ?? '');
            $level = (int) ($raw['level'] ?? 0);
            $referenceId = (int) ($raw['reference_id'] ?? 0);

            if (!in_array($type, FileFolderShare::TYPES, true)) {
                continue;
            }
            if (!isset(FileFolderShare::LEVEL_LABELS[$level])) {
                continue;
            }
            if ($type === FileFolderShare::TYPE_ALL_MEMBERS) {
                $referenceId = 0;
            } elseif ($referenceId <= 0) {
                continue;
            }

            $key = $type . ':' . $referenceId;
            if (!isset($byTarget[$key]) || $byTarget[$key]['level'] < $level) {
                $byTarget[$key] = ['type' => $type, 'reference_id' => $referenceId, 'level' => $level];
            }
        }

        return array_values($byTarget);
    }

    public function requireLevel(FileActor $actor, FileFolder $folder, int $level): void
    {
        $actual = $this->access->levelFor($actor, $folder);
        if ($actual === FileFolderShare::LEVEL_NONE) {
            // Wer den Ordner gar nicht sieht, erfährt auch nicht, dass es ihn gibt.
            throw FileManagementException::notFound();
        }
        if ($actual < $level) {
            $this->logger->warning('File management action denied.', [
                'event' => 'files.access_denied',
                'folder_id' => (int) $folder->id,
                'required_level' => $level,
                'actual_level' => $actual,
                'user_id' => $actor->userId,
            ]);
            throw FileManagementException::forbidden();
        }
    }

    public function cleanName(string $name): string
    {
        $clean = FileNameCleaner::clean($name);
        if ($clean === null) {
            throw new FileManagementException('Bitte einen gültigen Namen angeben.', 422);
        }

        return $clean;
    }

    public function assertNameFree(?int $parentId, string $name, ?int $exceptId = null): void
    {
        $query = FileFolder::query()->where('name', $name);
        $parentId === null ? $query->whereNull('parent_id') : $query->where('parent_id', $parentId);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw new FileManagementException('Ein Ordner mit diesem Namen existiert hier bereits.', 409);
        }
    }

    /**
     * Wechselt ein Ordner in einen anderen Teamordner, nimmt er seine Belegung mit.
     */
    private function assertQuotaForMove(FileFolder $folder, FileFolder $target): void
    {
        $sourceRoot = $this->access->rootIdOf($folder);
        $targetRoot = $this->access->rootIdOf($target);
        if ($sourceRoot === $targetRoot) {
            return;
        }

        $this->quota->assertFits($targetRoot, $this->quota->usedBytesInSubtree((int) $folder->id));
    }

    private function normalizeQuota(?int $quotaBytes): ?int
    {
        return $quotaBytes !== null && $quotaBytes > 0 ? $quotaBytes : null;
    }
}
