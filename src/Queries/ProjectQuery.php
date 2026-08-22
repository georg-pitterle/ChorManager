<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Project;
use App\Models\User;
use App\Services\NameFormatterService;
use App\Util\VoiceGroupOrder;
use Illuminate\Database\Eloquent\Collection;

class ProjectQuery
{
    private NameFormatterService $nameFormatter;

    public function __construct(NameFormatterService $nameFormatter)
    {
        $this->nameFormatter = $nameFormatter;
    }

    public function findById(int $id): ?Project
    {
        return Project::find($id);
    }

    public function getAllProjects(): Collection
    {
        return Project::orderBy('start_date', 'desc')->get();
    }

    /**
     * Returns the id of the project running today (start_date <= today <= end_date),
     * restricted to the given accessible project ids. If several projects run in
     * parallel, the one ending first wins. Returns 0 if none is running.
     *
     * @param int[] $accessibleProjectIds
     */
    public function findCurrentProjectId(array $accessibleProjectIds): int
    {
        if ($accessibleProjectIds === []) {
            return 0;
        }

        $today = date('Y-m-d');

        $project = Project::whereIn('id', $accessibleProjectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->orderBy('end_date', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        return $project ? (int) $project->id : 0;
    }

    public function getProjectMembers(int $projectId): Collection
    {
        $query = User::whereHas('projects', function ($query) use ($projectId) {
            $query->where('project_id', $projectId);
        })
            ->where('is_active', 1)
            ->with([
                'voiceGroups' => function ($query) {
                    $query->select('voice_groups.id', 'voice_groups.name')
                        ->withPivot('sub_voice_id');
                },
                'subVoices'
            ]);

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }

    public function isProjectMember(int $projectId, int $userId): bool
    {
        return User::where('id', $userId)
            ->whereHas('projects', function ($query) use ($projectId) {
                $query->where('project_id', $projectId);
            })->exists();
    }

    /**
     * Returns all users that are not yet assigned to the project, including archived (inactive) ones.
     * Archived users can be assigned to a project and are reactivated on assignment.
     */
    public function getUsersNotInProject(int $projectId): Collection
    {
        $query = User::whereDoesntHave('projects', function ($query) use ($projectId) {
            $query->where('project_id', $projectId);
        });

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }

    /**
     * Like getUsersNotInProject(), but restricted to users that belong to at
     * least one of the given voice groups. Used for the voice-group-scoped
     * project assignment right, where the candidate list must not leak members
     * of foreign voice groups. An empty voice group list yields no candidates.
     *
     * @param array<int> $voiceGroupIds
     */
    public function getUsersNotInProjectForVoiceGroups(int $projectId, array $voiceGroupIds): Collection
    {
        if ($voiceGroupIds === []) {
            return new Collection();
        }

        $query = User::whereDoesntHave('projects', function ($query) use ($projectId) {
            $query->where('project_id', $projectId);
        })->whereHas('voiceGroups', function ($query) use ($voiceGroupIds) {
            $query->whereIn('voice_group_id', $voiceGroupIds);
        });

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
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
     * The voice group ids a single user belongs to. Used to authorize
     * voice-group-scoped project member changes. A missing user yields [].
     *
     * @return array<int>
     */
    public function getUserVoiceGroupIds(int $userId): array
    {
        $user = User::with('voiceGroups')->find($userId);
        if (!$user) {
            return [];
        }

        return $user->voiceGroups->pluck('id')->map('intval')->all();
    }

    /**
     * True when the given member exists at all.
     *
     * getUserVoiceGroupIds() liefert fuer ein unbekanntes Mitglied dasselbe leere
     * Array wie fuer eines ohne Stimmgruppe - die Existenz muss deshalb getrennt
     * geprueft werden, bevor eine Zuordnung in den Fremdschluessel laeuft.
     */
    public function userExists(int $userId): bool
    {
        return User::whereKey($userId)->exists();
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

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        $users = $query->get();

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

        // Sort voice groups into canonical SATB order, "ohne Stimmgruppe" last
        $grouped = VoiceGroupOrder::sortNameKeyedMap($grouped, ['_ohne_stimmgruppe']);

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
