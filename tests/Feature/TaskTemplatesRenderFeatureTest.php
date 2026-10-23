<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\TaskController;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Util\PasswordHasher;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die beiden Aufgaben-Ansichten einmal wirklich rendern.
 *
 * Bis Lauf 51 fasste keine Prüfung diese Vorlagen anders an als über
 * `file_get_contents()` - UiControlsFeatureTest, TableUxFeatureTest und
 * TaskFeatureTest lesen den Quelltext und suchen Zeichenketten darin. Damit
 * wäre ein Fehler, den erst das Rendern zeigt, durch die ganze Suite gekommen:
 * eine Zuordnung, die der Controller nicht mitgibt, ein Feldname mit Tippfehler,
 * ein Konstrukt, das Twig 3 nicht kennt. Gemerkt hätte es erst jemand, der die
 * Seite aufruft.
 *
 * Aufgefallen ist die Lücke beim Umbau auf englische Statuswerte: Die vier
 * fast wortgleichen Kanban-Spalten wurden zu einer Schleife über
 * `Task::STATUSES`, und die Beschriftungen kommen jetzt aus Zuordnungen am
 * Modell. Genau so ein Umbau braucht einen Lauf durch das echte Twig.
 */
final class TaskTemplatesRenderFeatureTest extends TestCase
{
    private ?Project $project = null;
    private ?User $user = null;

    /** @var list<Task> */
    private array $tasks = [];

