<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ProjectQuery
{
    public function findById(int $id): ?Project
    {
        return Project::find($id);
    }

    public function getAllProjects(): Collection
    {
        return Project::orderBy('start_date', 'desc')->get();
    }

    public function getProjectMembers(int $projectId): Collection
    {
        return User::whereHas('projects', function ($query) use ($projectId) {
            $query->where('project_id', $projectId);
        })
            ->where('is_active', 1)
            ->with(['voiceGroups.subVoices', 'subVoices.voiceGroup'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function isProjectMember(int $projectId, int $userId): bool
    {
        return User::where('id', $userId)
            ->whereHas('projects', function ($query) use ($projectId) {
                $query->where('project_id', $projectId);
            })->exists();
    }

    public function getUsersNotInProject(int $projectId): Collection
    {
        return User::whereDoesntHave('projects', function ($query) use ($projectId) {
            $query->where('project_id', $projectId);
        })
            ->where('is_active', 1)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function getUserProjectIds(int $userId): array
    {
        $user = User::with('projects')->find($userId);
        if (!$user) {
            return [];
        }
        return $user->projects->pluck('id')->toArray();
    }

    /**
     * Returns project members grouped by voice group and sub-voice for the evaluation view.
     */
    public function getProjectMembersGroupedByVoice(int $projectId, ?array $filterVoiceGroupIds = null): array
    {
        $query = User::whereHas('projects', function ($query) use ($projectId) {
            $query->where('project_id', $projectId);
        })
            ->where('is_active', 1)
            ->with(['voiceGroups', 'subVoices.voiceGroup']);

        if ($filterVoiceGroupIds !== null && count($filterVoiceGroupIds) > 0) {
            $query->whereHas('voiceGroups', function ($q) use ($filterVoiceGroupIds) {
                $q->whereIn('voice_group_id', $filterVoiceGroupIds);
            });
        }

        $users = $query->orderBy('last_name')->orderBy('first_name')->get();

        $grouped = [];
        foreach ($users as $user) {
            // Find the active voice group (and subvoice if any) for this user.
            // If none, default to _ohne_stimmgruppe / _ohne_teilstimme
            $vgName = '_ohne_stimmgruppe';
            $svName = '_ohne_teilstimme';

            $voiceGroup = $user->voiceGroups->first();
            if ($voiceGroup) {
                $vgName = $voiceGroup->name;
                // sub_voice_id is stored in the pivot table for user_voice_groups
                $subVoiceId = $voiceGroup->pivot->sub_voice_id;
                if ($subVoiceId) {
                    $subVoice = $user->subVoices->firstWhere('id', $subVoiceId);
                    if ($subVoice) {
                        $svName = $subVoice->name;
                    }
                }
            }

            if (!isset($grouped[$vgName])) {
                $grouped[$vgName] = [];
            }
            if (!isset($grouped[$vgName][$svName])) {
                $grouped[$vgName][$svName] = [];
            }
            // store raw array structure compatible with legacy twig templates
            //(or just pass the user object if preferred)
            // for minimal twig breakage, we can store array data
            $grouped[$vgName][$svName][] = [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'voice_group_name' => $vgName !== '_ohne_stimmgruppe' ? $vgName : null,
                'sub_voice_name' => $svName !== '_ohne_teilstimme' ? $svName : null,
            ];
        }

        // Sort voice groups: put "ohne Stimmgruppe" last
        if (isset($grouped['_ohne_stimmgruppe'])) {
            $ungrouped = $grouped['_ohne_stimmgruppe'];
            unset($grouped['_ohne_stimmgruppe']);
            $grouped['_ohne_stimmgruppe'] = $ungrouped;
        }

        // Sort sub-voices within each voice group by name (except _ohne_teilstimme)
        foreach ($grouped as $vg => &$subVoices) {
            ksort($subVoices);
            if (isset($subVoices['_ohne_teilstimme'])) {
                $ungroupedSv = $subVoices['_ohne_teilstimme'];
                unset($subVoices['_ohne_teilstimme']);
                $subVoices['_ohne_teilstimme'] = $ungroupedSv;
            }
        }

        return $grouped;
    }
}
