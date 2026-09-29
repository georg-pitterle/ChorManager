<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolderShare;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\NameFormatterService;

/**
 * Beschriftet Freigaben für die Oberfläche ("Rolle: Chorleitung") und liefert
 * die Auswahl möglicher Ziele. Gemeinsam für Ordner- und Dateifreigaben.
 */
final class FileShareDescriber
{
    public function __construct(private readonly NameFormatterService $nameFormatter)
    {
    }

    /**
     * @param iterable<object{target_type: string, reference_id: int, level: int}> $shares
     * @return list<array{type: string, reference_id: int, level: int, label: string}>
     */
    public function label(iterable $shares): array
    {
        $shares = is_array($shares) ? $shares : iterator_to_array($shares, false);
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
    public function options(): array
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