    /** @var array<string, mixed> */
    private array $originalSession = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->tasks as $task) {
            $task->assignees()->detach();
            $task->delete();
        }
        $this->tasks = [];

        $this->project?->users()->detach();
        $this->project?->delete();
        $this->project = null;

        $this->user?->delete();
        $this->user = null;

        $_SESSION = $this->originalSession;

        parent::tearDown();
    }

    /**
     * Je Zustand eine Aufgabe, damit jede Kanban-Spalte eine Karte trägt und
     * keine über den Platzhalter-Zweig läuft.
     */
    private function seedProjectWithOneTaskPerStatus(): void
    {
        $this->project = Project::create([
            'name' => 'Aufgaben-Rendern ' . bin2hex(random_bytes(4)),
            'description' => 'Projekt für den Render-Lauf',
        ]);

        $this->user = User::create([
            'first_name' => 'Rita',
            'last_name' => 'Rendert',
            'email' => 'task.render.' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => 1,
        ]);

        $this->project->users()->attach($this->user->id);

        foreach (Task::STATUSES as $index => $status) {
            $task = Task::create([
                'project_id' => $this->project->id,
                'name' => 'Aufgabe ' . $status,
                'description' => '<p>Beschreibung</p>',
                'status' => $status,
                'priority' => Task::PRIORITIES[$index % count(Task::PRIORITIES)],
                'created_by' => $this->user->id,
                'end_date' => '2026-12-01',
            ]);
            $task->assignees()->attach($this->user->id);
            $this->tasks[] = $task;
        }
    }

    private function buildTwig(): Twig
    {
        $containerBuilder = new ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        $container = $containerBuilder->build();
        $container->get(Capsule::class);

        return $container->get(Twig::class);
    }

    /**
     * Die Zuordnungen kommen aus TaskController::taskValueMaps() selbst, nicht
     * aus einer Kopie hier.
     *
     * Eine Kopie hätte die Vorlage mit allem versorgt, was sie braucht - auch
     * dann, wenn der Controller einen Eintrag vergisst. Genau das war in der
     * ersten Fassung dieses Tests so: Nahm man `status_empty_texts` aus
     * `taskValueMaps()` heraus, blieb er grün, und die Seite hätte im Betrieb
     * Spalten ohne Hinweis gezeigt. Jetzt scheitert er.
     *
     * @return array<string, mixed>
     */
    private function valueMaps(): array
    {
        $method = new ReflectionMethod(TaskController::class, 'taskValueMaps');

        /** @var array<string, mixed> $maps */
        $maps = $method->invoke((new ReflectionClass(TaskController::class))->newInstanceWithoutConstructor());

        return $maps;
    }

    /** Aufgabenliste und Kanban-Board: jede Spalte, jedes Abzeichen, jedes Label. */
    public function testTaskBoardRendersEveryColumnAndBadge(): void
    {
        $this->seedProjectWithOneTaskPerStatus();

        $_SESSION = ['user_id' => (int) $this->user->id, 'can_manage_tasks' => true];

        $tasks = Task::with(['assignees'])->where('project_id', $this->project->id)->get();

        $html = $this->buildTwig()->fetch('projects/tasks.twig', [
            'project' => $this->project,
            'tasks' => $tasks,
            'projectUsers' => $this->project->users()->get(),
            'success' => null,
            'error' => null,
        ] + $this->valueMaps());

        // Je Zustand eine Spalte - mit dem englischen Bezeichner als Ablagezone
        // und dem deutschen Label in der Überschrift.
        foreach (Task::STATUSES as $status) {
            self::assertStringContainsString(
                sprintf('data-drop-zone="%s"', $status),
                $html,
                sprintf('Die Kanban-Spalte für "%s" fehlt.', $status)
            );
            self::assertStringContainsString(Task::STATUS_LABELS[$status], $html);
            self::assertStringContainsString(Task::STATUS_EMPTY_TEXTS[$status], $html);
            self::assertStringContainsString(
                sprintf('<option value="%s"', $status),
                $html,
                sprintf('Das Auswahlfeld bietet "%s" nicht an.', $status)
            );
        }

        foreach (Task::PRIORITIES as $priority) {
            self::assertStringContainsString(Task::PRIORITY_LABELS[$priority], $html);
        }

        // Der alte deutsche Wortlaut darf nicht mehr als Wert im Markup stehen.
        self::assertStringNotContainsString('data-drop-zone="Offen"', $html);
        self::assertStringNotContainsString('data-drop-zone="In Bearbeitung"', $html);
        self::assertStringNotContainsString('value="Abgeschlossen"', $html);
    }

    /**
     * Ein überschrittenes Datum ist nur bei offener Arbeit eine Warnung. In der Spalte
     * "Abgeschlossen" stand es trotzdem rot - die Listenansicht nahm erledigte Aufgaben
     * schon aus, das Kanban-Board nicht. `data-past-due` steht an jedem überschrittenen
     * Datum, damit das Board die Warnung nach einem Zug ohne Neuladen anpassen kann.
     */
    public function testKanbanWarnsAboutPastDueDatesOnlyForOpenWork(): void
    {
        $this->seedProjectWithOneTaskPerStatus();
        foreach ($this->tasks as $task) {
            $task->update(['end_date' => '2020-01-15']);
        }

        $_SESSION = ['user_id' => (int) $this->user->id, 'can_manage_tasks' => true];

        $html = $this->buildTwig()->fetch('projects/tasks.twig', [
            'project' => $this->project,
            'tasks' => Task::with(['assignees'])->where('project_id', $this->project->id)->get(),
            'projectUsers' => $this->project->users()->get(),
            'success' => null,
            'error' => null,
        ] + $this->valueMaps());

        $board = substr($html, (int) strpos($html, 'id="kanban-board"'));

        self::assertSame(count(Task::STATUSES), substr_count($board, 'data-past-due'));
        self::assertSame(count(Task::STATUSES) - 1, substr_count($board, 'kanban-date-warning'));

        $doneCard = substr($board, (int) strpos($board, 'data-task-status="done"'));
        $next = strpos($doneCard, 'class="kanban-card"');
        $doneCard = $next === false ? $doneCard : substr($doneCard, 0, $next);
        self::assertStringContainsString('data-past-due', $doneCard);
        self::assertStringNotContainsString('kanban-date-warning', $doneCard);
    }

    /** Einzelansicht: Zustand, Dringlichkeit und der Knopf "Als erledigt markieren". */
    public function testTaskDetailRendersStatusAndPriority(): void
    {
        $this->seedProjectWithOneTaskPerStatus();

        $_SESSION = ['user_id' => (int) $this->user->id, 'can_manage_tasks' => true];

        $task = Task::with(['project', 'assignees', 'createdBy', 'comments.user', 'attachments', 'activities.user'])
            ->findOrFail($this->tasks[0]->id);

        $html = $this->buildTwig()->fetch('projects/task_detail.twig', [
            'task' => $task,
            'project' => $task->project,
            'projectUsers' => $this->project->users()->get(),
            'success' => null,
            'error' => null,
        ] + $this->valueMaps());

        self::assertStringContainsString(Task::STATUS_LABELS[$task->status], $html);
        self::assertStringContainsString(Task::PRIORITY_LABELS[$task->priority], $html);

        // Der Knopf "Als erledigt markieren" schickt den englischen Bezeichner.
        self::assertStringContainsString('name="status" value="done"', $html);
        self::assertStringNotContainsString('value="Abgeschlossen"', $html);

        // Das Auswahlfeld im Bearbeiten-Fenster kennt jeden Zustand.
        foreach (Task::STATUSES as $status) {
            self::assertStringContainsString(sprintf('<option value="%s"', $status), $html);
        }
    }

    /**
     * Eine leere Spalte zeigt ihren Hinweis, und der Hinweis steht zusätzlich als
     * `data-empty-text` an der Ablagezone - dort liest ihn
     * `public/js/kanban-sortable-init.js` ab, wenn es den Platzhalter nach einem
     * Zug neu anlegt.
     */
    public function testEmptyColumnCarriesItsHintOnTheDataAttribute(): void
    {
        $this->seedProjectWithOneTaskPerStatus();

        $_SESSION = ['user_id' => (int) $this->user->id, 'can_manage_tasks' => true];

        $html = $this->buildTwig()->fetch('projects/tasks.twig', [
            'project' => $this->project,
            'tasks' => collect([]),
            'projectUsers' => $this->project->users()->get(),
            'success' => null,
            'error' => null,
        ] + $this->valueMaps());

        foreach (Task::STATUSES as $status) {
            self::assertStringContainsString(
                sprintf('data-empty-text="%s"', Task::STATUS_EMPTY_TEXTS[$status]),
                $html,
                sprintf('Die Spalte "%s" gibt ihren Hinweis nicht an das JavaScript weiter.', $status)
            );
        }

        self::assertStringContainsString('kanban-empty-placeholder', $html);
    }
}
