<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Controllers\NewsletterController;
use App\Controllers\SongLibraryController;
use App\Controllers\SponsorController;
use App\Controllers\TaskController;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\Song;
use App\Models\Sponsor;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Queries\ProjectQuery;
use App\Services\EntityAttachmentService;
use App\Services\EntityCleanupService;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\NewsletterAttachmentService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterMailRenderer;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use App\Policies\NewsletterPolicy;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Was beim Löschen einer Entität an Anhängseln mitgehen muss.
 *
 * `attachments`, `comments`, `activities` und `user_notifications` zeigen über
 * entity_type/entity_id auf ihr Objekt. Ein Fremdschlüssel geht dabei nicht,
 * weil das Ziel je Zeile in einer anderen Tabelle steht - die Datenbank kann
 * also nichts mitnehmen, und das Aufräumen bleibt am löschenden Codepfad
 * hängen.
 *
 * Genau das lief auseinander: Sponsor, Vereinbarung, Lied und Aufgabe räumten
 * ihre Anhänge ab, die Notizen einer Aufgabe und eines Termins sowie die
 * Verlaufseinträge einer Aufgabe blieben liegen, und ein gelöschter
 * Newsletter-Entwurf ließ seine Dateien als unerreichbare BLOB-Zeilen zurück.
 * Auffallen konnte das nicht: Die Zeilen sind über die Oberfläche nicht mehr
 * erreichbar, sie wachsen nur still mit - bei `attachments` samt Dateiinhalt.
 *
 * Der Test fasst die Regel für alle vier Wege zusammen, damit ein fünfter
 * Löschweg nicht wieder einzeln vergessen wird.
 */
final class EntityCleanupOnDeleteFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->user = User::create([
            // Adresse bleibt ASCII, wie jede E-Mail-Adresse. naming:ascii
            'email' => 'cleanup-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Clara',
            'last_name' => 'Aufräumer',
            'is_active' => 1,
        ]);

        $this->project = Project::create([
            'name' => 'Aufräumprojekt ' . bin2hex(random_bytes(4)),
            'description' => 'Projekt für den Aufräum-Test',
        ]);

        $_SESSION = [
            'user_id' => (int) $this->user->id,
            'can_manage_tasks' => true,
            'can_manage_events' => true,
            'can_manage_newsletters' => true,
            'can_manage_song_library' => true,
            'can_manage_sponsoring' => true,
        ];
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

    public function testDeletingATaskTakesItsAttachmentsNotesAndHistoryAlong(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Noten kopieren',
            'status' => 'Offen',
            'priority' => 'Mittel',
            'created_by' => $this->user->id,
        ]);
        $taskId = (int) $task->id;

        $this->attach('task', $taskId);
        $this->note('task', $taskId);
        $this->history('task', $taskId);
        $this->bell('task', $taskId);

        $this->taskController()->delete(
            $this->makeRequest('POST', '/tasks/' . $taskId . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $taskId]
        );

        $this->assertNull(Task::find($taskId), 'Die Aufgabe selbst muss weg sein.');
        $this->assertSame(0, $this->countAttachments('task', $taskId));
        $this->assertSame(
            0,
            $this->countComments('task', $taskId),
            'Die Notizen einer gelöschten Aufgabe bleiben sonst liegen.'
        );
        $this->assertSame(
            0,
            $this->countActivities('task', $taskId),
            'Der Verlauf einer gelöschten Aufgabe bleibt sonst liegen.'
        );
        $this->assertSame(
            0,
            $this->countBellEntries('task', $taskId),
            'Die Glocken-Einträge einer gelöschten Aufgabe führen sonst ins Leere - '
                . 'und der Kommentartext bleibt in der Glocke aller Beteiligten lesbar.'
        );
    }

    public function testDeletingAnEventTakesItsNotesAlong(): void
    {
        $event = $this->event();
        $eventId = (int) $event->id;

        $this->note('event', $eventId);
        $this->bell('event', $eventId);

        $this->eventController()->delete(
            $this->makeRequest('POST', '/events/' . $eventId . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $eventId]
        );

        $this->assertNull(Event::find($eventId), 'Der Termin selbst muss weg sein.');
        $this->assertSame(
            0,
            $this->countComments('event', $eventId),
            'Die Notizen eines gelöschten Termins bleiben sonst liegen.'
        );
        $this->assertSame(
            0,
            $this->countBellEntries('event', $eventId),
            'Die Glocken-Einträge eines gelöschten Termins führen sonst ins Leere.'
        );
    }

    public function testDeletingASeriesTakesTheNotesOfEveryRemovedEventAlong(): void
    {
        $series = EventSeries::create([
            'frequency' => 'weekly',
            'recurrence_interval' => 1,
            'weekdays' => '1',
            'end_date' => Carbon::now()->addDays(30)->format('Y-m-d'),
        ]);

        $first = $this->event(7, (int) $series->id);
        $second = $this->event(14, (int) $series->id);

        $this->note('event', (int) $first->id);
        $this->note('event', (int) $second->id);

        $this->eventController()->deleteSeries(
            $this->makeRequest('POST', '/events/' . $first->id . '/delete-series'),
            $this->makeResponse(),
            ['id' => (string) $first->id]
        );

        $this->assertNull(Event::find((int) $second->id), 'Die Serie ab dem angeklickten Termin muss weg sein.');
        $this->assertSame(0, $this->countComments('event', (int) $first->id));
        $this->assertSame(
            0,
            $this->countComments('event', (int) $second->id),
            'Auch die Notizen der weiteren Serientermine müssen mitgehen.'
        );
    }

    public function testDeletingANewsletterDraftTakesItsAttachmentsAlong(): void
    {
        $newsletter = Newsletter::create([
            'project_id' => $this->project->id,
            'title' => 'Probenwoche',
            'content_html' => '<p>Hallo</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $this->user->id,
        ]);
        $newsletterId = (int) $newsletter->id;

        $this->attach('newsletter', $newsletterId);

        $this->newsletterController()->deleteDraft(
            $this->makeRequest('POST', '/newsletters/' . $newsletterId . '/delete')
                ->withAttribute('id', (string) $newsletterId),
            $this->makeResponse()
        );

        $this->assertNull(Newsletter::find($newsletterId), 'Der Entwurf selbst muss weg sein.');
        $this->assertSame(
            0,
            $this->countAttachments('newsletter', $newsletterId),
            'Die Dateien eines gelöschten Entwurfs bleiben sonst als BLOB-Zeilen liegen.'
        );
    }

    public function testDeletingASongStillTakesItsAttachmentsAlong(): void
    {
        $song = Song::create(['title' => 'Abendlied ' . bin2hex(random_bytes(4))]);
        $songId = (int) $song->id;

        $this->attach('song', $songId);

        $this->songController()->deleteSong(
            $this->makeRequest('POST', '/song-library/' . $songId . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $songId]
        );

        $this->assertNull(Song::find($songId));
        $this->assertSame(0, $this->countAttachments('song', $songId));
    }

    /**
     * Die Wiedervorlagen aus NotificationReminderService hängen als
     * Glocken-Einträge am Sponsor. Der Löschweg räumte bisher nur die Anhänge
     * ab und ging am gemeinsamen Dienst vorbei.
     */
    public function testDeletingASponsorTakesItsBellEntriesAlong(): void
    {
        $sponsor = Sponsor::create([
            'type' => 'organization',
            'name' => 'Musikhaus Klang ' . bin2hex(random_bytes(4)),
            'created_by_user_id' => $this->user->id,
        ]);
        $sponsorId = (int) $sponsor->id;

        $this->attach('sponsor', $sponsorId);
        $this->bell('sponsor', $sponsorId);

        $this->sponsorController()->delete(
            $this->makeRequest('POST', '/sponsoring/sponsors/' . $sponsorId . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $sponsorId]
        );

        $this->assertNull(Sponsor::find($sponsorId), 'Der Sponsor selbst muss weg sein.');
        $this->assertSame(0, $this->countAttachments('sponsor', $sponsorId));
        $this->assertSame(
            0,
            $this->countBellEntries('sponsor', $sponsorId),
            'Die Wiedervorlage eines gelöschten Sponsors führt sonst ins Leere.'
        );
    }

    /**
     * Der Dienst selbst: Er fasst nur die genannten Kennungen an und lässt die
     * Anhängsel anderer Entitäten desselben Typs unberührt.
     */
    public function testTheCleanupLeavesOtherEntitiesOfTheSameTypeAlone(): void
    {
        $kept = $this->unusedTaskId();
        $removedId = $kept + 1;

        $this->attach('task', $removedId);
        $this->note('task', $removedId);
        $this->history('task', $removedId);
        $this->bell('task', $removedId);
        $this->attach('task', $kept);
        $this->bell('task', $kept);

        $removed = (new EntityCleanupService())->purgeForEntities('task', [$removedId]);

        $this->assertSame(
            ['attachments' => 1, 'comments' => 1, 'activities' => 1, 'notifications' => 1],
            $removed
        );
        $this->assertSame(0, $this->countAttachments('task', $removedId));
        $this->assertSame(1, $this->countAttachments('task', $kept), 'Eine fremde Aufgabe darf nichts verlieren.');
        $this->assertSame(
            1,
            $this->countBellEntries('task', $kept),
            'Die Glocke einer fremden Aufgabe darf nichts verlieren.'
        );
    }

    public function testTheCleanupDoesNothingWithoutIds(): void
    {
        $taskId = $this->unusedTaskId();
        $this->attach('task', $taskId);

        $removed = (new EntityCleanupService())->purgeForEntities('task', []);

        $this->assertSame(
            ['attachments' => 0, 'comments' => 0, 'activities' => 0, 'notifications' => 0],
            $removed
        );
        $this->assertSame(1, $this->countAttachments('task', $taskId));
    }

    /**
     * Eine Kennung, die keine echte Aufgabe trägt. Der Dienst prüft das Ziel
     * nicht - er räumt zu einer Kennung ab, die der Aufrufer gerade löscht.
     */
    private function unusedTaskId(): int
    {
        return ((int) Task::query()->max('id')) + 1000;
    }

    private function taskController(): TaskController
    {
        return new TaskController(
            $this->twig(),
            new HtmlSanitizer(),
            new TaskPolicy($_SESSION),
            new NameFormatterService(),
            new NullLogger()
        );
    }

    private function eventController(): EventController
    {
        return new EventController(
            $this->twig(),
            new NameFormatterService(),
            new NullLogger(),
            new ProjectQuery(new NameFormatterService())
        );
    }

    private function songController(): SongLibraryController
    {
        return new SongLibraryController($this->twig(), new NullLogger());
    }

    /**
     * Nur der Löschweg wird geprüft; die Versand- und Anzeigeteile bekommen
     * deshalb schlichte Instanzen statt einer nachgebauten Umgebung.
     */
    private function newsletterController(): NewsletterController
    {
        $twig = $this->twig();

        return new NewsletterController(
            $twig,
            new NewsletterService(
                new NewsletterRecipientService(),
                new Mailer(new NullLogger()),
                new HtmlSanitizer(),
                new MailQueueService(),
                new NullLogger(),
                new NewsletterPlaceholderService(new NameFormatterService()),
                new NewsletterMailRenderer($twig),
                new NewsletterAttachmentService()
            ),
            new NewsletterLockingService(),
            new NewsletterRecipientService(),
            new HtmlSanitizer(),
            new NullLogger(),
            new NameFormatterService(),
            new NewsletterPlaceholderService(new NameFormatterService()),
            new MailQueueService(),
            new NewsletterMailRenderer($twig),
            new NewsletterPolicy($_SESSION),
            new EntityAttachmentService(new NullLogger()),
            new NewsletterAttachmentService()
        );
    }

    private function sponsorController(): SponsorController
    {
        return new SponsorController(
            $this->twig(),
            new SponsoringPolicy($_SESSION),
            new EntityAttachmentService(new NullLogger()),
            new NullLogger()
        );
    }

    private function twig(): Twig
    {
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            static fn(ResponseInterface $response): ResponseInterface => $response
        );

        return $twig;
    }

    private function event(int $daysAhead = 7, ?int $seriesId = null): Event
    {
        $start = Carbon::now()->addDays($daysAhead)->setTime(19, 0);

        return Event::create([
            'title' => 'Probe',
            'starts_at' => $start,
            'ends_at' => (clone $start)->setTime(21, 0),
            'type' => 'Probe',
            'series_id' => $seriesId,
            'location' => 'Pfarrsaal',
            'registration_enabled' => false,
            'attendance_required' => true,
        ]);
    }

    private function attach(string $entityType, int $entityId): void
    {
        Attachment::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'filename' => bin2hex(random_bytes(8)) . '_noten.pdf',
            'original_name' => 'noten.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 4,
            'file_content' => 'PDF!',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function note(string $entityType, int $entityId): void
    {
        Comment::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $this->user->id,
            'comment' => 'Bitte Noten mitbringen.',
            'is_private' => false,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function history(string $entityType, int $entityId): void
    {
        Activity::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $this->user->id,
            'action' => 'created',
            'description' => 'Angelegt.',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function bell(string $entityType, int $entityId, ?int $commentId = null): void
    {
        UserNotification::create([
            'user_id' => $this->user->id,
            'notification_type' => 'task_comment',
            'actor_user_id' => $this->user->id,
            'title' => 'Neuer Kommentar',
            'body' => 'Bitte Noten mitbringen.',
            'link' => '/' . $entityType . 's/' . $entityId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'comment_id' => $commentId,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function countBellEntries(string $entityType, int $entityId): int
    {
        return UserNotification::where('entity_type', $entityType)->where('entity_id', $entityId)->count();
    }

    private function countAttachments(string $entityType, int $entityId): int
    {
        return Attachment::where('entity_type', $entityType)->where('entity_id', $entityId)->count();
    }

    private function countComments(string $entityType, int $entityId): int
    {
        return Comment::where('entity_type', $entityType)->where('entity_id', $entityId)->count();
    }

    private function countActivities(string $entityType, int $entityId): int
    {
        return Activity::where('entity_type', $entityType)->where('entity_id', $entityId)->count();
    }
}
