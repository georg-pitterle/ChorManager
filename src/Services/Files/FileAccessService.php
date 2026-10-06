<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolder;
use App\Models\FileFolderShare;
use App\Models\FileShare;
use App\Models\StoredFile;
use App\Services\Audience\AudienceFilterService;
use App\Services\Audience\MemberProfile;
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
 *
 * Zwischenspeicher
 * ----------------
 * Ordnerbaum, Ordnerstufen, Dateifreigaben und das Mitgliedsprofil werden je
 * Instanz behalten. Der Dienst lebt im Container genau eine Anfrage lang, der
 * Speicher also auch - er braucht deshalb keine Ablaufzeit, aber ein
 * ausdrückliches Verwerfen.
 *
 * Gemessen an einem Bestand mit drei Teamordnern, sechs Unterordnern und
 * achtzehn Dateien kostete der Dienst ohne diesen Speicher 33 Abfragen für die
 * Seite /files und 14 für ein einziges `fileLevelFor()`: `tree()` lief dreimal
 * je Dateistufe, und sowohl die Ordner- als auch die Dateifreigaben schlugen
 * dasselbe Profil erneut nach.
 *
 * **Wer Ordner oder Freigaben schreibt, verwirft den Speicher.** Das betrifft
 * FileFolderService (anlegen, umbenennen, verschieben, in den Papierkorb,
 * Freigaben setzen), FileTrashService (zurückholen, endgültig löschen) und
 * FileShareService (Dateifreigaben setzen) - und zwar auch dann, wenn der
 * Schreibvorgang scheitert: Innerhalb einer Transaktion liest der Dienst den
 * noch nicht festgeschriebenen Stand, und nach einem Rollback wäre genau der im
 * Speicher. Dateien selbst stehen nicht darin; sie anzulegen, zu verschieben
 * oder in den Papierkorb zu legen verlangt kein Verwerfen.
 *
 * Verworfen wird prozessweit, über einen Generationszähler, und nicht nur auf
 * der Instanz, die gerade zur Hand ist. Der Speicher spiegelt den Zustand der
 * Datenbank, und der gilt für jede Instanz gleich - der Container hält zwar nur
 * eine, aber nicht jeder Schreibvorgang läuft über die Dienste hier: Fixtures
 * der Tests, Kommandos in `bin/` und jeder künftige Aufrufer schreiben direkt am
 * Modell. Die hätten eine Instanz gar nicht zur Hand, und ein Speicher, dessen
 * Richtigkeit daran hängt, dass alle ihn kennen, wird irgendwann falsch. Wer
 * Ordner oder Freigaben ohne Instanz schreibt, ruft `FileAccessService::invalidate()`.
 */
final class FileAccessService
{
    /** @var array<int, array{parent_id: ?int, deleted: bool, name: string}>|null */
    private ?array $treeCache = null;

    /** @var array<string, array<int, int>> */
    private array $folderLevelCache = [];

    /** @var array<string, array<int, int>> */
    private array $directFileLevelCache = [];

    /** @var array<int, MemberProfile|null> */
    private array $profileCache = [];

    /**
     * Generation der Daten in der Datenbank. Jedes Verwerfen zählt sie hoch;
     * eine Instanz, die an einer älteren hängt, baut ihren Speicher neu auf.
     */
    private static int $generation = 0;

    private int $cachedGeneration = 0;

    public function __construct(private readonly AudienceFilterService $filters = new AudienceFilterService())
    {
    }

    /**
     * Verwirft den Zwischenspeicher aller Instanzen dieses Prozesses. Aufzurufen
     * von jedem, der Ordner oder Freigaben geschrieben hat - siehe Klassenkommentar.
     */
    public static function invalidate(): void
    {
        self::$generation++;
    }

    /**
     * Dasselbe, nur als Instanzaufruf: Die Dienste haben eine zur Hand, und
     * `$this->access->forget()` liest sich an der Schreibstelle besser als ein
     * statischer Aufruf.
     */
    public function forget(): void
    {
        self::invalidate();
        $this->dropCaches();
    }

