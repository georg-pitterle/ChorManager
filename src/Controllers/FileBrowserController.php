<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FileControllerSupport;
use App\Models\FileFolder;
use App\Models\FileFolderShare;
use App\Models\Project;
use App\Models\Role;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use App\Services\Files\FileFavoriteService;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileSearchService;
use App\Services\Files\FileService;
use App\Services\NameFormatterService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Lesende Seiten der Dateiverwaltung: Übersicht, Ordner, Suche.
 */
final class FileBrowserController
{
    use FileControllerSupport;

    public function __construct(
        private readonly Twig $view,
        private readonly FileAccessService $access,
        private readonly FileFavoriteService $favorites,
        private readonly FileQuotaService $quota,
        private readonly FileSearchService $search,
        private readonly FileService $files,
        private readonly NameFormatterService $nameFormatter
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $actor = $this->actor();

        $roots = $this->access->rootsFor($actor)->map(fn (FileFolder $root): array => [
            'folder' => $root,
            'used_bytes' => $this->quota->usedBytesInSubtree((int) $root->id),
            'quota_bytes' => $root->quota_bytes,
        ])->all();

        $shared = $this->access->sharedEntryPointsFor($actor);
        $sharedPaths = $this->access->pathsFor($shared->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $this->view->render($response, 'files/index.twig', [
            'roots' => $roots,
            'shared' => $shared->map(fn (FileFolder $folder): array => [
                'folder' => $folder,
                'path' => $sharedPaths[(int) $folder->id] ?? [],
            ])->all(),
            'favorites' => $this->favorites->listFor($actor),
            'is_file_admin' => $actor->isFileAdmin,
            'total_used_bytes' => $actor->isFileAdmin ? $this->quota->totalUsedBytes() : null,
            'total_quota_bytes' => $this->quota->totalQuotaBytes(),
        ]);
    }

    public function folder(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor();
        $folder = FileFolder::find((int) $args['id']);
        $levels = $this->access->folderLevels($actor);
        $level = $folder !== null ? ($levels[(int) $folder->id] ?? 0) : 0;
        if ($folder === null || $level === FileFolderShare::LEVEL_NONE) {
            $_SESSION['error'] = 'Der Ordner existiert nicht oder ist nicht freigegeben.';

            return $this->redirect($response, '/files');
        }

        $path = $this->access->pathsFor([(int) $folder->id])[(int) $folder->id] ?? [];
        $breadcrumb = array_map(static fn (array $node): array => $node + [
            'linkable' => isset($levels[$node['id']]),
        ], $path);

        $rootId = $path[0]['id'] ?? (int) $folder->id;
        $root = $rootId === (int) $folder->id ? $folder : FileFolder::find($rootId);

        $subfolders = FileFolder::query()->where('parent_id', $folder->id)->orderBy('name')->get();
        $files = StoredFile::query()
            ->with(['updater'])
            ->where('folder_id', $folder->id)
            ->orderBy('name')
            ->get();

        $canManage = $level >= FileFolderShare::LEVEL_MANAGE;

        return $this->view->render($response, 'files/folder.twig', [
            'folder' => $folder,
            'level' => $level,
            'level_labels' => FileFolderShare::LEVEL_LABELS,
            'breadcrumb' => $breadcrumb,
            'subfolders' => $subfolders,
            'files' => $files,
            'favorite_file_ids' => $this->favorites->favoriteIds($actor, FileFavoriteService::TYPE_FILE),
            'favorite_folder_ids' => $this->favorites->favoriteIds($actor, FileFavoriteService::TYPE_FOLDER),
            'is_file_admin' => $actor->isFileAdmin,
            'root' => $root,
            'root_used_bytes' => $this->quota->usedBytesInSubtree($rootId),
            'max_upload_bytes' => $this->files->maxUploadBytes(),
            'move_targets' => $level >= FileFolderShare::LEVEL_EDIT ? $this->moveTargets($actor, $levels) : [],
            'shares' => $canManage ? $this->describeShares($folder) : [],
            'inherited_shares' => $canManage ? $this->inheritedShares($path) : [],
            'share_options' => $canManage ? $this->shareOptions() : null,
        ]);
    }

    public function search(Request $request, Response $response): Response
    {
        $actor = $this->actor();
        $term = trim((string) ($request->getQueryParams()['q'] ?? ''));

        return $this->view->render($response, 'files/search.twig', [
            'term' => $term,
            'min_length' => FileSearchService::MIN_LENGTH,
            'results' => $this->search->search($actor, $term),
            'favorite_file_ids' => $this->favorites->favoriteIds($actor, FileFavoriteService::TYPE_FILE),
        ]);
    }

    /**
     * Ordner, in die verschoben werden darf, mit lesbarem Pfad.
     *
     * @param array<int, int> $levels
     * @return list<array{id: int, label: string}>
     */
    private function moveTargets(FileActor $actor, array $levels): array
    {
        $ids = array_keys(array_filter($levels, static fn (int $l): bool => $l >= FileFolderShare::LEVEL_EDIT));
        $targets = [];
        foreach ($this->access->pathsFor($ids) as $id => $chain) {
            $targets[] = [
                'id' => $id,
                'label' => implode(' / ', array_column($chain, 'name')),
            ];
        }
        usort($targets, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $targets;
    }

    /**
     * @return list<array{type: string, reference_id: int, level: int, label: string}>
     */
    private function describeShares(FileFolder $folder): array
    {
        return $this->labelShares(
            FileFolderShare::query()->where('folder_id', $folder->id)->get()->all()
        );
    }

    /**
     * Freigaben der Vorfahren - zur Anzeige, woher geerbte Rechte kommen.
     *
     * @param list<array{id: int, name: string}> $path
     * @return list<array{folder: string, shares: list<array{type: string, reference_id: int, level: int, label: string}>}>
     */
    private function inheritedShares(array $path): array
    {
        $ancestors = array_slice($path, 0, -1);
        if ($ancestors === []) {
            return [];
        }

        $byFolder = FileFolderShare::query()
            ->whereIn('folder_id', array_column($ancestors, 'id'))
            ->get()
            ->groupBy('folder_id');

        $result = [];
        foreach ($ancestors as $ancestor) {
            $shares = $byFolder->get($ancestor['id']);
            if ($shares !== null && $shares->isNotEmpty()) {
                $result[] = ['folder' => $ancestor['name'], 'shares' => $this->labelShares($shares->all())];
            }
        }

        return $result;
    }

    /**
     * @param list<FileFolderShare> $shares
     * @return list<array{type: string, reference_id: int, level: int, label: string}>
     */
    private function labelShares(array $shares): array
    {
        $ids = [];
        foreach ($shares as $share) {
            $ids[$share->target_type][] = (int) $share->reference_id;
        }

        $names = [
            FileFolderShare::TYPE_ROLE => Role::query()->whereIn('id', $ids['role'] ?? [0])->pluck('name', 'id')->all(),
            FileFolderShare::TYPE_VOICE_GROUP => VoiceGroup::query()->whereIn('id', $ids['voice_group'] ?? [0])
                ->pluck('name', 'id')->all(),
            FileFolderShare::TYPE_PROJECT_MEMBERS => Project::query()->whereIn('id', $ids['project_members'] ?? [0])
                ->pluck('name', 'id')->all(),
            FileFolderShare::TYPE_USER => User::query()->whereIn('id', $ids['user'] ?? [0])->get()
                ->mapWithKeys(fn (User $u): array => [(int) $u->id => $this->nameFormatter->formatPerson($u)])
                ->all(),
        ];

        $prefix = [
            FileFolderShare::TYPE_ROLE => 'Rolle',
            FileFolderShare::TYPE_VOICE_GROUP => 'Stimmgruppe',
            FileFolderShare::TYPE_PROJECT_MEMBERS => 'Projekt',
            FileFolderShare::TYPE_USER => 'Mitglied',
        ];

        $described = [];
        foreach ($shares as $share) {
            $type = (string) $share->target_type;
            $label = $type === FileFolderShare::TYPE_ALL_MEMBERS
                ? 'Alle Mitglieder'
                : $prefix[$type] . ': ' . ($names[$type][(int) $share->reference_id] ?? 'gelöscht');
            $described[] = [
                'type' => $type,
                'reference_id' => (int) $share->reference_id,
                'level' => (int) $share->level,
                'label' => $label,
            ];
        }
        usort($described, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $described;
    }

    /**
     * @return array<string, mixed>
     */
    private function shareOptions(): array
    {
        $usersQuery = User::query()->where('is_active', 1);
        $this->nameFormatter->applyNameOrder($usersQuery);

        return [
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'voice_groups' => VoiceGroup::query()->orderBy('id')->get(['id', 'name']),
            'projects' => Project::query()->chronological()->get(['id', 'name']),
            'users' => $usersQuery->get(),
        ];
    }
}
