<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Attachment;
use App\Models\User;
use App\Services\CalendarFeedService;
use App\Services\CalendarSubscriptionService;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use App\Services\NotificationService;
use App\Util\AppUrlResolver;
use App\Util\NotificationType;
use App\Util\UploadValidator;
use App\Policies\TaskPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Util\DownloadFileName;

class TaskController
{
    private Twig $view;
    private HtmlSanitizer $htmlSanitizer;
    private TaskPolicy $policy;
    private NameFormatterService $nameFormatter;
    private LoggerInterface $logger;
    private ?NotificationService $notificationService;

    /**
     * `$notificationService` steht am Ende und ist optional, weil zahlreiche
     * Tests diesen Controller mit festen Positionsargumenten bauen - ein
     * Parameter in der Mitte hätte sie stumm auf die falschen Werte gesetzt.
     *
     * Optional heißt hier aber nicht "darf fehlen": PHP-DI füllt optionale
     * Parameter nicht aus dem Container, deshalb reicht ihn die ausdrückliche
     * Registrierung in `Dependencies.php` durch. Ohne sie verschickte der
     * Betrieb still keine Benachrichtigungen - dagegen steht
     * `NotificationWiringFeatureTest`.
     */
    public function __construct(
        Twig $view,
        HtmlSanitizer $htmlSanitizer,
        TaskPolicy $policy,
        NameFormatterService $nameFormatter,
        LoggerInterface $logger,
        ?NotificationService $notificationService = null
    ) {
        $this->view = $view;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->policy = $policy;
        $this->nameFormatter = $nameFormatter;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
    }

    /**
     * Der eigene Aufgaben-Feed. Öffentlich erreichbar wie der Termin-Feed und
     * über denselben Token abgesichert: Ein zweites Geheimnis mit eigener
     * Erneuerung hätte bedeutet, dass ein zurückgezogenes Abo nur eine Hälfte
     * trifft.
     */
    public function exportCalendar(Request $request, Response $response, array $args): Response
    {
        $subscription = (new CalendarSubscriptionService())->findByToken((string) $args['token']);
        if (!$subscription) {
            return $response->withStatus(404);
        }

        // Wie im Termin-Feed: Mit dem Archivieren endet auch das Abo. Sonst
        // liest ein ausgeschiedenes Mitglied die Aufgabenliste ohne Anmeldung weiter.
        $user = User::find((int) $subscription->user_id);
        if (!$user || !(bool) $user->is_active) {
            return $response->withStatus(404);
        }

        $content = (new CalendarFeedService($this->nameFormatter))
            ->buildTaskCalendar($user, AppUrlResolver::resolveBaseUrl($request));

        $response->getBody()->write($content);

        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="chor-manager-aufgaben.ics"');
    }

