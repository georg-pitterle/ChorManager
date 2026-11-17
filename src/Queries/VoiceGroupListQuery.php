<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Project;
use App\Models\VoiceGroup;
use App\Models\VoiceGroupList;
use Illuminate\Support\Collection;

/**
 * Die Abfragen der Stimmgruppenlisten.
 *
 * Sie standen im VoiceGroupListController. Eine leere Liste von Stimmgruppen ergibt
 * eine leere Antwort ohne Abfrage.
 */
class VoiceGroupListQuery
{
    /**
     * @param list<int> $voiceGroupIds
     * @return array<string, VoiceGroup> nach Name
     */
    public function voiceGroupsByName(array $voiceGroupIds): array
    {
        if ($voiceGroupIds === []) {
            return [];
        }

        return VoiceGroup::query()->whereIn('id', $voiceGroupIds)->get()->keyBy('name')->all();
    }

    /**
     * @param list<int> $voiceGroupIds
     * @return Collection<int, VoiceGroupList> mit ihrem Projekt, nach Titel
     */
    public function listsForVoiceGroups(array $voiceGroupIds): Collection
    {
        if ($voiceGroupIds === []) {
            return collect();
        }

        return VoiceGroupList::query()
            ->with('project')
            ->whereIn('voice_group_id', $voiceGroupIds)
            ->orderBy('title')
            ->get();
    }

    /**
     * Die Projekte, zu denen eine Liste dieser Stimmgruppen gehören darf: Projekte, in
     * denen mindestens ein Mitglied einer der Stimmgruppen mitwirkt - dazu das bisherige
     * Projekt der Liste, damit eine Bearbeitung es nicht verliert.
     *
     * @param list<int> $voiceGroupIds
     * @return Collection<int, Project>
     */
    public function allowedProjects(array $voiceGroupIds, ?int $currentProjectId = null): Collection
    {
        if ($voiceGroupIds === []) {
            return collect();
        }

        return Project::query()
            ->whereExists(static function ($sub) use ($voiceGroupIds): void {
                $sub->selectRaw('1')
                    ->from('project_users')
                    ->join('user_voice_groups', 'user_voice_groups.user_id', '=', 'project_users.user_id')
                    ->whereColumn('project_users.project_id', 'projects.id')
                    ->whereIn('user_voice_groups.voice_group_id', $voiceGroupIds);
            })
            ->when($currentProjectId !== null, static fn($query) => $query->orWhere('projects.id', $currentProjectId))
            ->orderByDesc('start_date')
            ->orderBy('name')
            ->get(['id', 'name', 'start_date']);
    }
}
