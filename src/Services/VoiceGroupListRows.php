<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\VoiceGroupList;
use App\Models\VoiceGroupListColumn;
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
     * Eine Abfrage für alle Spalten, nicht eine je Spalte: Die Ansicht zeigt sie
     * ohnehin alle zusammen, und bei fünf Spalten waren das fünf Abfragen für
     * einen Seitenaufbau.
     *
     * @param list<int>|null $memberIds schon berechnete Zeilen, sonst neu ermittelt
     * @return array<int, array{done: int, total: int}>
     */
    public function progress(VoiceGroupList $list, ?array $memberIds = null): array
    {
        $memberIds ??= $this->memberIds($list);

        $columnIds = [];
        foreach ($list->columns()->get() as $column) {
            if ($column->isCheckbox()) {
                $columnIds[] = (int) $column->id;
            }
        }
        if ($columnIds === []) {
            return [];
        }

        $done = $this->checkedCounts($columnIds, $memberIds);

        $progress = [];
        foreach ($columnIds as $columnId) {
            $progress[$columnId] = ['done' => $done[$columnId] ?? 0, 'total' => count($memberIds)];
        }

        return $progress;
    }

    /**
     * Fortschritt einer einzelnen Spalte, null für eine Spalte ohne Häkchen.
     *
     * Für die Stellen, die genau eine Spalte brauchen - sie ließen zuvor den Stand
     * aller Spalten berechnen und warfen alles bis auf einen Eintrag weg.
     *
     * @param list<int>|null $memberIds schon berechnete Zeilen, sonst neu ermittelt
     * @return array{done: int, total: int}|null
     */
    public function progressFor(
        VoiceGroupList $list,
        VoiceGroupListColumn $column,
        ?array $memberIds = null
    ): ?array {
        if (!$column->isCheckbox()) {
            return null;
        }

        $memberIds ??= $this->memberIds($list);
        $columnId = (int) $column->id;

        return [
            'done' => $this->checkedCounts([$columnId], $memberIds)[$columnId] ?? 0,
            'total' => count($memberIds),
        ];
    }

    /**
     * Gesetzte Häkchen je Spalte, gezählt über die angegebenen Zeilen.
     *
     * @param list<int> $columnIds
     * @param list<int> $memberIds
     * @return array<int, int>
     */
    private function checkedCounts(array $columnIds, array $memberIds): array
    {
        if ($columnIds === [] || $memberIds === []) {
            return [];
        }

        $query = VoiceGroupListEntry::query()
            ->whereIn('column_id', $columnIds)
            ->whereIn('user_id', $memberIds)
            ->whereNotNull('checked_at');

        // Eine Spalte braucht kein Gruppieren; die Ansicht mit allen Spalten schon.
        if (count($columnIds) === 1) {
            return [$columnIds[0] => $query->count()];
        }

        $counts = [];
        foreach ($query->groupBy('column_id')->selectRaw('column_id, COUNT(*) as checked')->get() as $row) {
            $counts[(int) $row->column_id] = (int) $row->checked;
        }

        return $counts;
    }
}
