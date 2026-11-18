<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\TaskController;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Policies\NewsletterPolicy;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Services\AttachmentAccessRegistry;
use App\Util\PasswordHasher;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Antwort 1C aus Review-Lauf 78: Wem eine Aufgabe zugewiesen ist, der darf
 * ihre Seite öffnen - auch ohne das Recht "Aufgaben verwalten".
 *
 * Vorher hing die ganze Aufgabenseite am Recht, während das Kalender-Abo jedem
 * Zugewiesenen seine Aufgaben schickte. Der Link im Kalendereintrag führte für
 * diese Personen ins Leere.
 *
 * Geöffnet wird nur das Lesen. Ändern, Abhaken, Kommentieren, Hochladen und
 * Löschen bleiben am Recht; auf der Seite sind sie für die lesende Person
 * deshalb gar nicht erst zu sehen.
 */
final class TaskDetailForAssigneeFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    private Project $project;
    private User $assignee;
    private User $stranger;
    private Task $task;

    /** @var array<string, mixed> */
    private array $originalSession = [];

    /**
     * Aufgeräumt wird gezielt, nicht über eine Transaktion.
     *
     * render() baut sich für das echte Twig einen eigenen Container, und der
     * richtet die Datenbankverbindung neu ein. Das `rollBack()` im tearDown
     * griffe danach auf eine Verbindung ohne offene Transaktion - die
     * Fixtures blieben stehen, und der Lecktest der Suite fände sie. Dieselbe
     * Bauart wie in tests/Feature/TaskTemplatesRenderFeatureTest.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];

        $suffix = bin2hex(random_bytes(4));

        $this->project = Project::create([
            'name' => 'Sommerkonzert ' . $suffix,
            'description' => 'Projekt für den Zugriffstest',
        ]);

        $this->assignee = User::create([
            'email' => 'zustaendig.' . $suffix . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Zara',
            'last_name' => 'Zuständig',
            'is_active' => 1,
        ]);

        $this->stranger = User::create([
            'email' => 'fremd.' . $suffix . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Franz',
            'last_name' => 'Fremd',
            'is_active' => 1,
        ]);

        $this->project->users()->attach([$this->assignee->id, $this->stranger->id]);

        $this->task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Programmhefte abholen',
            'description' => '<p>Bei der Druckerei in der Innenstadt.</p>',
            'status' => Task::STATUS_OPEN,
            'priority' => Task::PRIORITY_MEDIUM,
            'end_date' => '2026-12-01',
            'created_by' => $this->stranger->id,
        ]);
        $this->task->assignees()->attach($this->assignee->id);
    }

    protected function tearDown(): void
    {
        $this->task->assignees()->detach();
        $this->task->delete();
        $this->project->users()->detach();
        $this->project->delete();
        $this->assignee->delete();
        $this->stranger->delete();

        $_SESSION = $this->originalSession;

        parent::tearDown();
    }

    public function testTheAssigneeReachesTheTaskWithoutTheManagementRight(): void
    {
        $_SESSION = ['user_id' => (int) $this->assignee->id];

        $response = $this->detail();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Location'));
    }

    public function testSomebodyWithoutTheRightAndWithoutTheTaskIsTurnedAway(): void
    {
        $_SESSION = ['user_id' => (int) $this->stranger->id];

        $response = $this->detail();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/dashboard', $response->getHeaderLine('Location'));
        $this->assertSame('Zugriff verweigert.', $_SESSION['error'] ?? null);
    }

    /**
     * Projektmitgliedschaft allein genügt nicht - beide Personen gehören zum
     * Projekt, nur eine trägt die Aufgabe.
     */
    public function testTheManagementRightStillOpensEveryTask(): void
    {
        $_SESSION = ['user_id' => (int) $this->stranger->id, 'can_manage_tasks' => true];

        $this->assertSame(200, $this->detail()->getStatusCode());
    }

    /**
     * Die Seite darf der lesenden Person nichts anbieten, was ihre Rechte nicht
     * hergeben: Jeder dieser Wege endet sonst an der Middleware.
     */
    public function testThePageOffersNoWriteActionToAReadingAssignee(): void
    {
        $html = $this->render(canManageTasks: false);

        $this->assertStringContainsString('Programmhefte abholen', $html);
        $this->assertStringContainsString('Bei der Druckerei in der Innenstadt.', $html);

        foreach (['/status', '/update', '/delete', '/comments', '/attachments'] as $writePath) {
            $this->assertStringNotContainsString(
                '/tasks/' . $this->task->id . $writePath,
                $html,
                'Die Seite bietet einen Schreibweg an, den die lesende Person nicht gehen darf: ' . $writePath
            );
        }

        // Die Brotkrume führte auf die Aufgabenliste des Projekts, und auch die
        // hängt am Recht.
        $this->assertStringNotContainsString('/projects/' . $this->project->id . '/tasks', $html);
        $this->assertStringContainsString($this->project->name, $html);
    }

    /**
     * Gegenprobe: Mit dem Recht steht alles wie bisher auf der Seite. Ohne sie
     * bliebe unbemerkt, wenn die Bedingung die Knöpfe für alle verschluckt.
     */
    public function testWithTheRightEveryActionIsStillThere(): void
    {
        $html = $this->render(canManageTasks: true);

        foreach (['/status', '/update', '/delete', '/comments', '/attachments'] as $writePath) {
            $this->assertStringContainsString('/tasks/' . $this->task->id . $writePath, $html);
        }

        $this->assertStringContainsString('/projects/' . $this->project->id . '/tasks', $html);
    }

    /**
     * Wer die Aufgabe lesen darf, darf auch ihre Anhänge laden.
     *
     * Sonst wäre die Öffnung nur halb: Die Seite listet die Dateien auf, und
     * jeder Klick darauf endete an der Auslieferungsroute. Dieselbe Frage wie
     * oben, nur eine Ebene tiefer - deshalb dieselbe Antwort.
     */
    public function testTheAssigneeMayReadTheAttachmentsOfTheirTask(): void
    {
        $_SESSION = ['user_id' => (int) $this->assignee->id];
        $this->assertTrue($this->registry()->mayAccess($this->taskAttachment()));

        $_SESSION = ['user_id' => (int) $this->stranger->id];
        $this->assertFalse($this->registry()->mayAccess($this->taskAttachment()));

        $_SESSION = ['user_id' => (int) $this->stranger->id, 'can_manage_tasks' => true];
        $this->assertTrue($this->registry()->mayAccess($this->taskAttachment()));
    }

    /**
     * Das Modul bleibt die äußere Schranke: Ist die Aufgabenverwaltung aus,
     * kommt auch die zuständige Person nicht an den Anhang.
     */
    public function testTheModuleStillSealsTheAttachmentsOfAnAssignee(): void
    {
        $_SESSION = ['user_id' => (int) $this->assignee->id];

        $this->assertFalse($this->registry(['tasks' => false])->mayAccess($this->taskAttachment()));
    }

    /**
     * Die Detailseite liegt nicht mehr in der Gruppe mit dem Rollen-Gate - die
     * Entscheidung fällt im Controller, weil sie an der einzelnen Aufgabe
     * hängt. Die schreibenden Wege bleiben dort, wo sie waren.
     */
    public function testOnlyTheWritingTaskRoutesCarryTheRoleGate(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertStringContainsString(
            "\$group->get('/tasks/{id:[0-9]+}', [TaskController::class, 'detail']);",
            $routes
        );
        $this->assertStringNotContainsString(
            "\$taskGroup->get('/{id:[0-9]+}', [TaskController::class, 'detail']);",
            $routes
        );
        $this->assertStringContainsString("new RoleMiddleware(gates: ['requiresTaskManagement'])", $routes);
    }

    /**
     * @param array<string, bool> $modules
     */
    private function registry(array $modules = []): AttachmentAccessRegistry
    {
        return new AttachmentAccessRegistry(
            new SponsoringPolicy($_SESSION),
            new TaskPolicy($_SESSION),
            array_merge(['finance' => true, 'sponsoring' => true, 'tasks' => true, 'newsletter' => true], $modules),
            new NewsletterPolicy($_SESSION),
            $_SESSION
        );
    }

    private function taskAttachment(): Attachment
    {
        $attachment = new Attachment();
        $attachment->entity_type = 'task';
        $attachment->entity_id = (int) $this->task->id;

        return $attachment;
    }

    /**
     * Ruft die Detailseite auf. Die Vorlage wird dabei nicht gerendert - das
     * tut render() weiter unten -, der Platzhalter reicht aber die Antwort
     * durch, damit sich ein Durchlassen (200) von einer Umleitung (302)
     * unterscheiden lässt. Ein blanker Stub gäbe für beides 0 zurück.
     */
    private function detail(): \Psr\Http\Message\ResponseInterface
    {
        $view = $this->createStub(Twig::class);
        $view->method('render')->willReturnCallback(
            static fn (\Psr\Http\Message\ResponseInterface $response): \Psr\Http\Message\ResponseInterface
                => $response->withStatus(200)
        );

        $controller = $this->taskController(['view' => $view]);

        return $controller->detail(
            $this->makeRequest('GET', '/tasks/' . $this->task->id),
            $this->makeResponse(),
            ['id' => (string) $this->task->id]
        );
    }

    /**
     * Die Vorlage einmal wirklich rendern - eine Bedingung, die nur im
     * Quelltext gesucht wird, kann im Twig trotzdem falsch herum stehen.
     */
    private function render(bool $canManageTasks): string
    {
        $_SESSION = ['user_id' => (int) $this->assignee->id];
        if ($canManageTasks) {
            $_SESSION['can_manage_tasks'] = true;
        }

        $task = Task::with(['project', 'assignees', 'createdBy', 'comments.user', 'attachments', 'activities.user'])
            ->findOrFail($this->task->id);

        return $this->buildTwig()->fetch('projects/task_detail.twig', [
            'task' => $task,
            'project' => $task->project,
            'projectUsers' => $this->project->users()->get(),
            'can_manage_tasks' => $canManageTasks,
            'success' => null,
            'error' => null,
        ] + $this->valueMaps());
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
     * Wie in TaskTemplatesRenderFeatureTest: Die Zuordnungen kommen aus dem
     * Controller selbst, nicht aus einer Kopie, die einen vergessenen Eintrag
     * verdecken würde.
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
}
