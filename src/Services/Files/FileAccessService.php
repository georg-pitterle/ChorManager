<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolder;
use App\Models\FileFolderShare;
use App\Models\FileShare;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Einzige Stelle, die entscheidet, mit welcher Stufe jemand einen Ordner sieht.
 *
 * Regel: Die Stufe eines Ordners ist die höchste Freigabe, die auf ihn oder
 * einen seiner Vorfahren passt. Unterordner erben also und können nur
 * erweitern, nie einschränken. "Dateiverwaltung verwalten" ergibt überall
 * die Stufe Verwalten. Liegt der Ordner selbst oder ein Vorfahre im Papierkorb,
 * ist er für alle unerreichbar - auch für die Verwaltung; zurück kommt er nur
 * über den Papierkorb.
 *
 * Berechnet wird über den ganzen Ordnerbaum (zwei schlanke Abfragen). Für die
 * Größe eines Chor-Bestands ist das billiger und vor allem einfacher als
 * rekursive Abfragen je Ordner.
 *
 * Dateien: Ihre Stufe ist die höhere aus Ordnerstufe und eigener
 * Dateifreigabe. Eine Dateifreigabe wirkt nur, solange Datei und alle
 * Vorfahren leben.
 */
final class FileAccessService
{
    public function levelFor(FileActor $actor, FileFolder|int $folder): int
    {
        $folderId = $folder instanceof FileFolder ? (int) $folder->id : $folder;

        return $this->folderLevels($actor)[$folderId] ?? FileFolderShare::LEVEL_NONE;
    }

    public function fileLevelFor(FileActor $actor, StoredFile $file): int
    {
        if ($file->trashed() || $this->isInTrash((int) $file->folder_id)) {
            return FileFolderShare::LEVEL_NONE;
        }
        if ($actor->isFileAdmin) {
            return FileFolderShare::LEVEL_MANAGE;
        }

        return max(
            $this->levelFor($actor, (int) $file->folder_id),
            $this->directFileLevels($actor)[(int) $file->id] ?? FileFolderShare::LEVEL_NONE
        );
    }

    /**
     * Dateien, die nur über ihre eigene Freigabe erreichbar sind - der Ordner
     * bleibt dem Nutzer verschlossen ("Mit mir geteilt").
     *
     * @return Collection<int, StoredFile>
     */
    public function sharedFilesFor(FileActor $actor): Collection
    {
        $direct = $this->directFileLevels($actor);
        if ($direct === [] || $actor->isFileAdmin) {
            return new Collection();
        }

        $folderLevels = $this->folderLevels($actor);
        $tree = $this->tree();

        return StoredFile::query()
            ->whereIn('id', array_keys($direct))
            ->orderBy('name')
            ->get()
            ->filter(function (StoredFile $file) use ($folderLevels, $tree): bool {
                $folderId = (int) $file->folder_id;

                return !isset($folderLevels[$folderId]) && isset($tree[$folderId]) && !$this->isInTrash($folderId);
            })
            ->values();
    }

    /**
     * Höchste direkt passende Dateifreigabe je Datei.
     *
     * @return array<int, int>
     */
    public function directFileLevels(FileActor $actor): array
    {
        return $this->matchingLevels($actor, FileShare::query(), 'file_id');
    }

    public function can(FileActor $actor, FileFolder|int $folder, int $requiredLevel): bool
    {
        return $this->levelFor($actor, $folder) >= $requiredLevel;
    }

    /**
     * Teamordner, die der Nutzer selbst betreten darf, alphabetisch.
     *
     * @return Collection<int, FileFolder>
     */
    public function rootsFor(FileActor $actor): Collection
    {
        $levels = $this->folderLevels($actor);

        return FileFolder::query()
            ->whereNull('parent_id')
            ->whereIn('id', array_keys($levels) ?: [0])
            ->orderBy('name')
            ->get();
    }

