<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Attachment;
use App\Models\User;
use App\Services\HtmlSanitizer;
use App\Util\UploadValidator;
use App\Policies\TaskPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TaskController
{
    private Twig $view;
    private HtmlSanitizer $htmlSanitizer;
    private TaskPolicy $policy;

    public function __construct(Twig $view, HtmlSanitizer $htmlSanitizer, TaskPolicy $policy)
    {
        $this->view = $view;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->policy = $policy;
    }

    private function validateStatus(string $status): string
    {
        $validStatuses = ['Offen', 'In Bearbeitung', 'Abgeschlossen', 'Blockiert'];
        return in_array($status, $validStatuses, true) ? $status : 'Offen';
    }

    private function validatePriority(string $priority): string
    {
        $validPriorities = ['Niedrig', 'Mittel', 'Hoch'];
        return in_array($priority, $validPriorities, true) ? $priority : 'Mittel';
    }

    private function hasTaskAccess(Project $project): bool
    {
        $canManageTasks = $this->policy->canManageTasks((int) $project->id);

        return $canManageTasks;
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        $projectId = (int) $args['project_id'];
        $project = Project::findOrFail($projectId);

        if (!$this->hasTaskAccess($project)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $tasks = $project->tasks()
            ->with(['assignee', 'createdBy'])
            ->withCount('comments')
            ->orderBy('end_date', 'asc')
            ->get();

        $projectUsers = $project->users()->orderBy('first_name')->get();
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'projects/tasks.twig', [
            'project'      => $project,
            'tasks'        => $tasks,
            'projectUsers' => $projectUsers,
            'success'      => $success,
            'error'        => $error,
        ]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $taskId = (int) $args['id'];
        $task = Task::with(['project', 'assignee', 'createdBy', 'comments.user', 'attachments', 'activities.user'])
            ->findOrFail($taskId);

        if (!$this->hasTaskAccess($task->project)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);
        $task->description = $this->htmlSanitizer->sanitizeTaskHtml((string) $task->description);

        return $this->view->render($response, 'projects/task_detail.twig', [
            'task'    => $task,
            'project' => $task->project,
            'projectUsers' => $task->project->users()->orderBy('first_name')->get(),
            'success' => $success,
            'error'   => $error,
        ]);
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $projectId = (int) $args['project_id'];
        $project = Project::findOrFail($projectId);

        if (!$this->hasTaskAccess($project)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $title = trim($data['title'] ?? '');

        // Validate required fields
        if (empty($title)) {
            $_SESSION['error'] = 'Aufgabentitel erforderlich.';
            return $response->withHeader('Location', "/projects/{$projectId}/tasks")->withStatus(302);
        }

        if (strlen($title) > 255) {
            $_SESSION['error'] = 'Aufgabentitel darf maximal 255 Zeichen lang sein.';
            return $response->withHeader('Location', "/projects/{$projectId}/tasks")->withStatus(302);
        }

        $description = $this->htmlSanitizer->sanitizeTaskHtml($data['description'] ?? '');

        // Parse and validate dates
        try {
            $startDate = !empty($data['start_date']) ? Carbon::parse($data['start_date'])->toDateString() : null;
            $endDate = !empty($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : null;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Ungültiges Datumsformat. Verwenden Sie das Format YYYY-MM-DD.';
            return $response->withHeader('Location', "/projects/{$projectId}/tasks")->withStatus(302);
        }

        DB::transaction(function () use ($project, $data, $title, $description, $startDate, $endDate) {
            $task = Task::create([
                'project_id'       => $project->id,
                'name'             => $title,
                'description'      => $description,
                'assigned_to'      => !empty($data['assigned_user_id']) ? (int) $data['assigned_user_id'] : null,
                'created_by'       => $_SESSION['user_id'],
                'start_date'       => $startDate,
                'end_date'         => $endDate,
                'status'           => $this->validateStatus($data['status'] ?? 'Offen'),
                'priority'         => $this->validatePriority($data['priority'] ?? 'Mittel'),
            ]);

            Activity::create([
                'entity_type' => 'task',
                'entity_id'   => $task->id,
                'user_id'     => $_SESSION['user_id'],
                'action'      => 'created',
                'description' => 'Aufgabe erstellt.',
            ]);
        });

        $_SESSION['success'] = 'Aufgabe erfolgreich erstellt.';
        return $response->withHeader('Location', "/projects/{$projectId}/tasks")->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $taskId = (int) $args['id'];
        $task = Task::findOrFail($taskId);

        if (!$this->hasTaskAccess($task->project)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $title = trim($data['title'] ?? $task->name);

        // Validate required fields
        if (empty($title)) {
            $_SESSION['error'] = 'Aufgabentitel erforderlich.';
            return $response->withHeader('Location', "/tasks/{$task->id}")->withStatus(302);
        }

        if (strlen($title) > 255) {
            $_SESSION['error'] = 'Aufgabentitel darf maximal 255 Zeichen lang sein.';
            return $response->withHeader('Location', "/tasks/{$task->id}")->withStatus(302);
        }

        $oldStatus = $task->status;
        $oldPriority = $task->priority;
        $oldAssigned = $task->assigned_to;
        $oldDescription = trim((string) $task->description);
        $descriptionInput = array_key_exists('description', $data) ? (string) $data['description'] : $task->description;
        $description = $this->htmlSanitizer->sanitizeTaskHtml($descriptionInput);

        // Parse and validate dates
        try {
            $startDate = !empty($data['start_date']) ? Carbon::parse($data['start_date'])->toDateString() : null;
            $endDate = !empty($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : null;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Ungültiges Datumsformat. Verwenden Sie das Format YYYY-MM-DD.';
            return $response->withHeader('Location', "/tasks/{$task->id}")->withStatus(302);
        }

        DB::transaction(function () use ($task, $data, $title, $description, $startDate, $endDate, $oldStatus, $oldPriority, $oldAssigned, $oldDescription) {
            $task->update([
                'name'             => $title,
                'description'      => $description,
                'assigned_to'      => !empty($data['assigned_user_id']) ? (int) $data['assigned_user_id'] : null,
                'start_date'       => $startDate,
                'end_date'         => $endDate,
                'status'           => $this->validateStatus($data['status'] ?? $task->status),
                'priority'         => $this->validatePriority($data['priority'] ?? $task->priority),
            ]);

            // Changes logging
            $changes = [];
            if ($oldStatus !== $task->status) {
                $changes[] = "Status von '$oldStatus' auf '{$task->status}' geändert";
            }
            if ($oldPriority !== $task->priority) {
                $changes[] = "Priorität von '$oldPriority' auf '{$task->priority}' geändert";
            }
            if ($oldAssigned !== $task->assigned_to) {
                $assignedUser = $task->assigned_to ? User::find($task->assigned_to) : null;
                $newUserName = $assignedUser ? $assignedUser->first_name : 'Niemanden';
                $changes[] = "Zugewiesen an: $newUserName";
            }
            if ($oldDescription !== $description) {
                $changes[] = 'Beschreibung aktualisiert';
            }

            if (count($changes) > 0) {
                Activity::create([
                    'entity_type' => 'task',
                    'entity_id'   => $task->id,
                    'user_id'     => $_SESSION['user_id'],
                    'action'      => 'updated',
                    'description' => implode(', ', $changes),
                ]);
            }
        });

        $_SESSION['success'] = 'Aufgabe erfolgreich aktualisiert.';
        return $response->withHeader('Location', "/tasks/{$task->id}")->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $taskId = (int) $args['id'];
        $task = Task::findOrFail($taskId);

        if (!$this->hasTaskAccess($task->project)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $projectId = $task->project_id;
        Attachment::where('entity_type', 'task')
            ->where('entity_id', $taskId)
            ->delete();
        $task->delete();

        $_SESSION['success'] = 'Aufgabe erfolgreich gelöscht.';
        return $response->withHeader('Location', "/projects/{$projectId}/tasks")->withStatus(302);
    }

    public function addComment(Request $request, Response $response, array $args): Response
    {
        $taskId = (int) $args['id'];
        $task = Task::findOrFail($taskId);

        if (!$this->hasTaskAccess($task->project)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $content = trim($data['content'] ?? '');

        if ($content !== '') {
            DB::transaction(function () use ($task, $content) {
                Comment::create([
                    'entity_type' => 'task',
                    'entity_id'   => $task->id,
                    'user_id'     => $_SESSION['user_id'],
                    'comment'     => $content,
                ]);

                Activity::create([
                    'entity_type' => 'task',
                    'entity_id'   => $task->id,
                    'user_id'     => $_SESSION['user_id'],
                    'action'      => 'commented',
                    'description' => 'Neuer Kommentar hinzugefügt.',
                ]);
            });

            $_SESSION['success'] = 'Kommentar hinzugefügt.';
        }

        return $response->withHeader('Location', "/tasks/{$task->id}")->withStatus(302);
    }

    public function uploadAttachment(Request $request, Response $response, array $args): Response
    {
        $taskId = (int) $args['id'];
        $task = Task::findOrFail($taskId);

        if (!$this->hasTaskAccess($task->project)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $files = $request->getUploadedFiles()['attachments'] ?? [];
        if (!is_array($files)) {
            $files = [$files];
        }

        $uploadedCount = 0;
        $errors = [];

        foreach ($files as $file) {
            $uploadError = UploadValidator::getUploadErrorMessage($file->getError(), 'Anhang');
            if ($uploadError !== null) {
                $errors[] = $uploadError;
                continue;
            }

            if ($file->getError() === UPLOAD_ERR_OK) {
                $mimeType = trim((string) $file->getClientMediaType());
                $contents = $file->getStream()->getContents();
                $size = strlen($contents);

                $validation = UploadValidator::validateFileSize($size, $mimeType);
                if (!$validation['valid']) {
                    $errors[] = $validation['error'];
                    continue;
                }

                Attachment::create([
                    'entity_type'   => 'task',
                    'entity_id'     => $task->id,
                    'filename'      => bin2hex(random_bytes(16)) . '_' . $file->getClientFilename(),
                    'original_name' => $file->getClientFilename(),
                    'mime_type'     => UploadValidator::normalizeMimeType($mimeType),
                    'file_size'     => $size,
                    'file_content'  => $contents,
                ]);
                $uploadedCount++;
            }
        }

        // Handle errors and success
        if (count($errors) > 0) {
            $_SESSION['error'] = implode('; ', $errors);
        }

        if ($uploadedCount > 0) {
            Activity::create([
                'entity_type' => 'task',
                'entity_id'   => $task->id,
                'user_id'     => $_SESSION['user_id'],
                'action'      => 'attachment_added',
                'description' => "$uploadedCount Anhang/Anhänge hinzugefügt.",
            ]);
            $_SESSION['success'] = 'Anhänge hochgeladen.';
        }

        return $response->withHeader('Location', "/tasks/{$task->id}")->withStatus(302);
    }

    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        $taskId = (int) $args['id'];
        $attachmentId = (int) $args['attachment_id'];
        $task = Task::findOrFail($taskId);

        if (!$this->hasTaskAccess($task->project)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $attachment = Attachment::where('entity_type', 'task')
            ->where('entity_id', $taskId)
            ->findOrFail($attachmentId);

        $attachment->delete();

        Activity::create([
            'entity_type' => 'task',
            'entity_id'   => $task->id,
            'user_id'     => $_SESSION['user_id'],
            'action'      => 'attachment_removed',
            'description' => 'Ein Anhang wurde gelöscht.',
        ]);

        $_SESSION['success'] = 'Anhang gelöscht.';
        return $response->withHeader('Location', "/tasks/{$task->id}")->withStatus(302);
    }

    public function downloadAttachment(Request $request, Response $response, array $args): Response
    {
        $taskId = (int) $args['id'];
        $attachmentId = (int) $args['attachment_id'];
        $task = Task::findOrFail($taskId);

        if (!$this->policy->canManageTasks($task->project_id)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $attachment = Attachment::where('entity_type', 'task')
            ->where('entity_id', $taskId)
            ->findOrFail($attachmentId);

        $safeName = self::normalizeFileName((string) $attachment->original_name);
        $response->getBody()->write($attachment->file_content);
        return $response
            ->withHeader('Content-Type', $attachment->mime_type)
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
            );
    }

    private static function normalizeFileName(string $name): string
    {
        $safe = str_replace(["\r", "\n", '"', '\\', '/'], '_', $name);
        $trimmed = trim($safe);
        return $trimmed !== '' ? $trimmed : 'download';
    }
}
