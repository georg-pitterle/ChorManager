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
     * Die Reihenfolge kommt aus Project::scopeChronological() und stand hier zuvor
     * abgeschrieben daneben - genau das, was der Scope verhindern soll: Wer ihn ändert,
     * ändert jede Projektliste außer dieser, und die Auswahl der Liste läse sich dann
     * anders geordnet als die Projektübersicht daneben. Dass beide Seiten dieselbe
     * Reihenfolge liefern, hält
     * VoiceGroupListQueryFeatureTest::testTheProjectChoiceFollowsTheSharedProjectOrder()
     * fest.
     *
     * Die Klammer um die beiden Bedingungen ist tragend - dieselbe Lage wie in
     * ProjectQuery::findCurrentProjectId(). Ungeklammert hing das ODER an der Spitze
     * der UND-Kette; dass dabei nichts kaputtging, lag allein daran, dass
     * Project::scopeChronological() nur sortiert. Eine Bedingung mehr dort oder an der
     * Aufrufkette, und das ODER bricht aus: Jedes Projekt wäre wählbar, auch eines,
     * in dem keine dieser Stimmgruppen mitwirkt.
     *
     * @param list<int> $voiceGroupIds
     * @return Collection<int, Project>
     */
    public function allowedProjects(array $voiceGroupIds, ?int $currentProjectId = null): Collection
    {
        if ($voiceGroupIds === [] && $currentProjectId === null) {
            return collect();
        }

        return Project::query()
            ->where(static function ($query) use ($voiceGroupIds, $currentProjectId): void {
                // Ohne Stimmgruppen trägt die Mitwirkung nichts bei. Das muss
                // ausdrücklich dastehen: Eine Klammer ohne Bedingung ließe jedes
                // Projekt durch - also das Gegenteil von "keine Stimmgruppe, kein
                // Projekt". Das bisherige Projekt bleibt daneben erhalten, sonst
                // hätte der Kurzschluss von vorhin es weiter verschluckt.
                if ($voiceGroupIds === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereExists(static function ($sub) use ($voiceGroupIds): void {
                        $sub->selectRaw('1')
                            ->from('project_users')
                            ->join('user_voice_groups', 'user_voice_groups.user_id', '=', 'project_users.user_id')
                            ->whereColumn('project_users.project_id', 'projects.id')
                            ->whereIn('user_voice_groups.voice_group_id', $voiceGroupIds);
                    });
                }

                if ($currentProjectId !== null) {
                    $query->orWhere('projects.id', $currentProjectId);
                }
            })
            ->chronological()
            ->get(['id', 'name', 'start_date']);
    }
}
