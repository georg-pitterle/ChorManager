<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\FileFolder;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileQuotaService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Dateiablage. Gezählt wird wie im Kontingent: jeder Speicherpfad einmal, auch wenn
 * mehrere Versionen auf ihn zeigen. Ein Pfad landet in der ersten zutreffenden
 * Kategorie - aktuelle Version vor älterer Version vor Papierkorb -, damit eine
 * spätere Aufräum-Funktion für "ältere Versionen" nie einen Pfad anfasst, den eine
 * aktuelle Version noch braucht.
 */
final class FileStorageUsageProvider implements StorageUsageProvider
{
    private const RANK_CURRENT = 0;
    private const RANK_OLD = 1;
    private const RANK_TRASH = 2;

    private const CATEGORIES = [
        self::RANK_CURRENT => ['files.current', 'Aktuelle Versionen'],
        self::RANK_OLD => ['files.versions.old', 'Ältere Versionen'],
        self::RANK_TRASH => ['files.trash', 'Papierkorb'],
    ];

    public function __construct(
        private readonly FileAccessService $access,
        private readonly FileQuotaService $quota
    ) {
    }

    public function key(): string
    {
        return 'files';
    }

    public function label(): string
    {
        return 'Dateiablage';
    }

    public function usage(): StorageUsageNode
    {
        /** @var array<string, array{rank: int, size: int}> $paths */
        $paths = [];
        $rows = DB::table('file_versions')
            ->join('files', 'files.id', '=', 'file_versions.file_id')
            ->select([
                'file_versions.id',
                'file_versions.storage_path',
                'file_versions.size',
                'files.current_version_id',
                'files.folder_id',
                'files.deleted_at',
            ])
            ->get();

        foreach ($rows as $row) {
            $inTrash = $row->deleted_at !== null || $this->access->isInTrash((int) $row->folder_id);
            $rank = match (true) {
                $inTrash => self::RANK_TRASH,
                (int) $row->id === (int) $row->current_version_id => self::RANK_CURRENT,
                default => self::RANK_OLD,
            };

            $path = (string) $row->storage_path;
            if (!isset($paths[$path]) || $rank < $paths[$path]['rank']) {
                $paths[$path] = ['rank' => $rank, 'size' => (int) $row->size];
            }
        }

        $totals = array_fill_keys(array_keys(self::CATEGORIES), ['bytes' => 0, 'count' => 0]);
        foreach ($paths as $entry) {
            $totals[$entry['rank']]['bytes'] += $entry['size'];
            $totals[$entry['rank']]['count']++;
        }

        $children = [];
        foreach (self::CATEGORIES as $rank => [$key, $label]) {
            $children[] = new StorageUsageNode($key, $label, $totals[$rank]['bytes'], $totals[$rank]['count']);
        }
        $children[] = $this->teamFolders();

        return StorageUsageNode::sum($this->key(), $this->label(), $children, count($paths));
    }

    private function teamFolders(): StorageUsageNode
    {
        $children = [];
        $roots = FileFolder::withTrashed()->whereNull('parent_id')->orderBy('name')->get();
        foreach ($roots as $root) {
            $label = (string) $root->name . ($root->deleted_at !== null ? ' (Papierkorb)' : '');
            $children[] = new StorageUsageNode(
                'files.team_folders.' . (int) $root->id,
                $label,
                $this->quota->usedBytesInSubtree((int) $root->id),
                quotaBytes: $root->quota_bytes === null ? null : (int) $root->quota_bytes
            );
        }

        $bytes = array_sum(array_map(static fn (StorageUsageNode $node): int => $node->bytes, $children));

        return new StorageUsageNode(
            'files.team_folders',
            'Teamordner',
            $bytes,
            count($children),
            $children,
            breakdown: true
        );
    }
}
