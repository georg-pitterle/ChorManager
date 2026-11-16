<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\Comment;
use App\Models\Task;
use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Schreibt Aufgaben samt Verlaufseintrag: Anlegen, Ändern, Kommentieren und
 * Statuswechsel.
 *
 * Die Transaktionen standen im TaskController. Jede Methode hält Aufgabe, Zuständige
 * und Verlauf zusammen; Benachrichtigungen gehören nicht hierher, sie gehen erst
 * nach dem Festschreiben raus - innerhalb der Transaktion stünde die Mail schon in
 * der Warteschlange, während die Aufgabe bei einem Rücksetzer nie entsteht.
 */
class TaskWriteService
{
    /**
     * Legt eine Aufgabe an, setzt die Zuständigen und schreibt den Verlaufseintrag.
     *
     * @param array{name: string, description: ?string, start_date: ?string,
     *     end_date: ?string, status: string, priority: string} $attributes bereits geprüft
     * @param list<int> $assigneeIds
     */
    public function create(int $projectId, array $attributes, array $assigneeIds, int $actorId): Task
    {
        return Capsule::connection()->transaction(
            function () use ($projectId, $attributes, $assigneeIds, $actorId): Task {
                $task = Task::create(array_merge($attributes, [
                    'project_id' => $projectId,
                    'created_by' => $actorId,
                ]));

                $task->assignees()->sync($assigneeIds);

                Activity::create([
                    'entity_type' => 'task',
                    'entity_id'   => $task->id,
                    'user_id'     => $actorId,
                    'action'      => 'created',
                    'description' => 'Aufgabe erstellt.',
                ]);

                return $task;
            }
        );
    }

    /**
     * Ändert die Aufgabe, setzt die Zuständigen und schreibt - falls sich etwas
     * geändert hat - den Verlaufseintrag.
     *
     * @param array<string, mixed> $attributes bereits geprüft
     * @param list<int> $assigneeIds
     * @param Closure(Task): ?string $describeChanges bekommt die Aufgabe nach dem
     *     Speichern und liefert den Verlaufstext oder null (dann kein Eintrag)
     */
    public function update(
        Task $task,
        array $attributes,
        array $assigneeIds,
        int $actorId,
        Closure $describeChanges
    ): void {
        Capsule::connection()->transaction(
            function () use ($task, $attributes, $assigneeIds, $actorId, $describeChanges): void {
                $task->update($attributes);
                $task->assignees()->sync($assigneeIds);

                $description = $describeChanges($task);
                if ($description !== null && $description !== '') {
                    Activity::create([
                        'entity_type' => 'task',
                        'entity_id'   => $task->id,
                        'user_id'     => $actorId,
                        'action'      => 'updated',
                        'description' => $description,
                    ]);
                }
            }
        );
    }

    public function addComment(Task $task, string $content, int $actorId): void
    {
        Capsule::connection()->transaction(function () use ($task, $content, $actorId): void {
            Comment::create([
                'entity_type' => 'task',
                'entity_id'   => $task->id,
                'user_id'     => $actorId,
                'comment'     => $content,
            ]);

            Activity::create([
                'entity_type' => 'task',
                'entity_id'   => $task->id,
                'user_id'     => $actorId,
                'action'      => 'commented',
                'description' => 'Neuer Kommentar hinzugefügt.',
            ]);
        });
    }

    public function changeStatus(Task $task, string $newStatus, string $activityText, int $actorId): void
    {
        Capsule::connection()->transaction(function () use ($task, $newStatus, $activityText, $actorId): void {
            $task->update([
                'status' => $newStatus,
            ]);

            Activity::create([
                'entity_type' => 'task',
                'entity_id'   => $task->id,
                'user_id'     => $actorId,
                'action'      => 'updated',
                'description' => $activityText,
            ]);
        });
    }
}