    /**
     * Tiefer liegende Ordner mit eigener Freigabe, deren Teamordner der Nutzer
     * nicht betreten darf - sonst fände er sie nie ("Mit mir geteilt").
     *
     * @return Collection<int, FileFolder>
     */
    public function sharedEntryPointsFor(FileActor $actor): Collection
    {
        $levels = $this->folderLevels($actor);
        if ($levels === []) {
            return new Collection();
        }

        $tree = $this->tree();
        $entryIds = [];
        foreach (array_keys($levels) as $folderId) {
            $parentId = $tree[$folderId]['parent_id'] ?? null;
            if ($parentId !== null && !isset($levels[$parentId])) {
                $entryIds[] = $folderId;
            }
        }

        return FileFolder::query()->whereIn('id', $entryIds ?: [0])->orderBy('name')->get();
    }

    /**
     * Alle erreichbaren Ordner mit ihrer Stufe.
     *
     * @return array<int, int> folder_id => Stufe (nur Stufe >= Lesen)
     */
    public function folderLevels(FileActor $actor): array
    {
        $tree = $this->tree();
        $ownLevels = $actor->isFileAdmin ? [] : $this->matchingShareLevels($actor);

        $children = [];
        foreach ($tree as $id => $node) {
            $children[$node['parent_id'] ?? 0][] = $id;
        }

        $result = [];
        $stack = [];
        foreach ($children[0] ?? [] as $rootId) {
            $stack[] = [$rootId, FileFolderShare::LEVEL_NONE];
        }

        while ($stack !== []) {
            [$id, $inherited] = array_pop($stack);
            if ($tree[$id]['deleted']) {
                continue;
            }

            $level = $actor->isFileAdmin
                ? FileFolderShare::LEVEL_MANAGE
                : max($inherited, $ownLevels[$id] ?? FileFolderShare::LEVEL_NONE);
            if ($level > FileFolderShare::LEVEL_NONE) {
                $result[$id] = $level;
            }

            foreach ($children[$id] ?? [] as $childId) {
                $stack[] = [$childId, $level];
            }
        }

        return $result;
    }

    /**
     * Ob ein Ordner oder einer seiner Vorfahren im Papierkorb liegt.
     */
    public function isInTrash(FileFolder|int $folder): bool
    {
        $tree = $this->tree();
        $id = $folder instanceof FileFolder ? (int) $folder->id : $folder;
        $guard = 0;
        while ($id !== null && isset($tree[$id]) && $guard++ < 1000) {
            if ($tree[$id]['deleted']) {
                return true;
            }
            $id = $tree[$id]['parent_id'];
        }

        return false;
    }

    /**
     * Vorfahren von der Wurzel bis zum Ordner selbst (für Brotkrumen und Kontingent).
     *
     * @return list<int>
     */
    public function ancestry(FileFolder|int $folder): array
    {
        $tree = $this->tree();
        $id = $folder instanceof FileFolder ? (int) $folder->id : $folder;
        $chain = [];
        $guard = 0;
        while ($id !== null && isset($tree[$id]) && $guard++ < 1000) {
            array_unshift($chain, $id);
            $id = $tree[$id]['parent_id'];
        }

        return $chain;
    }

