<?php
declare(strict_types = 1)
;

namespace App\Persistence;

use App\Models\Project;
use App\Models\User;

class ProjectPersistence
{
    public function addProjectMember(int $projectId, int $userId): void
    {
        $project = Project::find($projectId);
        if ($project) {
            $project->users()->syncWithoutDetaching([$userId]);
        }
    }

    public function removeProjectMember(int $projectId, int $userId): void
    {
        $project = Project::find($projectId);
        if ($project) {
            $project->users()->detach($userId);
        }
    }

    public function setUserProjects(int $userId, array $projectIds): void
    {
        $user = User::find($userId);
        if ($user) {
            // Filter out 0/empty values
            $validIds = array_filter($projectIds, fn($id) => (int)$id > 0);
            $user->projects()->sync($validIds);
        }
    }
}
