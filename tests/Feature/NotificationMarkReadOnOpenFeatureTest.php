<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Controllers\ProjectController;
use App\Controllers\SponsorController;
use App\Controllers\TaskController;
use App\Models\Event;
use App\Models\Project;
use App\Models\Sponsor;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Persistence\ProjectPersistence;
use App\Policies\ProjectMemberPolicy;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Queries\ProjectQuery;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use App\Util\NotificationType;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Wer eine Aufgabe, einen Termin, ein Projekt oder einen Sponsor öffnet, hat
 * die Glocken-Einträge dazu gesehen - egal, ob er über die Glocke kam oder über
 * die Liste. Gelesen werden dabei nur die eigenen Einträge zu genau diesem
 * Objekt.
 */
final class NotificationMarkReadOnOpenFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $anna;
    private User $bernd;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->anna = $this->createUser('Anna', 'Amsel');
        $this->bernd = $this->createUser('Bernd', 'Buchfink');
        $this->project = Project::create([
            'name' => 'Gelesen ' . bin2hex(random_bytes(3)),
            'description' => 'Projekt für die Glocke',
        ]);

        $_SESSION = [
            'user_id' => (int) $this->anna->id,
            'can_manage_tasks' => true,
            'can_manage_events' => true,
            'can_manage_sponsoring' => true,
        ];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testOpeningATaskReadsOnlyTheOwnEntriesOfThatTask(): void
    {
        $task = $this->makeTask('Programmheft');
        $otherTask = $this->makeTask('Plakate');
        $match = $this->entry($this->anna, 'task', (int) $task->id);
        $otherObject = $this->entry($this->anna, 'task', (int) $otherTask->id);
        $otherPerson = $this->entry($this->bernd, 'task', (int) $task->id);

        $this->taskController()->detail(
            $this->makeRequest('GET', '/tasks/' . $task->id),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertNotNull($match->fresh()->read_at);
        $this->assertNull($otherObject->fresh()->read_at);
        $this->assertNull($otherPerson->fresh()->read_at);
    }

    public function testADeniedTaskReadsNothing(): void
    {
        $task = $this->makeTask('Geheim');
        $entry = $this->entry($this->anna, 'task', (int) $task->id);
        $_SESSION['can_manage_tasks'] = false;

        $this->taskController()->detail(
            $this->makeRequest('GET', '/tasks/' . $task->id),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $this->assertNull($entry->fresh()->read_at);
    }

    public function testOpeningAnEventReadsOnlyTheOwnEntriesOfThatEvent(): void
    {
        $event = $this->makeEvent('Hauptprobe');
        $otherEvent = $this->makeEvent('Generalprobe');
        $match = $this->entry($this->anna, 'event', (int) $event->id);
        $otherObject = $this->entry($this->anna, 'event', (int) $otherEvent->id);
        $otherPerson = $this->entry($this->bernd, 'event', (int) $event->id);

        (new EventController(
            $this->createStub(Twig::class),
            new NameFormatterService(),
            new NullLogger(),
            new ProjectQuery(new NameFormatterService())
        ))->detail(
            $this->makeRequest('GET', '/events/' . $event->id),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );

        $this->assertNotNull($match->fresh()->read_at);
        $this->assertNull($otherObject->fresh()->read_at);
        $this->assertNull($otherPerson->fresh()->read_at);
    }

    public function testOpeningTheProjectMembersReadsTheProjectEntries(): void
    {
        $entry = $this->entry($this->anna, 'project', (int) $this->project->id);
        $policy = $this->createStub(ProjectMemberPolicy::class);
        $policy->method('canViewMembers')->willReturn(true);
        $policy->method('canViewAllCandidates')->willReturn(true);

        (new ProjectController(
            $this->createStub(Twig::class),
            new ProjectQuery(new NameFormatterService()),
            $this->createStub(ProjectPersistence::class),
            $policy,
            new NullLogger()
        ))->showMembers(
            $this->makeRequest('GET', '/projects/' . $this->project->id . '/members'),
            $this->makeResponse(),
            ['id' => (string) $this->project->id]
        );

        $this->assertNotNull($entry->fresh()->read_at);
    }

    public function testOpeningASponsorReadsTheSponsorEntries(): void
    {
        $sponsor = Sponsor::create(['type' => 'organization', 'name' => 'Musikhaus Klang']);
        $entry = $this->entry($this->anna, 'sponsor', (int) $sponsor->id);

        (new SponsorController(
            $this->createStub(Twig::class),
            new SponsoringPolicy($_SESSION),
            $this->attachmentService(),
            new NullLogger()
        ))->detail(
            $this->makeRequest('GET', '/sponsoring/sponsors/' . $sponsor->id),
            $this->makeResponse(),
            ['id' => (string) $sponsor->id]
        );

        $this->assertNotNull($entry->fresh()->read_at);
    }

    private function taskController(): TaskController
    {
        return new TaskController(
            $this->createStub(Twig::class),
            new HtmlSanitizer(),
            new TaskPolicy($_SESSION),
            new NameFormatterService(),
            new NullLogger()
        );
    }

    private function makeTask(string $name): Task
    {
        return Task::create([
            'project_id' => $this->project->id,
            'name' => $name,
            'status' => 'Offen',
            'priority' => 'Mittel',
            'created_by' => $this->anna->id,
        ]);
    }

    private function makeEvent(string $title): Event
    {
        $day = Carbon::now()->addDays(5)->format('Y-m-d');

        return Event::create([
            'title' => $title,
            'starts_at' => $day . ' 19:00:00',
            'ends_at' => $day . ' 21:00:00',
            'type' => 'Probe',
        ]);
    }

    private function entry(User $user, string $entityType, int $entityId): UserNotification
    {
        return UserNotification::create([
            'user_id' => $user->id,
            'notification_type' => NotificationType::TASK_COMMENT,
            'title' => 'Eintrag',
            'link' => '/x',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'created_at' => Carbon::now(),
        ]);
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'markread.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }
}
