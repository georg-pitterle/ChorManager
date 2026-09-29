<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolder;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Belegung und Kontingente. Gezählt wird, was tatsächlich Platz belegt: alle
 * Versionen, auch die im Papierkorb. Teilen sich zwei Versionen denselben
 * Speicherpfad (nach dem Zurückholen einer alten Version), zählt er einmal.
 */
final class FileQuotaService
{
    public function __construct(
        private readonly FileAccessService $access,
        private readonly int $totalQuotaBytes
    ) {
    }

    public function usedBytesInSubtree(int $folderId): int
    {
        return $this->sumForFolders($this->access->subtreeIds($folderId));
    }

    public function totalUsedBytes(): int
    {
        return $this->sumForFolders(null);
    }

    public function totalQuotaBytes(): int
    {
        return $this->totalQuotaBytes;
    }

    /**
     * @throws FileManagementException wenn das Kontingent des Teamordners oder
     *                                 das Gesamtlimit überschritten würde
     */
    public function assertFits(int $rootFolderId, int $additionalBytes): void
    {
        if ($additionalBytes <= 0) {
            return;
        }

        $root = FileFolder::withTrashed()->find($rootFolderId);
        $quota = $root?->quota_bytes;
        if ($quota !== null && $this->usedBytesInSubtree($rootFolderId) + $additionalBytes > $quota) {
            throw new FileManagementException(
                'Das Speicherkontingent dieses Teamordners reicht dafür nicht aus.',
                413
            );
        }

        if ($this->totalQuotaBytes > 0 && $this->totalUsedBytes() + $additionalBytes > $this->totalQuotaBytes) {
            throw new FileManagementException('Der gesamte Speicherplatz der Dateiverwaltung ist erschöpft.', 413);
        }
    }

    /**
     * @param list<int>|null $folderIds null = alle Ordner
     */
    private function sumForFolders(?array $folderIds): int
    {
        if ($folderIds === []) {
            return 0;
        }

        $inner = DB::table('file_versions')
            ->join('files', 'files.id', '=', 'file_versions.file_id')
            ->select('file_versions.storage_path', 'file_versions.size')
            ->distinct();
        if ($folderIds !== null) {
            $inner->whereIn('files.folder_id', $folderIds);
        }

        $sum = DB::query()->fromSub($inner, 'distinct_versions')->sum('size');

        return (int) $sum;
    }
}
