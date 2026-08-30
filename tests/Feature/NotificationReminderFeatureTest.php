<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\MailQueue;
use App\Models\NotificationDispatchLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\MailQueueService;
use App\Services\NotificationReminderService;
use App\Services\NotificationService;
use App\Util\NotificationType;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Erinnerung an fällige Aufgaben.
 *
 * Der heikle Teil ist nicht das Finden, sondern das Nicht-Wiederholen: Der
 * Worker läuft stündlich, und ohne Sperre bekäme jeder Zugewiesene bis zum
 * Fälligkeitstag jede Stunde dieselbe Mail.
 */
final class NotificationReminderFeatureTest extends TestCase
{
    private const BASE_URL = 'https://chor.example';

    private Project $project;
    private User $anna;
    private User $bernd;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        // Der Dienst durchsucht alle Aufgaben, nicht nur die dieses Tests. Mit
        // den Seed-Daten im Rücken wäre jede Zählung eine andere. Das Löschen
        // läuft in der Transaktion und ist nach dem Test wieder zurückgenommen.
        Task::query()->delete();
        NotificationDispatchLog::query()->delete();

        $this->project = Project::create([
            'name' => 'Erinnerungen ' . bin2hex(random_bytes(4)),
            'description' => 'Projekt für die Erinnerungen',
        ]);

        $this->anna = $this->createUser('Anna', 'Amsel');
        $this->bernd = $this->createUser('Bernd', 'Buchfink');
        $this->project->users()->attach([$this->anna->id, $this->bernd->id]);

        $this->setDaysBefore(3);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEveryAssigneeOfADueTaskIsReminded(): void
    {
        $this->makeTask('Bühne aufbauen', '+2 days', [$this->anna->id, $this->bernd->id]);

        $this->assertSame(2, $this->service()->processDue(self::BASE_URL));
        $this->assertSame(2, $this->queuedCount());
    }

    /**
     * Der zweite Lauf ist der Normalfall - der Worker läuft stündlich. Er darf
     * nichts wiederholen.
     */
    public function testASecondRunEnqueuesNothing(): void
    {
        $this->makeTask('Programmheft', '+1 day', [$this->anna->id]);

        $service = $this->service();
        $this->assertSame(1, $service->processDue(self::BASE_URL));
        $this->assertSame(0, $service->processDue(self::BASE_URL));
        $this->assertSame(1, $this->queuedCount());
    }

    /**
     * Wird die Aufgabe verschoben, ist es ein neuer Anlass: Der Merkzettel
     * trägt das Fälligkeitsdatum, also greift die Sperre für das neue Datum
     * nicht mehr.
     */
    public function testAMovedDueDateRemindsAgain(): void
    {
        $task = $this->makeTask('Technik', '+1 day', [$this->anna->id]);

        $service = $this->service();
        $this->assertSame(1, $service->processDue(self::BASE_URL));

        $task->update(['end_date' => Carbon::today()->addDays(3)->toDateString()]);

        $this->assertSame(1, $service->processDue(self::BASE_URL));
        $this->assertSame(2, $this->queuedCount());
    }

    public function testTasksOutsideTheWindowAreLeftAlone(): void
    {
        $this->makeTask('Noch lange hin', '+30 days', [$this->anna->id]);

        $this->assertSame(0, $this->service()->processDue(self::BASE_URL));
    }

    /**
     * Bereits überfällige Aufgaben bleiben außen vor: Sonst bekäme jemand, der
     * den Termin verpasst hat, bis zum Abschließen täglich dieselbe Mahnung.
     */
    public function testOverdueTasksAreNotReminded(): void
    {
        $this->makeTask('Längst fällig', '-5 days', [$this->anna->id]);

        $this->assertSame(0, $this->service()->processDue(self::BASE_URL));
    }

    public function testCompletedTasksAreNotReminded(): void
    {
        $this->makeTask('Schon erledigt', '+1 day', [$this->anna->id], 'Abgeschlossen');

        $this->assertSame(0, $this->service()->processDue(self::BASE_URL));
    }

    public function testTasksWithoutADueDateAreNotReminded(): void
    {
        $this->makeTask('Ohne Datum', null, [$this->anna->id]);

        $this->assertSame(0, $this->service()->processDue(self::BASE_URL));
    }

    public function testZeroDaysSwitchesTheReminderOff(): void
    {
        $this->setDaysBefore(0);
        $this->makeTask('Wäre fällig', '+1 day', [$this->anna->id]);

        $this->assertSame(0, $this->service()->processDue(self::BASE_URL));
        $this->assertSame(0, NotificationDispatchLog::count());
    }

    /**
     * Die Sperre hängt am Paar aus Aufgabe und Empfänger: Hat eine Person den
     * Anlass abbestellt, darf das die andere nicht mitnehmen.
     */
    public function testAnOptedOutAssigneeDoesNotBlockTheOther(): void
    {
        \App\Models\UserNotificationSetting::create([
            'user_id' => $this->anna->id,
            'notification_type' => NotificationType::TASK_DUE_SOON,
            'enabled' => false,
        ]);

        $this->makeTask('Gemeinsam', '+2 days', [$this->anna->id, $this->bernd->id]);

        $this->assertSame(1, $this->service()->processDue(self::BASE_URL));
        $this->assertSame([$this->bernd->email], $this->queuedRecipients());
    }

    private function service(): NotificationReminderService
    {
        $notificationService = new NotificationService(
            new MailQueueService(),
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new NullLogger(),
            ['tasks' => true, 'sponsoring' => true]
        );

        return new NotificationReminderService($notificationService, new NullLogger());
    }

    private function setDaysBefore(int $days): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => 'notification_task_due_days_before'],
            ['setting_value' => (string) $days, 'binary_content' => '', 'mime_type' => 'text/plain']
        );
        AppSetting::updateOrCreate(
            ['setting_key' => 'notification_sponsoring_follow_up_days_before'],
            ['setting_value' => '0', 'binary_content' => '', 'mime_type' => 'text/plain']
        );
    }

    /**
     * @param list<int> $assigneeIds
     */
    private function makeTask(string $name, ?string $offset, array $assigneeIds, string $status = 'Offen'): Task
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => $name,
            'status' => $status,
            'priority' => 'Mittel',
            'end_date' => $offset === null ? null : Carbon::today()->modify($offset)->toDateString(),
            'created_by' => $this->anna->id,
        ]);
        $task->assignees()->sync($assigneeIds);

        return $task->fresh();
    }

    private function queuedCount(): int
    {
        return MailQueue::whereIn('recipient_email', [$this->anna->email, $this->bernd->email])->count();
    }

    /**
     * @return list<string>
     */
    private function queuedRecipients(): array
    {
        return MailQueue::whereIn('recipient_email', [$this->anna->email, $this->bernd->email])
            ->orderBy('id')
            ->pluck('recipient_email')
            ->all();
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'reminder.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }
}