    /**
     * Der Ordner und alle Nachfahren, auch die im Papierkorb.
     *
     * @return list<int>
     */
    public function subtreeIds(int $folderId): array
    {
        $tree = $this->tree();
        if (!isset($tree[$folderId])) {
            return [];
        }

        $children = [];
        foreach ($tree as $id => $node) {
            if ($node['parent_id'] !== null) {
                $children[$node['parent_id']][] = $id;
            }
        }

        $result = [];
        $stack = [$folderId];
        while ($stack !== []) {
            $id = array_pop($stack);
            $result[] = $id;
            foreach ($children[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return $result;
    }

    /**
     * Pfade mehrerer Ordner auf einmal, jeweils von der Wurzel bis zum Ordner.
     *
     * @param list<int> $folderIds
     * @return array<int, list<array{id: int, name: string}>>
     */
    public function pathsFor(array $folderIds): array
    {
        $tree = $this->tree();
        $paths = [];
        foreach ($folderIds as $folderId) {
            $chain = [];
            $id = $folderId;
            $guard = 0;
            while ($id !== null && isset($tree[$id]) && $guard++ < 1000) {
                array_unshift($chain, ['id' => $id, 'name' => $tree[$id]['name']]);
                $id = $tree[$id]['parent_id'];
            }
            $paths[$folderId] = $chain;
        }

        return $paths;
    }

    public function rootIdOf(FileFolder|int $folder): int
    {
        $chain = $this->ancestry($folder);

        return $chain[0] ?? ($folder instanceof FileFolder ? (int) $folder->id : $folder);
    }

    /**
     * @return array<int, array{parent_id: ?int, deleted: bool, name: string}>
     */
    private function tree(): array
    {
        $tree = [];
        $rows = FileFolder::withTrashed()->toBase()->get(['id', 'parent_id', 'deleted_at', 'name']);
        foreach ($rows as $row) {
            $tree[(int) $row->id] = [
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'deleted' => $row->deleted_at !== null,
                'name' => (string) $row->name,
            ];
        }

        return $tree;
    }

    /**
     * Höchste direkt passende Freigabe je Ordner.
     *
     * @return array<int, int>
     */
    private function matchingShareLevels(FileActor $actor): array
    {
        return $this->matchingLevels($actor, FileFolderShare::query(), 'folder_id');
    }

    /**
     * Gemeinsamer Abgleich für Ordner- und Dateifreigaben: dieselben Zieltypen,
     * dieselbe Auflösung über Rollen, Stimmgruppen und Projekte.
     *
     * @param Builder<FileFolderShare>|Builder<FileShare> $query
     * @return array<int, int> Kennung => höchste Stufe
     */
    private function matchingLevels(FileActor $actor, Builder $query, string $keyColumn): array
    {
        $user = User::find($actor->userId);
        if ($user === null) {
            return [];
        }

        $roleIds = $this->ids($user->roles()->pluck('role_id'));
        $voiceGroupIds = $this->ids($user->voiceGroups()->pluck('voice_group_id'));
        $projectIds = $this->ids($user->projects()->pluck('project_id'));

        $rows = $query
            ->where(function ($query) use ($actor, $roleIds, $voiceGroupIds, $projectIds) {
                $query->where('target_type', FileFolderShare::TYPE_ALL_MEMBERS)
                    ->orWhere(function ($q) use ($actor) {
                        $q->where('target_type', FileFolderShare::TYPE_USER)
                            ->where('reference_id', $actor->userId);
                    })
                    ->orWhere(function ($q) use ($roleIds) {
                        $q->where('target_type', FileFolderShare::TYPE_ROLE)
                            ->whereIn('reference_id', $roleIds ?: [0]);
                    })
                    ->orWhere(function ($q) use ($voiceGroupIds) {
                        $q->where('target_type', FileFolderShare::TYPE_VOICE_GROUP)
                            ->whereIn('reference_id', $voiceGroupIds ?: [0]);
                    })
                    ->orWhere(function ($q) use ($projectIds) {
                        $q->where('target_type', FileFolderShare::TYPE_PROJECT_MEMBERS)
                            ->whereIn('reference_id', $projectIds ?: [0]);
                    });
            })
            ->groupBy($keyColumn)
            ->selectRaw($keyColumn . ' AS target_id, MAX(level) AS level')
            ->toBase()
            ->get();

        $levels = [];
        foreach ($rows as $row) {
            $levels[(int) $row->target_id] = (int) $row->level;
        }

        return $levels;
    }

    /**
     * @param iterable<mixed> $values
     * @return list<int>
     */
    private function ids(iterable $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }
}
