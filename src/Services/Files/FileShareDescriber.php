<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\AudienceFilterCondition as C;
use App\Models\FileFolderShare;
use App\Models\FileShare;
use App\Models\Project;
use App\Models\Role;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\Audience\AudienceFilterService;
use App\Services\NameFormatterService;

/**
 * Beschriftet Freigaben für die Oberfläche ("Stimmgruppe: Sopran, Alt ·
 * Projekt: Frühjahrskonzert") und liefert die Auswahl möglicher Werte je
 * Kategorie. Gemeinsam für Ordner- und Dateifreigaben.
 */
final class FileShareDescriber
{
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
     * @return list<array{filter_id: int, level: int, label: string, conditions: array<string, list<int>>,
     *                    all: bool, missing: array<string, list<int>>}>
     */
    public function label(iterable $shares): array
    {
        $shares = is_array($shares) ? array_values($shares) : iterator_to_array($shares, false);
        $conditions = $this->filters->conditionsOf(
            array_map(static fn ($share): int => (int) $share->audience_filter_id, $shares)
        );

        $described = [];
        foreach ($shares as $share) {
            $set = $conditions[(int) $share->audience_filter_id] ?? [];
            $described[] = [
                'filter_id' => (int) $share->audience_filter_id,
                'level' => (int) $share->level,
                'label' => $this->summarize($set),
                'conditions' => $set,
                'all' => $set === [],
                // Gespeicherte Werte, die es nicht mehr gibt: Das Formular muss sie
                // zeigen, sonst fallen sie beim nächsten Speichern still weg.
                'missing' => $this->missingOf($set),
            ];
        }
        usort($described, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $described;
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
