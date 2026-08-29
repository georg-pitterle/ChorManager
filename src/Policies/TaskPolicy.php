<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Policy for task management authorization.
 *
 * Determines whether a user can create, view, update, or delete tasks
 * within a specific project.
 */
class TaskPolicy
{
    private bool $canManageTasks;

    public function __construct()
    {
        // Auf Wahrheitswert prüfen, nicht strikt auf true - RoleMiddleware und
        // DashboardController lesen denselben Schlüssel ebenfalls nur truthy.
        $this->canManageTasks = (bool) ($_SESSION['can_manage_tasks'] ?? false);
    }

    /**
     * Check if the user can manage tasks.
     *
     * Ausschlaggebend ist allein das explizite Recht can_manage_tasks -
     * Mitgliederverwaltung ist kein Ersatz dafuer, sonst haetten Rollenverwalter
     * still Zugriff auf Projektaufgaben.
     *
     * Die Entscheidung faellt bewusst projektunabhaengig: eine eigene
     * Projektmitgliedschaft wird nicht verlangt, weil die Planung eines frisch
     * angelegten Projekts noch keine Mitglieder hat und sonst fuer niemanden
     * erreichbar waere.
     */
    public function canManageTasks(): bool
    {
        return $this->canManageTasks;
    }
}
