<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\VoiceGroupList;
use App\Models\VoiceGroupListEntry;

/**
 * Welche Mitglieder in einer Stimmgruppen-Liste stehen, und wie weit jede
 * Häkchen-Spalte ist.
 *
 * Zeilen sind die aktiven Mitglieder der Stimmgruppe, gleich welche Teilstimme;
 * hat die Liste ein Projekt, nur die Projektmitglieder darunter. Gespeichert
 * wird das nicht: Ein neues Mitglied steht ohne Zutun in der Liste. Wer
 * herausfällt - archiviert, aus Stimmgruppe oder Projekt entfernt -, behält
 * seine Einträge in der Datenbank; sie werden nur weder gezeigt noch gezählt.
 */
class VoiceGroupListRows
{
    /**
     * @return list<array{user: User, sub_voice_name: ?string}>
     */
    public function members(VoiceGroupList $list): array
    {
        $query = User::query()
            ->select('users.*', 'sub_voices.name as sub_voice_name')
            ->join('user_voice_groups', 'user_voice_groups.user_id', '=', 'users.id')
            ->leftJoin('sub_voices', 'sub_voices.id', '=', 'user_voice_groups.sub_voice_id')
            ->where('user_voice_groups.voice_group_id', (int) $list->voice_group_id)
            ->where('users.is_active', 1);

        if ($list->project_id !== null) {
            $projectId = (int) $list->project_id;
            $query->whereExists(static function ($sub) use ($projectId): void {
                $sub->selectRaw('1')
                    ->from('project_users')
                    ->whereColumn('project_users.user_id', 'users.id')
                    ->where('project_users.project_id', $projectId);
            });
        }

        // Ohne Teilstimme zuletzt, darin nach Name - wie eine Probenaufstellung.
        $users = $query
            ->orderByRaw('sub_voices.name IS NULL')
            ->orderBy('sub_voices.name')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->orderBy('users.id')
            ->get();

        $rows = [];
        foreach ($users as $user) {
            $subVoiceName = $user->getAttribute('sub_voice_name');
            $rows[] = ['user' => $user, 'sub_voice_name' => $subVoiceName === null ? null : (string) $subVoiceName];
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    public function memberIds(VoiceGroupList $list): array
    {
        return array_map(static fn(array $row): int => (int) $row['user']->id, $this->members($list));
    }

    public function isRow(VoiceGroupList $list, int $userId): bool
    {
        return in_array($userId, $this->memberIds($list), true);
    }

    /**
     * Einträge der aktuellen Zeilen, nach Spalte und Mitglied.
     *
     * @param list<int>|null $memberIds schon berechnete Zeilen, sonst neu ermittelt
     * @return array<int, array<int, VoiceGroupListEntry>>
     */
    public function entriesByColumnAndUser(VoiceGroupList $list, ?array $memberIds = null): array
    {
        $memberIds ??= $this->memberIds($list);
        if ($memberIds === []) {
            return [];
        }

        $entries = VoiceGroupListEntry::query()
            ->with('updatedBy')
            ->whereIn('column_id', $list->columns()->pluck('id'))
            ->whereIn('user_id', $memberIds)
            ->get();

        $byColumn = [];
        foreach ($entries as $entry) {
            $byColumn[(int) $entry->column_id][(int) $entry->user_id] = $entry;
        }

        return $byColumn;
    }

    /**
     * Fortschritt je Häkchen-Spalte, gezählt über die aktuellen Zeilen.
     *
     * @param list<int>|null $memberIds schon berechnete Zeilen, sonst neu ermittelt
     * @return array<int, array{done: int, total: int}>
     */
    public function progress(VoiceGroupList $list, ?array $memberIds = null): array
    {
        $memberIds ??= $this->memberIds($list);
        $progress = [];

        foreach ($list->columns()->get() as $column) {
            if (!$column->isCheckbox()) {
                continue;
            }

            $done = $memberIds === [] ? 0 : $column->entries()
                ->whereIn('user_id', $memberIds)
                ->whereNotNull('checked_at')
                ->count();
            $progress[(int) $column->id] = ['done' => $done, 'total' => count($memberIds)];
        }

        return $progress;
    }
}
