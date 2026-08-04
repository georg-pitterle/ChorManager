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
        $this->canManageTasks = ($_SESSION['can_manage_tasks'] ?? false) === true;
    }

    /**
     * Check if the user can manage tasks in a specific project.
     *
     * Ausschlaggebend ist allein das explizite Recht can_manage_tasks -
     * Mitgliederverwaltung ist kein Ersatz dafuer, sonst haetten Rollenverwalter
     * still Zugriff auf Projektaufgaben.
     *
     * Eine eigene Projektmitgliedschaft wird bewusst nicht verlangt: die Planung
     * eines frisch angelegten Projekts hat noch keine Mitglieder und waere sonst
     * fuer niemanden erreichbar.
     */
    public function canManageTasks(int $projectId): bool
    {
        return $this->canManageTasks;
    }
}
