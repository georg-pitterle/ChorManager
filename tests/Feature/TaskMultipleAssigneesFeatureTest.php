<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\TaskController;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Eine Aufgabe gehörte bis zur Migration 20260830120000 genau einer Person.
 * Wer zu zweit an einer Sache arbeitete, legte die Aufgabe zweimal an - und
 * pflegte danach zwei Verläufe.
 */
class TaskMultipleAssigneesFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private Project $project;
    private User $anna;
    private User $bernd;
    private User $clara;

    protected function setUp(): void
    {
        parent::setUp();

        Bootstrap::setupTestDatabase();

        $this->project = Project::create([
            'name' => 'Mehrfach-Zuweisung ' . bin2hex(random_bytes(4)),
            'description' => 'Projekt für die Mehrfach-Zuweisung',
        ]);

        $this->anna = $this->createUser('Anna', 'Amsel');
        $this->bernd = $this->createUser('Bernd', 'Buchfink');
        $this->clara = $this->createUser('Clara', 'Kranich');

        $this->project->users()->attach([$this->anna->id, $this->bernd->id, $this->clara->id]);

        $_SESSION = ['user_id' => (int) $this->anna->id, 'can_manage_tasks' => true];
    }

    protected function tearDown(): void
    {
        $taskIds = Task::where('project_id', $this->project->id)->pluck('id')->all();
        Activity::where('entity_type', 'task')->whereIn('entity_id', $taskIds)->delete();
        Task::whereIn('id', $taskIds)->delete();
        $this->project->users()->detach();
        $this->project->delete();
        $this->anna->delete();
        $this->bernd->delete();
        $this->clara->delete();

        $_SESSION = [];

        parent::tearDown();
    }

    public function testCreateStoresEveryChosenPerson(): void
    {
        $this->controller()->create(
            $this->makeRequest('POST', '/projects/' . $this->project->id . '/tasks', [
                'title' => 'Bühne aufbauen',
                'assigned_user_ids' => [(string) $this->anna->id, (string) $this->bernd->id],
            ]),
            $this->makeResponse(),
            ['project_id' => (string) $this->project->id]
        );

        $task = Task::where('name', 'Bühne aufbauen')->first();

        $this->assertNotNull($task);
        $this->assertSame(
            $this->sorted([(int) $this->anna->id, (int) $this->bernd->id]),
            $this->assigneeIds($task)
        );
    }

    /**
     * Die Gegenrichtung: Beide Zugewiesenen finden die Aufgabe auch über die
     * eigene Aufgabenliste, sonst wäre die Zuweisung nur Zierde am Datensatz.
     */
    public function testEveryAssigneeFindsTheTaskInTheirOwnList(): void
    {
        $task = $this->makeTask('Programmheft prüfen', [$this->anna->id, $this->bernd->id]);

        foreach ([$this->anna, $this->bernd] as $user) {
            $this->assertContains(
                (int) $task->id,
                array_map('intval', $user->tasks()->pluck('tasks.id')->all()),
                'Die Aufgabe fehlt in der Liste von ' . $user->first_name . '.'
            );
        }

        $this->assertNotContains(
            (int) $task->id,
            array_map('intval', $this->clara->tasks()->pluck('tasks.id')->all())
        );
    }

    public function testDuplicateSelectionsAreStoredOnlyOnce(): void
    {
        $this->controller()->create(
            $this->makeRequest('POST', '/projects/' . $this->project->id . '/tasks', [
                'title' => 'Doppelt gewählt',
                'assigned_user_ids' => [(string) $this->anna->id, (string) $this->anna->id],
            ]),
            $this->makeResponse(),
            ['project_id' => (string) $this->project->id]
        );

        $task = Task::where('name', 'Doppelt gewählt')->first();

        $this->assertNotNull($task);
        $this->assertSame([(int) $this->anna->id], $this->assigneeIds($task));
    }

    /**
     * Der Verlauf nennt Zugang und Abgang getrennt. Nur den neuen Stand zu
     * schreiben würde bei drei Namen verschweigen, wer weggefallen ist.
     */
    public function testTheActivityEntryNamesArrivalsAndDepartures(): void
    {
        $task = $this->makeTask('Technik abstimmen', [$this->anna->id, $this->bernd->id]);

        $this->controller()->update(
            $this->makeRequest('POST', '/tasks/' . $task->id . '/update', [
                'title' => 'Technik abstimmen',
                'assigned_user_ids' => [(string) $this->anna->id, (string) $this->clara->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertSame(
            $this->sorted([(int) $this->anna->id, (int) $this->clara->id]),
            $this->assigneeIds($task)
        );

        $description = $this->lastActivityDescription($task);

        $this->assertStringContainsString('Zugewiesen an: Clara Kranich', $description);
        $this->assertStringContainsString('Zuweisung entfernt: Bernd Buchfink', $description);
        $this->assertStringNotContainsString('Anna Amsel', $description);
    }

    public function testAnUnchangedAssignmentLeavesNoActivityEntry(): void
    {
        $task = $this->makeTask('Unverändert', [$this->bernd->id, $this->anna->id]);

        $this->controller()->update(
            $this->makeRequest('POST', '/tasks/' . $task->id . '/update', [
                'title' => 'Unverändert',
                // Umgekehrte Reihenfolge - dieselbe Menge.
                'assigned_user_ids' => [(string) $this->anna->id, (string) $this->bernd->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertNull($this->lastActivity($task));
    }

    public function testRemovingEveryAssigneeIsLogged(): void
    {
        $task = $this->makeTask('Niemand mehr zuständig', [$this->anna->id]);

        $this->controller()->update(
            $this->makeRequest('POST', '/tasks/' . $task->id . '/update', [
                'title' => 'Niemand mehr zuständig',
                'assigned_user_ids' => [],
            ]),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertSame([], $this->assigneeIds($task));
        $this->assertStringContainsString(
            'Zuweisung entfernt: Anna Amsel',
            $this->lastActivityDescription($task)
        );
    }

    /**
     * @param list<int> $userIds
     */
    private function makeTask(string $name, array $userIds): Task
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => $name,
            'status' => 'Offen',
            'created_by' => $this->anna->id,
        ]);
        $task->assignees()->sync($userIds);

        return $task;
    }

    /**
     * @return list<int>
     */
    private function assigneeIds(Task $task): array
    {
        return $this->sorted(array_map('intval', $task->assignees()->pluck('users.id')->all()));
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function lastActivity(Task $task): ?Activity
    {
        return Activity::where('entity_type', 'task')
            ->where('entity_id', $task->id)
            ->orderBy('id', 'desc')
            ->first();
    }

    private function lastActivityDescription(Task $task): string
    {
        $activity = $this->lastActivity($task);
        $this->assertNotNull($activity, 'Es wurde kein Verlaufseintrag geschrieben.');

        return (string) $activity->description;
    }

    private function controller(): TaskController
    {
        return new TaskController(
            $this->createStub(Twig::class),
            new HtmlSanitizer(),
            new TaskPolicy(),
            new NameFormatterService(),
            new NullLogger()
        );
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'task.multi.' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }
}
