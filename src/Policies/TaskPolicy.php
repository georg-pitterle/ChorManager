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
}
