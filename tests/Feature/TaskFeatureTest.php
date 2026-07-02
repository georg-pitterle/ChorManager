<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\TaskController;
use App\Models\Task;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use App\Models\Role;
use App\Middleware\RoleMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

class TaskFeatureTest extends TestCase
{
    private const INITIAL_MIGRATION_PATH = __DIR__ . '/../../db/migrations/20260314130000_initial.php';

    /**
     * Test task controller exists with all required methods
     */
    public function testTaskControllerStructureExists(): void
    {
        $this->assertTrue(class_exists(TaskController::class));
        $this->assertTrue(method_exists(TaskController::class, 'index'));
        $this->assertTrue(method_exists(TaskController::class, 'create'));
        $this->assertTrue(method_exists(TaskController::class, 'detail'));
        $this->assertTrue(method_exists(TaskController::class, 'update'));
        $this->assertTrue(method_exists(TaskController::class, 'delete'));
        $this->assertTrue(method_exists(TaskController::class, 'addComment'));
        $this->assertTrue(method_exists(TaskController::class, 'uploadAttachment'));
        $this->assertTrue(method_exists(TaskController::class, 'downloadAttachment'));
        $this->assertTrue(method_exists(TaskController::class, 'deleteAttachment'));
    }

    /**
     * Test all required models exist
     */
    public function testTaskModelsExist(): void
    {
        $this->assertTrue(class_exists(Task::class));
        $this->assertTrue(class_exists(Activity::class));
        $this->assertTrue(class_exists(Comment::class));
        $this->assertTrue(class_exists(Attachment::class));
    }

    /**
     * Test routes are properly defined in Routes.php
     */
    public function testTaskRoutesExist(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/{project_id:[0-9]+}/tasks'", $routesContent);
        $this->assertStringContainsString("'/tasks'", $routesContent);
        $this->assertStringContainsString("'/{id:[0-9]+}/comments'", $routesContent);
        $this->assertStringContainsString("'/{id:[0-9]+}/attachments'", $routesContent);
    }

    /**
     * Test task routes have proper middleware protection
     */
    public function testTaskRoutesHaveMiddleware(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertStringContainsString("->add(new RoleMiddleware", $routesContent);
        // Verify the task routes are gated by the dedicated task-management permission.
        $taskMiddlewareSignature = "new RoleMiddleware(requiresTaskManagement: true)";
        $this->assertStringContainsString($taskMiddlewareSignature, $routesContent);
        $this->assertGreaterThanOrEqual(2, substr_count($routesContent, $taskMiddlewareSignature));
    }

    public function testTaskControllerDoesNotGrantAccessByProjectMembershipAlone(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/TaskController.php');

        $this->assertStringNotContainsString("\$project->users()->where('users.id', \$userId)->exists()", $controllerContent);
        $this->assertStringContainsString('return $canManageTasks;', $controllerContent);
        $this->assertStringNotContainsString('can_manage_master_data', $controllerContent);
        $this->assertStringNotContainsString('can_manage_users', $controllerContent);
    }

    public function testTaskControllerSanitizesHtmlDescriptions(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/TaskController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString('use App\\Services\\HtmlSanitizer;', $controllerContent);
        $this->assertStringContainsString('private HtmlSanitizer $htmlSanitizer;', $controllerContent);
        $this->assertStringContainsString('$this->htmlSanitizer->sanitizeTaskHtml', $controllerContent);
        $this->assertGreaterThanOrEqual(2, substr_count($controllerContent, "'description'      => " . '$description'));
        $this->assertStringContainsString('$oldDescription = trim((string) $task->description);', $controllerContent);
        $this->assertStringContainsString("$" . "changes[] = 'Beschreibung aktualisiert';", $controllerContent);
    }

    public function testTaskControllerConsumesFlashMessagesInViews(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/TaskController.php');

        $this->assertIsString($controllerContent);
        $this->assertGreaterThanOrEqual(2, substr_count($controllerContent, "unset(" . '$' . "_SESSION['success'], " . '$' . "_SESSION['error']);"));
        $this->assertStringContainsString("'success'      => " . '$' . 'success,', $controllerContent);
        $this->assertStringContainsString("'error'        => " . '$' . 'error,', $controllerContent);
        $this->assertStringContainsString("'success' => " . '$' . 'success,', $controllerContent);
        $this->assertStringContainsString("'error'   => " . '$' . 'error,', $controllerContent);
    }

