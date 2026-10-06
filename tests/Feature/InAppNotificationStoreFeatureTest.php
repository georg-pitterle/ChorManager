<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\InAppMessage;
use App\Services\Notifications\InAppNotificationStore;
use App\Util\NotificationType;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Glocke einer Person zeigt nur ihre eigenen Einträge, und „gelesen“
 * trifft nie die Einträge anderer.
 */
final class InAppNotificationStoreFeatureTest extends TestCase
{
    private User $anna;
    private User $bernd;
    private InAppNotificationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->anna = $this->createUser('Anna', 'Amsel');
        $this->bernd = $this->createUser('Bernd', 'Buchfink');
        $this->store = new InAppNotificationStore();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testCreateManyWritesOneEntryPerRecipient(): void
    {
        $created = $this->store->createMany(
            NotificationType::TASK_COMMENT,
            new InAppMessage('Neuer Kommentar: Saal', 'Ist reserviert.', '/tasks/7', 'task', 7),
            [(int) $this->anna->id, (int) $this->bernd->id],
            null
        );

        $this->assertSame(2, $created);
        $entry = UserNotification::where('user_id', $this->anna->id)->firstOrFail();
        $this->assertSame('Neuer Kommentar: Saal', $entry->title);
        $this->assertSame('Ist reserviert.', $entry->body);
        $this->assertSame('/tasks/7', $entry->link);
        $this->assertSame('task', $entry->entity_type);
        $this->assertSame(7, $entry->entity_id);
        $this->assertNull($entry->read_at);
        $this->assertNotNull($entry->created_at);
    }

    public function testCreateManyWithoutRecipientsWritesNothing(): void
    {
        $this->assertSame(0, $this->store->createMany(
            NotificationType::TASK_COMMENT,
            new InAppMessage('T', null, '/x'),
            [],
            null
        ));
    }

    public function testUnreadCountOnlyCountsOwnUnreadEntries(): void
    {
        $this->entry($this->anna);
        $this->entry($this->anna, readAt: Carbon::now());
        $this->entry($this->bernd);

        $this->assertSame(1, $this->store->unreadCount((int) $this->anna->id));
    }

    public function testRecentReturnsTheNewestOwnEntriesFirst(): void
    {
        $this->entry($this->anna, title: 'alt', createdAt: Carbon::now()->subHour());
        $this->entry($this->anna, title: 'neu', createdAt: Carbon::now());
        $this->entry($this->bernd, title: 'fremd');

        $titles = $this->store->recent((int) $this->anna->id, 10)->pluck('title')->all();

        $this->assertSame(['neu', 'alt'], $titles);
    }

    public function testPaginateCanFilterUnreadAndCountsPages(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->entry($this->anna, createdAt: Carbon::now()->subMinutes($i));
        }
        $this->entry($this->anna, readAt: Carbon::now());

        $all = $this->store->paginate((int) $this->anna->id, 2, false);
        $unread = $this->store->paginate((int) $this->anna->id, 1, true);

        $this->assertSame(31, $all['total']);
        $this->assertSame(2, $all['pages']);
        $this->assertSame(2, $all['page']);
        $this->assertCount(6, $all['items']);
        $this->assertSame(30, $unread['total']);
    }

    public function testPaginateClampsAPageBeyondTheEnd(): void
    {
        $this->entry($this->anna);

        $this->assertSame(1, $this->store->paginate((int) $this->anna->id, 99, false)['page']);
    }

    public function testFindForUserIgnoresForeignEntries(): void
    {
        $foreign = $this->entry($this->bernd);

        $this->assertNull($this->store->findForUser((int) $this->anna->id, (int) $foreign->id));
        $this->assertNotNull($this->store->findForUser((int) $this->bernd->id, (int) $foreign->id));
    }

    public function testMarkAllReadLeavesOtherPeopleAlone(): void
    {
        $this->entry($this->anna);
        $foreign = $this->entry($this->bernd);

        $this->assertSame(1, $this->store->markAllRead((int) $this->anna->id));
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function testMarkEntityReadOnlyTouchesThatObjectAndThatPerson(): void
    {
        $match = $this->entry($this->anna, entityType: 'task', entityId: 7);
        $otherTask = $this->entry($this->anna, entityType: 'task', entityId: 8);
        $otherPerson = $this->entry($this->bernd, entityType: 'task', entityId: 7);

        $this->assertSame(1, $this->store->markEntityRead((int) $this->anna->id, 'task', 7));
        $this->assertNotNull($match->fresh()->read_at);
        $this->assertNull($otherTask->fresh()->read_at);
        $this->assertNull($otherPerson->fresh()->read_at);
    }

    public function testPruneKeepsEntriesInsideTheirRetention(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $readOld = $this->entry($this->anna, readAt: Carbon::now()->subDays(31), createdAt: Carbon::now()->subDays(31));
        $readFresh = $this->entry($this->anna, readAt: Carbon::now()->subDays(29), createdAt: Carbon::now()->subDays(40));
        $unreadOld = $this->entry($this->anna, createdAt: Carbon::now()->subDays(181));
        $unreadFresh = $this->entry($this->anna, createdAt: Carbon::now()->subDays(179));

        $this->assertSame(2, $this->store->prune());
        $this->assertNull($readOld->fresh());
        $this->assertNotNull($readFresh->fresh());
        $this->assertNull($unreadOld->fresh());
        $this->assertNotNull($unreadFresh->fresh());
    }

    private function entry(
        User $user,
        string $title = 'Eintrag',
        ?Carbon $readAt = null,
        ?Carbon $createdAt = null,
        ?string $entityType = null,
        ?int $entityId = null
    ): UserNotification {
        return UserNotification::create([
            'user_id' => $user->id,
            'notification_type' => NotificationType::TASK_COMMENT,
            'title' => $title,
            'link' => '/tasks/1',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'read_at' => $readAt,
            'created_at' => $createdAt ?? Carbon::now(),
        ]);
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'bell.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }
}