    /**
     * Project members ordered by the configured name display format.
     */
    private function projectUsersInNameOrder(Project $project): \Illuminate\Database\Eloquent\Collection
    {
        $query = $project->users();

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
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

    /**
     * Prüft die gewählten Personen gegen die Projektmitglieder - dieselbe Menge,
     * die das Auswahlfeld anbietet. task_assignees hängt an Fremdschlüsseln:
     * Eine fremde oder unbekannte Kennung endete sonst in einer QueryException
     * und damit in einem 500 statt in einer Meldung am Formular.
     *
     * Liefert die Kennungen, ein leeres Feld für "nicht zugewiesen" oder false,
     * sobald eine Angabe nicht zum Projekt gehört. Eine einzelne ungültige
     * Kennung lässt die ganze Eingabe scheitern, statt sie stillschweigend zu
     * verwerfen - sonst spricht das Formular von drei Zugewiesenen und
     * gespeichert werden zwei.
     *
     * @param array<string, mixed> $data
     * @return list<int>|false
     */
    private function resolveAssignedUserIds(Project $project, array $data): array|false
    {
        $raw = $data['assigned_user_ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $userIds = [];
        foreach ($raw as $value) {
            $candidate = trim((string) $value);
            if ($candidate === '' || $candidate === '0') {
                continue;
            }

            if (!ctype_digit($candidate)) {
                return false;
            }

            $userIds[] = (int) $candidate;
        }

        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return [];
        }

        $known = $project->users()->whereIn('users.id', $userIds)->pluck('users.id')->all();

        return count($known) === count($userIds) ? $userIds : false;
    }

    /**
     * Nennt die Zugewiesenen einer Aufgabe in stabiler Reihenfolge, damit der
     * Verlaufseintrag bei unveränderter Zuweisung nicht anschlägt.
     *
     * @return list<int>
     */
    private function assigneeIds(Task $task): array
    {
        $ids = array_map('intval', $task->assignees()->pluck('users.id')->all());
        sort($ids);

        return $ids;
    }

    /**
     * Meldet den neu Zugewiesenen, dass sie eine Aufgabe bekommen haben.
     *
     * @param list<int> $addedUserIds
     */
    private function notifyAssigned(Request $request, Task $task, array $addedUserIds): void
    {
        if ($this->notificationService === null || $addedUserIds === []) {
            return;
        }

        $task->loadMissing(['assignees', 'project']);
        $actorId = (int) ($_SESSION['user_id'] ?? 0);

        // Wer sonst noch an der Aufgabe sitzt - das beantwortet die erste
        // Rückfrage, die eine solche Mail auslöst, gleich mit.
        $coAssignees = $task->assignees
            ->reject(fn ($assignee): bool => in_array((int) $assignee->id, $addedUserIds, true))
            ->map(fn ($assignee): string => $this->nameFormatter->formatPerson($assignee))
            ->values()
            ->all();

        $this->notificationService->notify(
            NotificationType::TASK_ASSIGNED,
            $task->assignees->filter(
                fn ($assignee): bool => in_array((int) $assignee->id, $addedUserIds, true)
            ),
            'Neue Aufgabe: ' . $task->name,
            'emails/notification_task_assigned.twig',
            [
                'task' => $task,
                'actor_name' => $this->actorName($actorId),
                'co_assignees' => $coAssignees,
                'link' => $this->taskUrl($request, $task),
                'profile_url' => $this->profileUrl($request),
            ],
            $actorId ?: null
        );
    }

    /**
     * Meldet den Beteiligten einer Aufgabe einen neuen Kommentar.
     *
     * Empfänger sind die Zugewiesenen und die Person, die die Aufgabe angelegt
     * hat - Letztere, weil sie den Stand wissen will, auch wenn sie die Arbeit
     * abgegeben hat.
     */
    private function notifyComment(Request $request, Task $task, string $comment): void
    {
        if ($this->notificationService === null) {
            return;
        }

        $task->loadMissing(['assignees', 'project', 'createdBy']);
        $actorId = (int) ($_SESSION['user_id'] ?? 0);

        $recipients = $task->assignees->all();
        if ($task->createdBy !== null) {
            $recipients[] = $task->createdBy;
        }

        $this->notificationService->notify(
            NotificationType::TASK_COMMENT,
            $recipients,
            'Neuer Kommentar: ' . $task->name,
            'emails/notification_task_comment.twig',
            [
                'task' => $task,
                'actor_name' => $this->actorName($actorId),
                'comment_text' => $comment,
                'link' => $this->taskUrl($request, $task),
                'profile_url' => $this->profileUrl($request),
            ],
            $actorId ?: null
        );
    }

    private function actorName(int $actorId): ?string
    {
        if ($actorId <= 0) {
            return null;
        }

        $actor = User::find($actorId);

        return $actor === null ? null : $this->nameFormatter->formatPerson($actor);
    }

    private function taskUrl(Request $request, Task $task): string
    {
        return AppUrlResolver::resolveBaseUrl($request) . '/tasks/' . $task->id;
    }

    private function profileUrl(Request $request): string
    {
        return AppUrlResolver::resolveBaseUrl($request) . '/profile';
    }

    /**
     * Zugang und Abgang einer Zuweisungsänderung.
     *
     * Verlaufseintrag und Benachrichtigung müssen sich über dieselbe Menge
     * einig sein - sonst nennt der Verlauf jemanden, der nie eine Mail bekam.
     *
     * @param list<int> $oldIds
     * @param list<int> $newIds
     * @return array{added: list<int>, removed: list<int>}
     */
    private function assigneeDiff(array $oldIds, array $newIds): array
    {
        return [
            'added' => array_values(array_diff($newIds, $oldIds)),
            'removed' => array_values(array_diff($oldIds, $newIds)),
        ];
    }

    /**
     * Beschreibt die Änderung an der Zuweisung als Zugang und Abgang. Die
     * frühere Fassung nannte nur den neuen Namen; bei mehreren Zugewiesenen
     * bliebe damit offen, wer dazugekommen und wer gegangen ist.
     *
     * @param list<int> $oldIds
     * @param list<int> $newIds
     */
    private function describeAssigneeChange(array $oldIds, array $newIds): string
    {
        ['added' => $added, 'removed' => $removed] = $this->assigneeDiff($oldIds, $newIds);

        $names = User::whereIn('id', array_merge($added, $removed))
            ->get()
            ->keyBy('id');

        $format = static function (array $ids) use ($names): string {
            $labels = [];
            foreach ($ids as $id) {
                $user = $names->get($id);
                $labels[] = $user ? trim($user->first_name . ' ' . $user->last_name) : 'Unbekannt';
            }

            return implode(', ', $labels);
        };

        $parts = [];
        if ($added !== []) {
            $parts[] = 'Zugewiesen an: ' . $format($added);
        }
        if ($removed !== []) {
            $parts[] = 'Zuweisung entfernt: ' . $format($removed);
        }

        if ($parts === []) {
            return 'Zuweisung geändert';
        }

        return implode('; ', $parts);
    }

    private function hasTaskAccess(Project $project): bool
    {
        $canManageTasks = $this->policy->canManageTasks();

        // Die Middleware lässt jeden mit can_manage_tasks passieren; fällt die
        // Entscheidung erst hier, blieb die Abweisung früher unprotokolliert.
        if (!$canManageTasks) {
            $this->logger->info('Access denied.', [
                'event' => 'authz.denied',
                'permission' => 'can_manage_tasks',
                'project_id' => (int) $project->id,
            ]);
        }

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
            ->with(['assignees', 'createdBy'])
            ->withCount('comments')
            ->orderBy('end_date', 'asc')
            ->get();

        $projectUsers = $this->projectUsersInNameOrder($project);
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
        $task = Task::with(['project', 'assignees', 'createdBy', 'comments.user', 'attachments', 'activities.user'])
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
            'projectUsers' => $this->projectUsersInNameOrder($task->project),
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

        $assignedUserIds = $this->resolveAssignedUserIds($project, $data);
        if ($assignedUserIds === false) {
            $_SESSION['error'] = 'Mindestens eine gewählte Person gehört nicht zu diesem Projekt.';
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

        // Die Benachrichtigung geht erst nach dem Festschreiben raus: Innerhalb
        // der Transaktion stünde die Mail schon in der Warteschlange, während
        // die Aufgabe bei einem Rücksetzer nie entsteht.
        $task = Capsule::connection()->transaction(function () use (
            $project,
            $data,
            $title,
            $description,
            $startDate,
            $endDate,
            $assignedUserIds
        ) {
            $task = Task::create([
                'project_id'       => $project->id,
                'name'             => $title,
                'description'      => $description,
                'created_by'       => $_SESSION['user_id'],
                'start_date'       => $startDate,
                'end_date'         => $endDate,
                'status'           => $this->validateStatus($data['status'] ?? 'Offen'),
                'priority'         => $this->validatePriority($data['priority'] ?? 'Mittel'),
            ]);

            $task->assignees()->sync($assignedUserIds);

            Activity::create([
                'entity_type' => 'task',
                'entity_id'   => $task->id,
                'user_id'     => $_SESSION['user_id'],
                'action'      => 'created',
                'description' => 'Aufgabe erstellt.',
            ]);

            return $task;
        });

        $this->notifyAssigned($request, $task, $assignedUserIds);

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

        $assignedUserIds = $this->resolveAssignedUserIds($task->project, $data);
        if ($assignedUserIds === false) {
            $_SESSION['error'] = 'Mindestens eine gewählte Person gehört nicht zu diesem Projekt.';
            return $response->withHeader('Location', "/tasks/{$task->id}")->withStatus(302);
        }

        $oldStatus = $task->status;
        $oldPriority = $task->priority;
        $oldAssignedIds = $this->assigneeIds($task);
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

        $addedAssignedIds = Capsule::connection()->transaction(function () use (
            $task,
            $data,
            $title,
            $description,
            $startDate,
            $endDate,
            $oldStatus,
            $oldPriority,
            $oldAssignedIds,
            $oldDescription,
            $assignedUserIds
        ) {
            $task->update([
                'name'             => $title,
                'description'      => $description,
                'start_date'       => $startDate,
                'end_date'         => $endDate,
                'status'           => $this->validateStatus($data['status'] ?? $task->status),
                'priority'         => $this->validatePriority($data['priority'] ?? $task->priority),
            ]);

            $task->assignees()->sync($assignedUserIds);

            // Changes logging
            $changes = [];
            if ($oldStatus !== $task->status) {
                $changes[] = "Status von '$oldStatus' auf '{$task->status}' geändert";
            }
            if ($oldPriority !== $task->priority) {
                $changes[] = "Priorität von '$oldPriority' auf '{$task->priority}' geändert";
            }
            $newAssignedIds = $this->assigneeIds($task->fresh());
            if ($oldAssignedIds !== $newAssignedIds) {
                $changes[] = $this->describeAssigneeChange($oldAssignedIds, $newAssignedIds);
            }
            $addedAssignedIds = $this->assigneeDiff($oldAssignedIds, $newAssignedIds)['added'];
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

            return $addedAssignedIds;
        });

        $this->notifyAssigned($request, $task->fresh(), $addedAssignedIds);

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
            Capsule::connection()->transaction(function () use ($task, $content) {
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

            $this->notifyComment($request, $task, $content);

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
                $mimeType = UploadValidator::detectMimeType($file);
                $contents = $file->getStream()->getContents();
                $size = strlen($contents);

                $validation = UploadValidator::validateFileSize($size, $mimeType);
                if (!$validation['valid']) {
                    $this->logger->warning('File upload rejected.', [
                        'event' => 'security.upload.rejected',
                        'reason' => $validation['reason'],
                    ]);
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

        // Wie in allen übrigen Aufgaben-Aktionen über hasTaskAccess(): dasselbe
        // Recht, aber mit dem authz.denied-Eintrag im Protokoll. Der direkte
        // Aufruf der Policy war die einzige Abweisung dieser Datei ohne Spur.
        if (!$this->hasTaskAccess($task->project)) {
            $_SESSION['error'] = 'Zugriff verweigert.';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $attachment = Attachment::where('entity_type', 'task')
            ->where('entity_id', $taskId)
            ->findOrFail($attachmentId);

        $safeName = DownloadFileName::sanitize((string) $attachment->original_name);
        $response->getBody()->write($attachment->file_content);
        return $response
            ->withHeader('Content-Type', $attachment->mime_type)
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
            );
    }

    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $taskId = (int) $args['id'];
        $task = Task::findOrFail($taskId);

        if (!$this->hasTaskAccess($task->project)) {
            $response->getBody()->write((string) json_encode([
                'success' => false,
                'error' => 'Zugriff verweigert.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $data = (array) $request->getParsedBody();
        if (empty($data)) {
            $json = $request->getBody()->getContents();
            $data = (array) json_decode($json, true);
        }

        $statusInput = trim((string) ($data['status'] ?? ''));
        if (empty($statusInput)) {
            $response->getBody()->write((string) json_encode([
                'success' => false,
                'error' => 'Status erforderlich.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $newStatus = $this->validateStatus($statusInput);
        if ($newStatus !== $statusInput) {
            $response->getBody()->write((string) json_encode([
                'success' => false,
                'error' => 'Ungültiger Status.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $oldStatus = $task->status;

        if ($oldStatus !== $newStatus) {
            Capsule::connection()->transaction(function () use ($task, $newStatus, $oldStatus) {
                $task->update([
                    'status' => $newStatus,
                ]);

                Activity::create([
                    'entity_type' => 'task',
                    'entity_id'   => $task->id,
                    'user_id'     => $_SESSION['user_id'],
                    'action'      => 'updated',
                    'description' => "Status von '$oldStatus' auf '$newStatus' geändert via Kanban-Board",
                ]);
            });
        }

        $response->getBody()->write((string) json_encode([
            'success' => true,
            'status' => $newStatus,
            'message' => 'Status erfolgreich aktualisiert.'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
}
