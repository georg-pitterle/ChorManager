<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Models\Project;
use App\Models\User;

class ProjectPersistence
{
    /**
     * Assigns a user to a project. Archived (inactive) users are reactivated by the assignment.
     *
     * Die Reaktivierung ist ausdruecklich gewollt und nicht an ein Benutzerverwaltungsrecht
     * gebunden: wer Projektmitglieder pflegen darf, holt ein archiviertes Mitglied damit auch
     * ohne can_manage_users zurueck - beim breiten Recht systemweit, beim stimmgruppen-
     * beschraenkten Recht innerhalb der eigenen Stimmgruppe.
     *
     * @return bool True if the user was reactivated by this assignment.
     */
    public function addProjectMember(int $projectId, int $userId): bool
    {
        $project = Project::find($projectId);
        if (!$project) {
            return false;
        }

        $project->users()->syncWithoutDetaching([$userId]);

        $user = User::find($userId);
        if (!$user || (bool)$user->is_active) {
            return false;
        }

        $user->is_active = 1;
        $user->save();

        return true;
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
