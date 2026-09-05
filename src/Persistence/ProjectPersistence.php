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
     * Die Reaktivierung ist ausdrücklich gewollt und nicht an ein Benutzerverwaltungsrecht
     * gebunden: wer Projektmitglieder pflegen darf, holt ein archiviertes Mitglied damit auch
     * ohne can_manage_users zurück - beim breiten Recht systemweit, beim stimmgruppen-
     * beschränkten Recht innerhalb der eigenen Stimmgruppe.
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

    /**
     * Setzt die Projektzuordnung eines Mitglieds auf genau die übergebenen Projekte.
     *
     * Unbekannte Ids werden verworfen, nicht durchgereicht: sie liefen sonst in den
     * Fremdschlüssel von `project_users`, und im Zweig für die reine Projektzuordnung
     * (UserController::update() ohne can_edit_users) fängt das niemand ab - die Eingabe
     * endete in einem HTTP 500. Verworfen wird nur der unbekannte Wert, damit ein aus der
     * Oberfläche stammender Rest weiterhin gespeichert wird.
     *
     * @param array<int|string> $projectIds
     */
    public function setUserProjects(int $userId, array $projectIds): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }

        $requested = array_values(array_unique(array_filter(
            array_map('intval', $projectIds),
            static fn(int $id): bool => $id > 0
        )));

        $existingIds = $requested === []
            ? []
            : Project::whereIn('id', $requested)
                ->pluck('id')
                ->map(static fn($id): int => (int) $id)
                ->all();

        $user->projects()->sync($existingIds);
    }
}