    public function testTaskDeleteAlsoRemovesAttachments(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/TaskController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("Attachment::where('entity_type', 'task')", $controllerContent);
        $this->assertStringContainsString("->where('entity_id', " . '$' . "taskId)", $controllerContent);
        $this->assertStringContainsString("->delete();", $controllerContent);
    }

    public function testProjectsTemplateShowsPlanningLinkOnlyWithTaskRelatedPermissions(): void
    {
        $templateContent = file_get_contents(dirname(__DIR__) . '/../templates/projects/index.twig');

        $this->assertStringContainsString(
            '{% if session.can_manage_tasks %}',
            $templateContent
        );
        $this->assertStringNotContainsString('session.can_manage_master_data', $templateContent);
        $this->assertStringNotContainsString('session.can_manage_users', $templateContent);
        $this->assertStringNotContainsString('project.id in userProjectIds', $templateContent);
    }

    public function testRoleMiddlewareTaskCheckHasNoMasterDataBypass(): void
    {
        $middlewareContent = file_get_contents(dirname(__DIR__) . '/../src/Middleware/RoleMiddleware.php');

        $this->assertStringContainsString(
            'if ($this->requiresTaskManagement && !$canManageTasks) {',
            $middlewareContent
        );
        $this->assertStringNotContainsString(
            'if ($this->requiresTaskManagement && !$canManageTasks && !$canManageUsers) {',
            $middlewareContent
        );
        $this->assertStringNotContainsString(
            'if ($this->requiresTaskManagement && !$canManageTasks && !$canManageUsers && !$canManageMasterData) {',
            $middlewareContent
        );
    }

