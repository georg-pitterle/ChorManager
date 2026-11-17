<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Models\VoiceGroupList;
use App\Models\VoiceGroupListColumn;
use App\Models\VoiceGroupListEntry;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Schreibt Stimmgruppenlisten, ihre Spalten und die Einträge darin.
 *
 * Die Schreibaufrufe standen im VoiceGroupListController. Fachregeln (wer eine Liste
 * sehen darf, ob sie archiviert ist, was als Titel gilt) bleiben dort; hier kommen
 * fertige Werte an.
 */
class VoiceGroupListPersistence
{
    public function createList(int $voiceGroupId, string $title, ?int $projectId, ?int $createdByUserId): VoiceGroupList
    {
        return VoiceGroupList::create([
            'voice_group_id' => $voiceGroupId,
            'title' => $title,
            'project_id' => $projectId,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    public function updateList(VoiceGroupList $list, string $title, ?int $projectId): void
    {
        $list->update(['title' => $title, 'project_id' => $projectId]);
    }

    public function archiveList(VoiceGroupList $list): void
    {
        $list->update(['archived_at' => Carbon::now()]);
    }

    public function restoreList(VoiceGroupList $list): void
    {
        $list->update(['archived_at' => null]);
    }

    public function deleteList(VoiceGroupList $list): void
    {
        $list->delete();
    }

    public function addColumn(VoiceGroupList $list, string $label, string $type): VoiceGroupListColumn
    {
        return VoiceGroupListColumn::create([
            'list_id' => $list->id,
            'label' => $label,
            'type' => $type,
            'sort_order' => (int) $list->columns()->max('sort_order') + 1,
        ]);
    }

    public function renameColumn(VoiceGroupListColumn $column, string $label): void
    {
        $column->update(['label' => $label]);
    }

    /**
     * Neu durchnummeriert statt getauscht: Gleiche sort_order-Werte (etwa aus einem
     * Altbestand) ließen sich sonst nicht trennen. Geschrieben wird nur, was sich ändert.
     *
     * @param list<VoiceGroupListColumn> $columns in der neuen Reihenfolge
     */
    public function renumberColumns(array $columns): void
    {
        Capsule::connection()->transaction(static function () use ($columns): void {
            foreach ($columns as $position => $column) {
                if ((int) $column->sort_order !== $position) {
                    $column->update(['sort_order' => $position]);
                }
            }
        });
    }

    public function deleteColumn(VoiceGroupListColumn $column): void
    {
        $column->delete();
    }

    /**
     * Ein leeres Häkchen und eine leere Notiz sind kein Eintrag: Die Zeile
     * verschwindet, eine neue Spalte ist damit für alle offen.
     */
    public function storeEntry(
        VoiceGroupListColumn $column,
        int $userId,
        bool|string $value,
        ?int $actorId
    ): ?VoiceGroupListEntry {
        $existing = VoiceGroupListEntry::where('column_id', $column->id)->where('user_id', $userId)->first();

        if ($value === false || $value === '') {
            $existing?->delete();
            return null;
        }

        // Ein schon gesetztes Häkchen bleibt, wie es ist: Klickt eine zweite
        // Stimmvertretung auf einer veralteten Seite erneut, hieße es sonst
        // "Abgehakt von ihr" - mit dem Datum der ersten.
        if ($column->isCheckbox() && $existing?->checked_at !== null) {
            return $existing->load('updatedBy');
        }

        $attributes = $column->isCheckbox()
            ? ['checked_at' => Carbon::now()]
            : ['note' => $value];
        $attributes['updated_by_user_id'] = $actorId;

        $entry = $existing ?? new VoiceGroupListEntry(['column_id' => $column->id, 'user_id' => $userId]);
        $entry->fill($attributes)->save();

        return $entry->load('updatedBy');
    }
}
