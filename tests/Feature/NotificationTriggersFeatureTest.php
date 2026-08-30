<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\TaskController;
use App\Models\MailQueue;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Services\NotificationService;
use App\Util\NotificationType;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Auslöser im Aufgaben-Controller.
 *
 * Geprüft wird nicht, dass der Dienst gerufen wurde, sondern dass am Ende die
 * richtigen Zeilen in der Warteschlange stehen - der Weg dorthin darf sich
 * ändern, das Ergebnis nicht.
 */
final class NotificationTriggersFeatureTest extends TestCase
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
        Capsule::connection()->beginTransaction();

        $this->project = Project::create([
            'name' => 'Auslöser ' . bin2hex(random_bytes(4)),
            'description' => 'Projekt für die Auslöser',
        ]);

        $this->anna = $this->createUser('Anna', 'Amsel');
        $this->bernd = $this->createUser('Bernd', 'Buchfink');
        $this->clara = $this->createUser('Clara', 'Kranich');
        $this->project->users()->attach([$this->anna->id, $this->bernd->id, $this->clara->id]);

        // Anna löst aus - sie darf nie selbst benachrichtigt werden.
        $_SESSION = ['user_id' => (int) $this->anna->id, 'can_manage_tasks' => true];
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

    public function testCreatingATaskNotifiesTheAssigneesButNotTheCreator(): void
    {
        $this->controller()->create(
            $this->makeRequest('POST', '/projects/' . $this->project->id . '/tasks', [
                'title' => 'Bühne aufbauen',
                'assigned_user_ids' => [
                    (string) $this->anna->id,
                    (string) $this->bernd->id,
                    (string) $this->clara->id,
                ],
            ]),
            $this->makeResponse(),
            ['project_id' => (string) $this->project->id]
        );

        $this->assertSame(
            [$this->bernd->email, $this->clara->email],
            $this->queuedRecipients(),
            'Anna hat die Aufgabe selbst angelegt und sich selbst eingetragen.'
        );
        $this->assertSame(
            NotificationType::TASK_ASSIGNED,
            $this->firstQueued()->payload_json['notification_type']
        );
    }

    /**
     * Beim Ändern bekommen nur die neu Hinzugekommenen eine Mail - wer schon
     * eingetragen war, weiß es bereits.
     */
    public function testUpdatingATaskNotifiesOnlyTheNewlyAdded(): void
    {
        $task = $this->makeTask('Technik abstimmen', [$this->bernd->id]);

        $this->controller()->update(
            $this->makeRequest('POST', '/tasks/' . $task->id . '/update', [
                'title' => 'Technik abstimmen',
                'assigned_user_ids' => [(string) $this->bernd->id, (string) $this->clara->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertSame([$this->clara->email], $this->queuedRecipients());
    }

    public function testRemovingAnAssigneeSendsNoMail(): void
    {
        $task = $this->makeTask('Wird frei', [$this->bernd->id, $this->clara->id]);

        $this->controller()->update(
            $this->makeRequest('POST', '/tasks/' . $task->id . '/update', [
                'title' => 'Wird frei',
                'assigned_user_ids' => [(string) $this->bernd->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertSame([], $this->queuedRecipients());
    }

    public function testCommentingNotifiesTheAssigneesAndTheCreator(): void
    {
        $task = $this->makeTask('Programmheft', [$this->bernd->id], (int) $this->clara->id);

        $this->controller()->addComment(
            $this->makeRequest('POST', '/tasks/' . $task->id . '/comments', [
                'content' => 'Der Saal ist reserviert.',
            ]),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertSame(
            [$this->bernd->email, $this->clara->email],
            $this->queuedRecipients(),
            'Zugewiesener und Ersteller - nicht die kommentierende Anna.'
        );
    }

    /**
     * Ist der Kommentierende zugleich der einzige Zugewiesene, entsteht keine
     * Mail. Sich selbst zu schreiben wäre der offensichtlichste Fehler.
     */
    public function testCommentingOnAnOwnTaskSendsNothing(): void
    {
        $task = $this->makeTask('Meine eigene', [$this->anna->id], (int) $this->anna->id);

        $this->controller()->addComment(
            $this->makeRequest('POST', '/tasks/' . $task->id . '/comments', [
                'content' => 'Notiz an mich.',
            ]),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertSame([], $this->queuedRecipients());
    }

    /**
     * Der Ersteller kann zugleich zugewiesen sein - dann steht er zweimal in
     * der Empfängerliste und darf trotzdem nur eine Mail bekommen.
     */
    public function testAnAssignedCreatorGetsOneMailNotTwo(): void
    {
        $task = $this->makeTask('Doppelt vertreten', [$this->bernd->id], (int) $this->bernd->id);

        $this->controller()->addComment(
            $this->makeRequest('POST', '/tasks/' . $task->id . '/comments', [
                'content' => 'Zwischenstand.',
            ]),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertSame([$this->bernd->email], $this->queuedRecipients());
    }

    /**
     * @param list<int> $assigneeIds
     */
    private function makeTask(string $name, array $assigneeIds, ?int $createdBy = null): Task
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => $name,
            'status' => 'Offen',
            'created_by' => $createdBy ?? (int) $this->anna->id,
        ]);
        $task->assignees()->sync($assigneeIds);

        return $task->fresh();
    }

    private function controller(): TaskController
    {
        return new TaskController(
            $this->createStub(Twig::class),
            new HtmlSanitizer(),
            new TaskPolicy(),
            new NameFormatterService(),
            new NullLogger(),
            new NotificationService(
                new MailQueueService(),
                Twig::create(dirname(__DIR__, 2) . '/templates'),
                new NullLogger(),
                ['tasks' => true]
            )
        );
    }

    /**
     * @return list<string>
     */
    private function queuedRecipients(): array
    {
        return MailQueue::whereIn(
            'recipient_email',
            [$this->anna->email, $this->bernd->email, $this->clara->email]
        )
            ->orderBy('id')
            ->pluck('recipient_email')
            ->all();
    }

    private function firstQueued(): MailQueue
    {
        return MailQueue::whereIn(
            'recipient_email',
            [$this->anna->email, $this->bernd->email, $this->clara->email]
        )->orderBy('id')->firstOrFail();
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'trigger.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }
}
