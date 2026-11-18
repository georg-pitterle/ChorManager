<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Zwei Fragen, zwei Antworten: Verwalten darf, wer das Recht hat; lesen
 * zusätzlich, wem die Aufgabe zugewiesen ist.
 *
 * Die zweite Frage kam mit Antwort 1C aus Review-Lauf 78 dazu. Vorher hing
 * die Aufgabenseite allein am Recht, während der Kalender-Feed jedem
 * Zugewiesenen seine Aufgabe schickte - mit einem Link, der ins Leere führte.
 */
final class TaskPolicyTest extends TestCase
{
    public function testManagementRightAlonePermitsManaging(): void
    {
        $this->assertTrue((new TaskPolicy(['can_manage_tasks' => true]))->canManageTasks());
        $this->assertFalse((new TaskPolicy(['can_manage_tasks' => false]))->canManageTasks());
        $this->assertFalse((new TaskPolicy([]))->canManageTasks());
    }

    public function testTheManagementRightAlsoPermitsReadingAnyTask(): void
    {
        $policy = new TaskPolicy(['user_id' => 11, 'can_manage_tasks' => true]);

        $this->assertTrue($policy->canViewTask($this->taskAssignedTo(42)));
    }

    public function testAnAssigneeMayReadTheirTaskWithoutTheManagementRight(): void
    {
        $policy = new TaskPolicy(['user_id' => 42]);

        $this->assertTrue($policy->canViewTask($this->taskAssignedTo(7, 42)));
    }

    public function testSomebodyElseMayNotReadTheTask(): void
    {
        $policy = new TaskPolicy(['user_id' => 99]);

        $this->assertFalse($policy->canViewTask($this->taskAssignedTo(7, 42)));
    }

    /**
     * Ohne angemeldete Person lautet die Antwort nein. Sonst entschiede eine
     * Aufgabe ohne Zuständige über den Zugang, weil 0 gegen 0 stimmte.
     */
    public function testWithoutASessionUserNobodyMayRead(): void
    {
        $policy = new TaskPolicy([]);

        $this->assertFalse($policy->canViewTask($this->taskAssignedTo(0)));
        $this->assertFalse($policy->canViewTask($this->taskWithoutAssignees()));
    }

    private function taskAssignedTo(int ...$userIds): Task
    {
        $assignees = [];
        foreach ($userIds as $userId) {
            $user = new User();
            $user->setRawAttributes(['id' => $userId], true);
            $assignees[] = $user;
        }

        $task = new Task();
        $task->setRawAttributes(['id' => 5, 'name' => 'Programmhefte holen'], true);
        $task->setRelation('assignees', new Collection($assignees));

        return $task;
    }

    private function taskWithoutAssignees(): Task
    {
        $task = new Task();
        $task->setRawAttributes(['id' => 5, 'name' => 'Programmhefte holen'], true);
        $task->setRelation('assignees', new Collection());

        return $task;
    }
}
