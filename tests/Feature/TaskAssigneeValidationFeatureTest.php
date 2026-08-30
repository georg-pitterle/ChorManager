<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\TaskController;
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
 * Das Auswahlfeld "Zugewiesen an" bietet nur Projektmitglieder an. Ohne
 * serverseitige Prüfung landete ein abweichender Wert direkt im Fremdschlüssel
 * tasks_user_fk und endete in einem 500 statt in einer Meldung.
 *
 * Abgewiesen statt still auf null gesetzt: Beim Bearbeiten wäre das stille
 * Verwerfen Datenverlust ohne Hinweis, sobald jemand das Projekt verlassen hat
 * und danach ein ganz anderes Feld gespeichert wird.
 */
class TaskAssigneeValidationFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private Project $project;
    private User $member;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        Bootstrap::setupTestDatabase();

        $this->project = Project::create([
            'name' => 'Aufgaben-Zuweisung ' . bin2hex(random_bytes(4)),
            'description' => 'Projekt für die Zuweisungsprüfung',
        ]);

        $this->member = $this->createUser('member');
        $this->outsider = $this->createUser('outsider');
        $this->project->users()->attach($this->member->id);

        $_SESSION = ['user_id' => (int) $this->member->id, 'can_manage_tasks' => true];
    }

    protected function tearDown(): void
    {
        Task::where('project_id', $this->project->id)->delete();
        $this->project->users()->detach();
        $this->project->delete();
        $this->member->delete();
        $this->outsider->delete();
        $_SESSION = [];

        parent::tearDown();
    }

    public function testCreateRejectsAnAssigneeOutsideTheProject(): void
    {
        $response = $this->controller()->create(
            $this->makeRequest('POST', '/projects/' . $this->project->id . '/tasks', [
                'title' => 'Fremde Zuweisung',
                'assigned_user_ids' => [(string) $this->outsider->id],
            ]),
            $this->makeResponse(),
            ['project_id' => (string) $this->project->id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'Mindestens eine gewählte Person gehört nicht zu diesem Projekt.',
            $_SESSION['error'] ?? null
        );
        $this->assertNull(Task::where('name', 'Fremde Zuweisung')->first());
    }

    public function testCreateRejectsAnUnknownAssignee(): void
    {
        $unknownId = ((int) User::max('id')) + 5000;

        $this->controller()->create(
            $this->makeRequest('POST', '/projects/' . $this->project->id . '/tasks', [
                'title' => 'Unbekannte Zuweisung',
                'assigned_user_ids' => [(string) $unknownId],
            ]),
            $this->makeResponse(),
            ['project_id' => (string) $this->project->id]
        );

        $this->assertSame(
            'Mindestens eine gewählte Person gehört nicht zu diesem Projekt.',
            $_SESSION['error'] ?? null
        );
        $this->assertNull(Task::where('name', 'Unbekannte Zuweisung')->first());
    }

    public function testCreateAcceptsAProjectMember(): void
    {
        unset($_SESSION['error']);

        $this->controller()->create(
            $this->makeRequest('POST', '/projects/' . $this->project->id . '/tasks', [
                'title' => 'Gültige Zuweisung',
                'assigned_user_ids' => [(string) $this->member->id],
            ]),
            $this->makeResponse(),
            ['project_id' => (string) $this->project->id]
        );

        $task = Task::where('name', 'Gültige Zuweisung')->first();

        $this->assertNotNull($task);
        $this->assertSame([(int) $this->member->id], $this->assigneeIds($task));
        $this->assertNull($_SESSION['error'] ?? null);
    }

    public function testCreateAcceptsAnEmptyAssignee(): void
    {
        unset($_SESSION['error']);

        $this->controller()->create(
            $this->makeRequest('POST', '/projects/' . $this->project->id . '/tasks', [
                'title' => 'Ohne Zuweisung',
                'assigned_user_ids' => [],
            ]),
            $this->makeResponse(),
            ['project_id' => (string) $this->project->id]
        );

        $task = Task::where('name', 'Ohne Zuweisung')->first();

        $this->assertNotNull($task);
        $this->assertSame([], $this->assigneeIds($task));
        $this->assertNull($_SESSION['error'] ?? null);
    }

    public function testUpdateRejectsAnAssigneeOutsideTheProjectAndKeepsTheExistingOne(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Bestehende Aufgabe',
            'status' => 'Offen',
            'created_by' => $this->member->id,
        ]);
        $task->assignees()->sync([$this->member->id]);

        $response = $this->controller()->update(
            $this->makeRequest('POST', '/tasks/' . $task->id . '/update', [
                'title' => 'Bestehende Aufgabe',
                'assigned_user_ids' => [(string) $this->outsider->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'Mindestens eine gewählte Person gehört nicht zu diesem Projekt.',
            $_SESSION['error'] ?? null
        );

        $this->assertSame([(int) $this->member->id], $this->assigneeIds($task));
    }

    /**
     * @return list<int>
     */
    private function assigneeIds(Task $task): array
    {
        $ids = array_map('intval', $task->assignees()->pluck('users.id')->all());
        sort($ids);

        return $ids;
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

    private function createUser(string $prefix): User
    {
        return User::create([
            'first_name' => 'Aufgabe',
            'last_name' => 'Zuweisung',
            'email' => 'task.' . $prefix . '.' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }
}