    public function testTaskMiddlewareDeniesAccessWithoutTaskPermission(): void
    {
        $_SESSION = [
            'user_id' => 42,
            'can_manage_tasks' => false,
            'can_manage_users' => false,
            'can_manage_master_data' => true,
        ];

        $middleware = new RoleMiddleware(false, 0, false, false, false, false, false, false, true);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/tasks/1');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new SlimResponse(200);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTaskMiddlewareAllowsAccessWithTaskPermission(): void
    {
        $_SESSION = [
            'user_id' => 42,
            'can_manage_tasks' => true,
            'can_manage_users' => false,
            'can_manage_master_data' => false,
        ];

        $middleware = new RoleMiddleware(false, 0, false, false, false, false, false, false, true);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/tasks/1');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new SlimResponse(200);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTaskMiddlewareDeniesAccessForAdminWithoutTaskPermission(): void
    {
        $_SESSION = [
            'user_id' => 42,
            'can_manage_tasks' => false,
            'can_manage_users' => true,
            'can_manage_master_data' => false,
        ];

        $middleware = new RoleMiddleware(false, 0, false, false, false, false, false, false, true);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/tasks/1');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new SlimResponse(200);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Test RoleMiddleware has requiresTaskManagement support
     */
    public function testRoleMiddlewareHasTaskManagementParameter(): void
    {
        $reflection = new ReflectionClass(RoleMiddleware::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();

        // Should have 9 parameters (8 existing + 1 new requiresTaskManagement)
        $this->assertGreaterThanOrEqual(9, count($params));

        // Check for requiresTaskManagement parameter
        $paramNames = array_map(fn($p) => $p->getName(), $params);
        $this->assertContains('requiresTaskManagement', $paramNames);
    }

    /**
     * Test all required templates exist
     */
    public function testTaskTemplatesExist(): void
    {
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/projects/tasks.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/projects/task_detail.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/partials/comments.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/partials/attachments.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/partials/history.twig'));
    }

    public function testTaskTemplatesUseTinymceAndHtmlRenderingForDescription(): void
    {
        $tasksTemplate = file_get_contents(dirname(__DIR__) . '/../templates/projects/tasks.twig');
        $detailTemplate = file_get_contents(dirname(__DIR__) . '/../templates/projects/task_detail.twig');

        $this->assertIsString($tasksTemplate);
        $this->assertIsString($detailTemplate);
        $this->assertStringContainsString('tinymce-editor', $tasksTemplate);
        $this->assertStringContainsString('id="description"', $tasksTemplate);
        $this->assertStringContainsString('tinymce-editor', $detailTemplate);
        $this->assertStringContainsString('id="description"', $detailTemplate);
        $this->assertStringContainsString('{{ task.description|raw }}', $detailTemplate);
        $this->assertStringContainsString('task-description-html', $detailTemplate);
        $this->assertStringNotContainsString('{{ task.description|nl2br }}', $detailTemplate);
    }

    /**
     * Test Task model relationships
     */
    public function testTaskModelRelationships(): void
    {
        $task = new Task();

        $this->assertTrue(method_exists($task, 'project'));
        $this->assertTrue(method_exists($task, 'assignee'));
        $this->assertTrue(method_exists($task, 'createdBy'));
        $this->assertTrue(method_exists($task, 'comments'));
        $this->assertTrue(method_exists($task, 'attachments'));
        $this->assertTrue(method_exists($task, 'activities'));
    }

    /**
     * Test Project model has task relationships
     */
    public function testProjectModelHasTaskRelationships(): void
    {
        $project = new Project();

        $this->assertTrue(method_exists($project, 'tasks'));
        $this->assertTrue(method_exists($project, 'comments'));
        $this->assertTrue(method_exists($project, 'attachments'));
    }

    /**
     * Test User model has task relationships
     */
    public function testUserModelHasTaskRelationships(): void
    {
        $user = new User();

        $this->assertTrue(method_exists($user, 'tasks'));
        $this->assertTrue(method_exists($user, 'comments'));
        $this->assertTrue(method_exists($user, 'activities'));
    }

    /**
     * Test Activity model has user relationship
     */
    public function testActivityModelHasUserRelationship(): void
    {
        $activity = new Activity();
        $this->assertTrue(method_exists($activity, 'user'));
    }

    /**
     * Test Comment model has user relationship
     */
    public function testCommentModelHasUserRelationship(): void
    {
        $comment = new Comment();
        $this->assertTrue(method_exists($comment, 'user'));
    }

    /**
     * Test database tables exist in migration
     */
    public function testDatabaseMigrationTablesAreCreated(): void
    {
        $migrationContent = file_get_contents(self::INITIAL_MIGRATION_PATH);

        // Check attachment table
        $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS attachments", $migrationContent);
        $this->assertStringContainsString("entity_type varchar(50) NOT NULL", $migrationContent);
        $this->assertStringContainsString("entity_id int(11) NOT NULL", $migrationContent);

        // Check comments table
        $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS comments", $migrationContent);
        $this->assertStringContainsString("CONSTRAINT comments_user_fk", $migrationContent);

        // Check tasks table
        $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS tasks", $migrationContent);
        $this->assertStringContainsString("CONSTRAINT tasks_project_fk", $migrationContent);
        $this->assertStringContainsString("CONSTRAINT tasks_creator_fk", $migrationContent);

        // Check activities table
        $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS activities", $migrationContent);
        $this->assertStringContainsString("CONSTRAINT activities_user_fk", $migrationContent);
    }

    /**
     * Test database migration has proper indexes
     */
    public function testDatabaseMigrationHasIndexes(): void
    {
        $migrationContent = file_get_contents(self::INITIAL_MIGRATION_PATH);

        // Check attachment indexes
        $this->assertStringContainsString("KEY entity_idx (entity_type, entity_id)", $migrationContent);
        $this->assertStringContainsString("KEY created_at_idx (created_at)", $migrationContent);

        // Check task indexes for query optimization
        $this->assertStringContainsString("KEY project_idx (project_id)", $migrationContent);
        $this->assertStringContainsString("KEY assigned_to_idx (assigned_to)", $migrationContent);
        $this->assertStringContainsString("KEY status_idx (status)", $migrationContent);
    }

    /**
     * Test Task enum constraints are defined
     */
    public function testTaskEnumConstraintsAreDefined(): void
    {
        $migrationContent = file_get_contents(self::INITIAL_MIGRATION_PATH);

        // Check status enum
        $this->assertStringContainsString("status ENUM('Offen','In Bearbeitung','Abgeschlossen','Blockiert')", $migrationContent);

        // Check priority enum
        $this->assertStringContainsString("priority ENUM('Niedrig','Mittel','Hoch')", $migrationContent);
    }

    /**
     * Test Session auth service sets task permissions
     */
    public function testSessionAuthServiceSetsCoreTaskPermissions(): void
    {
        $serviceContent = file_get_contents(dirname(__DIR__) . '/../src/Services/SessionAuthService.php');

        // Check that can_manage_tasks is initialized 
        $this->assertStringContainsString('$canManageTasks = false', $serviceContent);
        // Check that can_manage_tasks is set in role loop
        $this->assertStringContainsString('$canManageTasks = true', $serviceContent);
        // Check that can_manage_tasks is set in session
        $this->assertStringContainsString("'can_manage_tasks'", $serviceContent);
        // Check role permission check
        $this->assertStringContainsString('if ($role->can_manage_tasks)', $serviceContent);
    }

    /**
     * Test migration handles data consolidation
     */
    public function testMigrationConsolidatesAttachmentTables(): void
    {
        $migrationContent = file_get_contents(self::INITIAL_MIGRATION_PATH);

        // Initial migration should define generic attachments as consolidated target.
        $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS attachments", $migrationContent);
        $this->assertStringContainsString("entity_type varchar(50) NOT NULL", $migrationContent);
        $this->assertStringContainsString("entity_id int(11) NOT NULL", $migrationContent);
    }

    /**
     * Test Role model has can_manage_tasks field
     */
    public function testRoleMigrationAddsCoreTaskPermissionField(): void
    {
        $migrationContent = file_get_contents(self::INITIAL_MIGRATION_PATH);

        // Check role permission is part of baseline schema and seeded roles.
        $this->assertStringContainsString("can_manage_tasks tinyint(1) NOT NULL DEFAULT 0", $migrationContent);
        $this->assertStringContainsString("(1,'Admin',          100, 1,1,1, 1,1, 1,1,1,1,1)", $migrationContent);
    }

    /**
     * Test controller has enum validation methods
     */
    public function testTaskControllerHasEnumValidationMethods(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/TaskController.php');

        // Check validation function for status
        $this->assertStringContainsString("validateStatus", $controllerContent);
        $this->assertStringContainsString("'Offen'", $controllerContent);
        $this->assertStringContainsString("'In Bearbeitung'", $controllerContent);
        $this->assertStringContainsString("'Abgeschlossen'", $controllerContent);
        $this->assertStringContainsString("'Blockiert'", $controllerContent);

        // Check validation function for priority
        $this->assertStringContainsString("validatePriority", $controllerContent);
        $this->assertStringContainsString("'Niedrig'", $controllerContent);
        $this->assertStringContainsString("'Mittel'", $controllerContent);
        $this->assertStringContainsString("'Hoch'", $controllerContent);
    }

    /**
     * Test task index loads comment counts
     */
    public function testTaskIndexLoadsCommentCounts(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/TaskController.php');

        // Check withCount for comments
        $this->assertStringContainsString("withCount('comments')", $controllerContent);
    }

    /**
     * Test dev seed includes task-related tables
     */
    public function testDevSeedServiceHandlesTaskTables(): void
    {
        $seedContent = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');

        // Check seed clears task-related tables
        $this->assertStringContainsString("'tasks'", $seedContent);
        $this->assertStringContainsString("'comments'", $seedContent);
        $this->assertStringContainsString("'activities'", $seedContent);
        $this->assertStringContainsString("'attachments'", $seedContent);

        // Check task seed methods are wired in run flow
        $this->assertStringContainsString("'task_activities' => 0", $seedContent);
        $this->assertStringContainsString("'task_comments' => 0", $seedContent);
        $this->assertStringContainsString("'task_attachments' => 0", $seedContent);
        $this->assertStringContainsString('$tasks = $this->seedTasks($projects, $users[\'active\']);', $seedContent);
        $this->assertStringContainsString('$this->seedTaskActivities($tasks, $users[\'active\']);', $seedContent);
        $this->assertStringContainsString('$this->seedTaskComments($tasks, $users[\'active\']);', $seedContent);
        $this->assertStringContainsString('$this->seedTaskAttachments($tasks, 40);', $seedContent);
    }

    /**
     * Test updateStatus method exists on TaskController
     */
    public function testTaskControllerHasUpdateStatusMethod(): void
    {
        $this->assertTrue(method_exists(TaskController::class, 'updateStatus'));
    }

    /**
     * Test updateStatus method validates status input and returns JSON
     */
    public function testUpdateStatusMethodValidatesAndReturnsJson(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/TaskController.php');

        $this->assertStringContainsString('updateStatus', $controllerContent);
        $this->assertStringContainsString("'Content-Type', 'application/json'", $controllerContent);
        $this->assertStringContainsString("'success' => true", $controllerContent);
        $this->assertStringContainsString("'success' => false", $controllerContent);
        $this->assertStringContainsString('validateStatus', $controllerContent);

        // Must NOT use DB facade (no facade application set in this app)
        $this->assertStringNotContainsString('Illuminate\\Support\\Facades\\DB', $controllerContent);
        // Must use Capsule::connection()->transaction() pattern
        $this->assertStringContainsString('Capsule::connection()->transaction(', $controllerContent);
    }

    // Test für alte Kanban-Drag&Drop-Logik entfernt, da SortableJS verwendet wird.
}
