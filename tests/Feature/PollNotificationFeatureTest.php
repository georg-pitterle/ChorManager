<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PollController;
use App\Models\Poll;
use App\Models\User;
use App\Models\UserNotification;
use App\Policies\PollPolicy;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Services\NotificationService;
use App\Services\PollAudienceService;
use App\Services\PollResults;
use App\Services\PollVoteService;
use App\Util\NotificationType;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Eine neue Umfrage meldet sich bei ihrer Zielgruppe - per Mail und Glocke,
 * im Formular abwählbar, und nur mit eingeschaltetem Modul.
 */
final class PollNotificationFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;
    use PollFixtures;

    private User $manager;
    private User $member;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->manager = $this->makePollMember('Leitung');
        $this->member = $this->makePollMember('Sopran');
        $this->outsider = $this->makePollMember('Bass');
        $_SESSION = $this->pollSession($this->manager, true);
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

    public function testTheTypeBelongsToThePollsModule(): void
    {
        $this->assertContains(NotificationType::POLL_CREATED, NotificationType::all());
        $this->assertSame('polls', NotificationType::definition(NotificationType::POLL_CREATED)['module']);
    }

    public function testANewPollRingsTheBellOfItsAudienceOnly(): void
    {
        $this->createPoll(['notify_members' => '1']);
        $poll = Poll::where('title', 'Probenwunsch?')->firstOrFail();

        $entry = UserNotification::where('user_id', $this->member->id)->firstOrFail();
        $this->assertSame('Neue Umfrage: Probenwunsch?', $entry->title);
        $this->assertSame('/polls/' . $poll->id, $entry->link);
        $this->assertSame('poll', $entry->entity_type);
        $this->assertSame(0, UserNotification::where('user_id', $this->outsider->id)->count());
        $this->assertSame(0, UserNotification::where('user_id', $this->manager->id)->count());
        $this->assertSame(1, Capsule::table('mail_queue')->where('recipient_email', $this->member->email)->count());
    }

    public function testWithoutTheSwitchNobodyIsNotified(): void
    {
        $this->createPoll([]);

        $this->assertSame(0, UserNotification::where('user_id', $this->member->id)->count());
    }

    public function testWithTheModuleOffTheTypeIsUnavailable(): void
    {
        $this->createPoll(['notify_members' => '1'], false);

        $this->assertSame(0, UserNotification::where('user_id', $this->member->id)->count());
    }

    /** @param array<string, string> $extra */
    private function createPoll(array $extra, bool $moduleOn = true): void
    {
        $audience = new PollAudienceService();
        $controller = new PollController(
            $this->createAppTwig('/polls'),
            new PollPolicy($_SESSION),
            $audience,
            new PollVoteService($audience),
            new PollResults($audience),
            new NameFormatterService(),
            new NotificationService(
                new MailQueueService(),
                Twig::create(dirname(__DIR__, 2) . '/templates'),
                new NullLogger(),
                ['polls' => $moduleOn]
            ),
            new NullLogger()
        );

        $controller->create($this->makeRequest('POST', '/polls', [
            'title' => 'Probenwunsch?',
            'options' => ['Dienstag', 'Donnerstag'],
            'closes_on' => Carbon::now()->addDays(7)->format('Y-m-d'),
            'audience' => [['conditions' => ['user' => [(string) $this->member->id, (string) $this->manager->id]]]],
        ] + $extra), $this->makeResponse());
    }
}