    /**
     * Hängt der Speicher an einer älteren Generation, wird er fallen gelassen.
     * Steht am Anfang jedes Lesewegs, der etwas behält.
     */
    private function syncGeneration(): void
    {
        if ($this->cachedGeneration === self::$generation) {
            return;
        }

        $this->dropCaches();
        $this->cachedGeneration = self::$generation;
    }

    private function dropCaches(): void
    {
        $this->treeCache = null;
        $this->folderLevelCache = [];
        $this->directFileLevelCache = [];
        $this->profileCache = [];
    }

    /**
     * Schlüssel des Zwischenspeichers. Das Datei-Admin-Recht gehört hinein: Es
     * entscheidet über jede Stufe, und derselbe Mensch kann in einer Anfrage als
     * Admin und als einfaches Mitglied gefragt werden (AudiencePreviewController).
     */
    private static function cacheKey(FileActor $actor): string
    {
        return $actor->userId . '|' . ($actor->isFileAdmin ? '1' : '0');
    }

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
        $this->syncGeneration();

        return $this->directFileLevelCache[self::cacheKey($actor)] ??= $this->matchingLevels(
            $actor,
            FileShare::query(),
            'file_id',
            'file_share_id'
        );
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
        $this->syncGeneration();

        return $this->folderLevelCache[self::cacheKey($actor)] ??= $this->computeFolderLevels($actor);
    }

    /**
     * @return array<int, int> folder_id => Stufe (nur Stufe >= Lesen)
     */
    private function computeFolderLevels(FileActor $actor): array
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
        $this->syncGeneration();

        if ($this->treeCache !== null) {
            return $this->treeCache;
        }

        $tree = [];
        $rows = FileFolder::withTrashed()->toBase()->get(['id', 'parent_id', 'deleted_at', 'name']);
        foreach ($rows as $row) {
            $tree[(int) $row->id] = [
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'deleted' => $row->deleted_at !== null,
                'name' => (string) $row->name,
            ];
        }

        return $this->treeCache = $tree;
    }

    /**
     * Das Profil des Mitglieds, einmal je Instanz. Es kostet vier Abfragen, und
     * Ordner- wie Dateifreigaben brauchen dasselbe.
     */
    private function profileOf(int $userId): ?MemberProfile
    {
        $this->syncGeneration();

        if (!array_key_exists($userId, $this->profileCache)) {
            $this->profileCache[$userId] = $this->filters->profileOf($userId);
        }

        return $this->profileCache[$userId];
    }

    /**
     * Höchste direkt passende Freigabe je Ordner.
     *
     * @return array<int, int>
     */
    private function matchingShareLevels(FileActor $actor): array
    {
        return $this->matchingLevels($actor, FileFolderShare::query(), 'folder_id', 'file_folder_share_id');
    }

    /**
     * Gemeinsamer Abgleich für Ordner- und Dateifreigaben über ihre
     * Zielgruppen-Filter. Ergebnis: höchste passende Stufe je Ordner bzw. Datei.
     *
     * @param Builder<FileFolderShare>|Builder<FileShare> $query
     * @return array<int, int> Kennung => höchste Stufe
     */
    private function matchingLevels(FileActor $actor, Builder $query, string $keyColumn, string $ownerColumn): array
    {
        $profile = $this->profileOf($actor->userId);
        if ($profile === null) {
            return [];
        }

        $rows = $query->toBase()->get(['id', $keyColumn . ' AS target_id', 'level']);
        $sets = $this->filters->conditionSetsForOwners(
            $ownerColumn,
            $rows->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );

        $levels = [];
        foreach ($rows as $row) {
            if (!$this->filters->fitsAny($profile, $sets[(int) $row->id] ?? [])) {
                continue;
            }
            $target = (int) $row->target_id;
            $levels[$target] = max($levels[$target] ?? 0, (int) $row->level);
        }

        return $levels;
    }
}
