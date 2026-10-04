<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\AudienceFilterCondition as C;
use App\Models\FileFolderShare;
use App\Models\FileShare;
use App\Models\Project;
use App\Models\Role;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\NameFormatterService;

/**
 * Beschriftet Zielgruppen für die Oberfläche ("Stimmgruppe: Sopran, Alt ·
 * Projekt: Frühjahrskonzert") und liefert die Auswahl möglicher Werte je
 * Kategorie. Gemeinsam für Freigaben, Termine, Newsletter und Vorlagen.
 */
final class AudienceDescriber
{
    /** @var array<string, array<int, string>> gefundene Namen je Kategorie und Kennungsmenge */
    private array $nameCache = [];

    private const CATEGORY_LABELS = [
        C::CATEGORY_ROLE => 'Rolle',
        C::CATEGORY_VOICE_GROUP => 'Stimmgruppe',
        C::CATEGORY_SUB_VOICE => 'Untergruppe',
        C::CATEGORY_PROJECT => 'Projekt',
        C::CATEGORY_USER => 'Mitglied',
    ];

    public function __construct(
        private readonly NameFormatterService $nameFormatter,
        private readonly AudienceFilterService $filters = new AudienceFilterService()
    ) {
    }

    /**
     * @param iterable<FileFolderShare|FileShare> $shares
     * @return list<array{level: int, label: string, conditions: array<string, list<int>>,
     *                    all: bool, missing: array<string, list<int>>}>
     */
    public function label(iterable $shares): array
    {
        $shares = is_array($shares) ? array_values($shares) : iterator_to_array($shares, false);
        $folderShareIds = [];
        $fileShareIds = [];
        foreach ($shares as $share) {
            if ($share instanceof FileFolderShare) {
                $folderShareIds[] = (int) $share->id;
            } else {
                $fileShareIds[] = (int) $share->id;
            }
        }
        $folderSets = $this->filters->conditionSetsForOwners('file_folder_share_id', $folderShareIds);
        $fileSets = $this->filters->conditionSetsForOwners('file_share_id', $fileShareIds);

        $described = [];
        foreach ($shares as $share) {
            // Eine Freigabe besitzt genau einen Filter.
            $sets = $share instanceof FileFolderShare ? $folderSets : $fileSets;
            $described[] = ['level' => (int) $share->level] + $this->describeSets([$sets[(int) $share->id][0] ?? []])[0];
        }
        usort($described, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $described;
    }

    /**
     * Zeilen für das Formular: Zusammenfassung, gewählte Werte und gespeicherte
     * Werte, die es nicht mehr gibt - die muss das Formular zeigen, sonst fallen
     * sie beim nächsten Speichern still weg.
     *
     * @param list<array<string, list<int>>> $sets
     * @return list<array{label: string, conditions: array<string, list<int>>, all: bool,
     *                    missing: array<string, list<int>>}>
     */
    public function describeSets(array $sets): array
    {
        return array_map(fn (array $set): array => [
            'label' => $this->summarize($set),
            'conditions' => $set,
            'all' => $set === [],
            'missing' => $this->missingOf($set),
        ], array_values($sets));
    }

    /**
     * Eine Zeile je Filter, getrennt mit " / ".
     *
     * @param list<array<string, list<int>>> $sets
     */
    public function summarizeSets(array $sets): string
    {
        if ($sets === []) {
            return 'Keine Zielgruppe';
        }

        return implode(' / ', array_map(fn (array $set): string => $this->summarize($set), $sets));
    }

    /**
     * Alle Kennungen einer Kategorie über mehrere Bedingungsmengen hinweg -
     * etwa, damit gewählte beendete Projekte in der Auswahl bleiben.
     *
     * @param list<array<string, list<int>>> $sets
     * @return list<int>
     */
    public static function selectedIds(array $sets, string $category): array
    {
        $ids = [];
        foreach ($sets as $set) {
            foreach ($set[$category] ?? [] as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, list<int>> $conditions
     */
    public function summarize(array $conditions): string
    {
        if ($conditions === []) {
            return 'Alle Mitglieder';
        }

        $parts = [];
        foreach ($conditions as $category => $ids) {
            $parts[] = self::CATEGORY_LABELS[$category] . ': ' . implode(', ', $this->namesFor($category, $ids));
        }

        return implode(' · ', $parts);
    }

    /**
     * Auswahllisten je Kategorie. Projekte: laufende und künftige, dazu bereits
     * gewählte beendete (markiert), damit bestehende Freigaben sichtbar bleiben.
     *
     * Mitglieder: aktive, dazu bereits gewählte inaktive (markiert).
     *
     * @param list<int> $selectedProjectIds
     * @param list<int> $selectedUserIds
     * @return array<string, mixed>
     */
    public function options(array $selectedProjectIds = [], array $selectedUserIds = []): array
    {
        $usersQuery = User::query()->where(static function ($query) use ($selectedUserIds): void {
            $query->where('is_active', 1)->orWhereIn('id', $selectedUserIds ?: [0]);
        });
        $this->nameFormatter->applyNameOrder($usersQuery);
        $today = date('Y-m-d');

        $projects = Project::query()->chronological()->get(['id', 'name', 'end_date'])
            ->filter(static fn (Project $p): bool => $p->end_date === null
                || $p->end_date->format('Y-m-d') >= $today
                || in_array((int) $p->id, $selectedProjectIds, true))
            ->map(static fn (Project $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'ended' => $p->end_date !== null && $p->end_date->format('Y-m-d') < $today,
            ])
            ->values()
            ->all();

        $groups = VoiceGroup::query()->orderBy('id')->get(['id', 'name']);
        $subVoices = SubVoice::query()->orderBy('name')->get(['id', 'name', 'voice_group_id'])->groupBy('voice_group_id');

        return [
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'voice_groups' => $groups,
            'sub_voices' => $groups
                ->map(static fn (VoiceGroup $g): array => [
                    'group' => (string) $g->name,
                    'items' => ($subVoices->get($g->id) ?? collect())->values()->all(),
                ])
                ->filter(static fn (array $entry): bool => $entry['items'] !== [])
                ->values()
                ->all(),
            'projects' => $projects,
            'users' => $usersQuery->get(),
        ];
    }

    /**
     * @param array<string, list<int>> $conditions
     * @return array<string, list<int>>
     */
    private function missingOf(array $conditions): array
    {
        $missing = [];
        foreach ($conditions as $category => $ids) {
            $gone = array_values(array_diff($ids, array_map('intval', array_keys($this->found($category, $ids)))));
            if ($gone !== []) {
                $missing[$category] = $gone;
            }
        }

        return $missing;
    }

    /**
     * @param list<int> $ids
     * @return list<string>
     */
    private function namesFor(string $category, array $ids): array
    {
        $found = $this->found($category, $ids);

        $names = array_values(array_map('strval', $found));
        $missing = count(array_diff($ids, array_map('intval', array_keys($found))));
        if ($missing > 0) {
            $names[] = $missing === 1 ? 'gelöscht' : $missing . '× gelöscht';
        }

        return $names;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function found(string $category, array $ids): array
    {
        // Je Instanz nur einmal nachschlagen: Ein Kalender-Feed beschriftet eine
        // Serie mit lauter gleichen Zielgruppen.
        $sorted = $ids;
        sort($sorted);
        $key = $category . ':' . implode(',', $sorted);

        return $this->nameCache[$key] ??= $this->lookup($category, $ids);
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function lookup(string $category, array $ids): array
    {
        return match ($category) {
            C::CATEGORY_ROLE => Role::query()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all(),
            C::CATEGORY_VOICE_GROUP => VoiceGroup::query()->whereIn('id', $ids)->orderBy('id')
                ->pluck('name', 'id')->all(),
            C::CATEGORY_SUB_VOICE => SubVoice::query()->whereIn('id', $ids)->orderBy('voice_group_id')->orderBy('name')
                ->pluck('name', 'id')->all(),
            C::CATEGORY_PROJECT => Project::query()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all(),
            C::CATEGORY_USER => User::query()->whereIn('id', $ids)->get()
                ->mapWithKeys(fn (User $u): array => [(int) $u->id => $this->nameFormatter->formatPerson($u)])
                ->all(),
            default => [],
        };
    }
}
