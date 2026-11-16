<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskWriteService;
use App\Util\PasswordHasher;
use ArrayObject;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Der TaskController schreibt Aufgaben nicht selbst, sondern über den
 * TaskWriteService.
 *
 * Anlegen, Ändern, Kommentieren und Statuswechsel samt Verlaufseintrag standen
 * als Transaktionen im Controller. Die Regeln bewachen weiter TaskFeatureTest und
 * die übrigen Task*-Tests über den Controller; dieser Test hält nur fest, dass
 * der Controller den Dienst ruft.
 */
final class TaskWriteWiringFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    public function testStatusChangeGoesThroughTheWriteService(): void
    {
        $project = Project::create(['name' => 'Schreibdienst ' . bin2hex(random_bytes(4))]);
        $user = User::create([
            'first_name' => 'Task',
            'last_name' => 'Dienst',
            'email' => 'task.dienst.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
        $project->users()->attach($user->id);
        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Statusaufgabe',
            'status' => Task::STATUS_OPEN,
            'created_by' => $user->id,
        ]);
        $_SESSION = ['user_id' => $user->id, 'can_manage_tasks' => true];

        $calls = new ArrayObject();
        $service = new class ($calls) extends TaskWriteService {
            public function __construct(private readonly ArrayObject $calls)
            {
            }

            public function changeStatus(Task $task, string $newStatus, string $activityText, int $actorId): void
            {
                $this->calls[] = [(int) $task->id, $newStatus, $actorId];
                parent::changeStatus($task, $newStatus, $activityText, $actorId);
            }
        };
        $controller = $this->taskController([
            'view' => $this->createStub(Twig::class),
            'taskWriter' => $service,
        ]);

        $controller->updateStatus(
            $this->makeRequest(
                'POST',
                '/tasks/' . $task->id . '/status',
                ['status' => Task::STATUS_DONE, 'return_to' => 'task']
            ),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        self::assertSame(
            [[(int) $task->id, Task::STATUS_DONE, (int) $user->id]],
            $calls->getArrayCopy(),
            'Genau ein Aufruf, mit dem Akteur.'
        );
        self::assertSame(Task::STATUS_DONE, $task->fresh()->status);
        self::assertSame(
            1,
            Activity::where('entity_type', 'task')
                ->where('entity_id', $task->id)
                ->where('action', 'updated')
                ->count(),
            'Der Statuswechsel schreibt genau einen Verlaufseintrag.'
        );
    }
}
