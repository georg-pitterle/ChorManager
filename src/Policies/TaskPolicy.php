<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;

/**
 * Policy for task management authorization.
 *
 * Determines whether a user can create, view, update, or delete tasks
 * within a specific project.
 */
class TaskPolicy
{
    private bool $canManageTasks;

    private int $userId;

    /**
     * Die Sitzung kommt von außen - siehe
     * tests/Unit/Policies/PoliciesReceiveTheSessionTest.
     *
     * @param array<string, mixed> $session
     */
    public function __construct(array $session)
    {
        // Auf Wahrheitswert prüfen, nicht strikt auf true - RoleMiddleware
        // liest denselben Schlüssel ebenfalls nur truthy.
        $this->canManageTasks = (bool) ($session['can_manage_tasks'] ?? false);
        $this->userId = (int) ($session['user_id'] ?? 0);
    }

    /**
     * Check if the user can manage tasks.
     *
     * Ausschlaggebend ist allein das explizite Recht can_manage_tasks -
     * Mitgliederverwaltung ist kein Ersatz dafür, sonst hätten Rollenverwalter
     * still Zugriff auf Projektaufgaben.
     *
     * Die Entscheidung fällt bewusst projektunabhängig: eine eigene
     * Projektmitgliedschaft wird nicht verlangt, weil die Planung eines frisch
     * angelegten Projekts noch keine Mitglieder hat und sonst für niemanden
     * erreichbar wäre.
     */
    public function canManageTasks(): bool
    {
        return $this->canManageTasks;
    }

    /**
     * Darf diese Person die Aufgabe lesen?
     *
     * Zwei Wege: das Recht, oder die eigene Zuständigkeit. Der zweite kam mit
     * Antwort 1C aus Review-Lauf 78 dazu. Bis dahin hing die Aufgabenseite
     * allein am Recht, während das Kalender-Abo jedem Zugewiesenen seine
     * Aufgaben schickte - samt Link, der für diese Personen ins Leere führte.
     *
     * Nur Lesen. Ändern, Abhaken, Kommentieren, Hochladen und Löschen bleiben
     * bei `canManageTasks()`: Wer eine Aufgabe zugewiesen bekommt, hat damit
     * noch keine Hoheit über ihren Zuschnitt, und welche Schreibwege eine
     * zuständige Person zusätzlich bekommen soll, ist eine eigene Entscheidung.
     *
     * Ohne angemeldete Person lautet die Antwort nein. Sonst öffnete eine
     * Aufgabe mit einer Null in `task_users.user_id` den Zugang für jeden
     * Abruf ohne Sitzung.
     */
    public function canViewTask(Task $task): bool
    {
        if ($this->canManageTasks) {
            return true;
        }

        if ($this->userId <= 0) {
            return false;
        }

        $userId = $this->userId;

        return $task->assignees->contains(
            static fn ($assignee): bool => (int) $assignee->id === $userId
        );
    }
}
