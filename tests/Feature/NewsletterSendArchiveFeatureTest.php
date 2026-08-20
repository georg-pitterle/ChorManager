<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipient;
use App\Models\NewsletterRecipientSource;
use App\Models\Project;
use App\Models\User;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Behavioural coverage for the send flow: the audience is resolved fresh at
 * send time and every recipient receives a personal archive entry.
 */
final class NewsletterSendArchiveFeatureTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The send flow only enqueues mails; the actual provider is never hit
        // here. Disabling send keeps the queue worker (if triggered elsewhere)
        // from attempting real delivery.
        $_ENV['DISABLE_MAIL_SEND'] = $_SERVER['DISABLE_MAIL_SEND'] = 'true';

        self::$capsule?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function makeService(): NewsletterService
    {
        return new NewsletterService(
            new NewsletterRecipientService(),
            new Mailer(new NullLogger()),
            new HtmlSanitizer(),
            new MailQueueService(),
            new NullLogger()
        );
    }

    private function createUser(bool $active = true): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "recipient_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Test',
            'last_name' => 'User',
            'is_active' => $active ? 1 : 0,
        ]);
    }

    private function createProject(): Project
    {
        return Project::create([
            'name' => 'Send Archive Project ' . bin2hex(random_bytes(4)),
        ]);
    }

    private function createDraft(Project $project, User $creator): Newsletter
    {
        $newsletter = Newsletter::create([
            'project_id' => $project->id,
            'title' => 'Behavioural Newsletter',
            'content_html' => '<p>Hallo Chor!</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
            'reference_id' => $project->id,
        ]);

        return $newsletter;
    }

    public function testSendWritesArchiveEntryForEachResolvedRecipient(): void
    {
        $project = $this->createProject();
        $memberA = $this->createUser();
        $memberB = $this->createUser();
        $project->users()->attach([$memberA->id, $memberB->id]);

        $newsletter = $this->createDraft($project, $memberA);

        $sentCount = $this->makeService()->send($newsletter, (int) $memberA->id);

        $this->assertSame(2, $sentCount);

        $archivedUserIds = NewsletterArchive::query()
            ->where('newsletter_id', $newsletter->id)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $expected = [(int) $memberA->id, (int) $memberB->id];
        sort($expected);

        $this->assertSame($expected, $archivedUserIds);

        $archiveForA = NewsletterArchive::query()
            ->where('newsletter_id', $newsletter->id)
            ->where('user_id', $memberA->id)
            ->first();

        $this->assertNotNull($archiveForA);
        $this->assertSame($memberA->email, $archiveForA->email);
        $this->assertNotNull($archiveForA->sent_at);

        $newsletter->refresh();
        $this->assertSame(Newsletter::STATUS_SENT, $newsletter->status);
    }

    public function testSendReflectsCurrentMembershipNotStaleSnapshot(): void
    {
        $project = $this->createProject();
        $memberA = $this->createUser();
        $project->users()->attach($memberA->id);

        $recipientService = new NewsletterRecipientService();
        $newsletter = $this->createDraft($project, $memberA);

        // Snapshot taken while only member A belongs to the project.
        $recipientService->setRecipients($newsletter, [(int) $memberA->id]);

        // A second member joins after the draft was saved.
        $memberB = $this->createUser();
        $project->users()->attach($memberB->id);

        $this->makeService()->send($newsletter, (int) $memberA->id);

        $archivedUserIds = NewsletterArchive::query()
            ->where('newsletter_id', $newsletter->id)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains((int) $memberA->id, $archivedUserIds);
        $this->assertContains(
            (int) $memberB->id,
            $archivedUserIds,
            'A member added after the snapshot must still receive the newsletter.'
        );
    }

    public function testSendExcludesDeactivatedUsersEvenWhenStoredAsRecipients(): void
    {
        $project = $this->createProject();
        $activeMember = $this->createUser(true);
        $inactiveMember = $this->createUser(false);
        $project->users()->attach([$activeMember->id, $inactiveMember->id]);

        $recipientService = new NewsletterRecipientService();
        $newsletter = $this->createDraft($project, $activeMember);

        // Stale snapshot that still lists the (now) deactivated member.
        $recipientService->setRecipients(
            $newsletter,
            [(int) $activeMember->id, (int) $inactiveMember->id]
        );

        $sentCount = $this->makeService()->send($newsletter, (int) $activeMember->id);

        $this->assertSame(1, $sentCount);

        $archivedUserIds = NewsletterArchive::query()
            ->where('newsletter_id', $newsletter->id)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertSame([(int) $activeMember->id], $archivedUserIds);

        $storedRecipientIds = NewsletterRecipient::query()
            ->where('newsletter_id', $newsletter->id)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertSame([(int) $activeMember->id], $storedRecipientIds);
    }

    /**
     * Doppelklick oder zwei gleichzeitige Vorstände: Beide Requests laden je eine
     * eigene Instanz desselben Entwurfs und sehen "draft". Der Übergang
     * Entwurf -> Versendet muss genau einem Versandlauf gehören, sonst geht der
     * Newsletter doppelt raus.
     */
    public function testConcurrentSendClaimsTheDraftExactlyOnce(): void
    {
        $project = $this->createProject();
        $member = $this->createUser();
        $project->users()->attach($member->id);

        $draft = $this->createDraft($project, $member);

        $first = Newsletter::findOrFail($draft->id);
        $second = Newsletter::findOrFail($draft->id);

        $service = $this->makeService();
        $service->send($first, (int) $member->id);

        $secondRejected = false;
        try {
            $service->send($second, (int) $member->id);
        } catch (\Exception) {
            $secondRejected = true;
        }

        $this->assertTrue(
            $secondRejected,
            'Ein zweiter Versandlauf desselben Entwurfs muss abgelehnt werden.'
        );

        $this->assertSame(
            1,
            $this->queuedMailCount((int) $draft->id),
            'Der Newsletter darf nur einmal in die Mail-Queue eingereiht werden.'
        );

        $this->assertSame(
            1,
            NewsletterArchive::query()->where('newsletter_id', $draft->id)->count()
        );

        $draft->refresh();
        $this->assertSame(Newsletter::STATUS_SENT, $draft->status);
    }

    /**
     * Schlägt der Versand fehl, bevor eine einzige Mail in der Queue liegt, muss
     * der Entwurf wieder freigegeben werden - sonst wäre er dauerhaft blockiert.
     */
    public function testFailedSendReleasesTheDraftAgain(): void
    {
        $project = $this->createProject();
        $member = $this->createUser();
        $project->users()->attach($member->id);

        $newsletter = $this->createDraft($project, $member);

        // Ohne gültige Adresse wird keine Mail eingereiht.
        $member->email = 'kaputte-adresse';
        $member->save();

        $failed = false;
        try {
            $this->makeService()->send($newsletter, (int) $member->id);
        } catch (\Exception) {
            $failed = true;
        }

        $this->assertTrue($failed);

        $newsletter->refresh();
        $this->assertSame(
            Newsletter::STATUS_DRAFT,
            $newsletter->status,
            'Ein fehlgeschlagener Versand darf den Entwurf nicht als versendet zurücklassen.'
        );
        $this->assertNull($newsletter->sent_at);
    }

    private function queuedMailCount(int $newsletterId): int
    {
        return MailQueue::query()
            ->where('mail_type', 'newsletter')
            ->where('payload_json', 'like', '%"newsletter_id":' . $newsletterId . '%')
            ->count();
    }
}
