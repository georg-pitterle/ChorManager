<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use App\Services\NewsletterLockingService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Bearbeitungssperre eines Newsletters muss auch dann halten, wenn zwei
 * Requests gleichzeitig eintreffen und beide einen Stand vor der Sperre gelesen
 * haben.
 */
final class NewsletterLockingFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "lock_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Sperr',
            'last_name' => 'Test',
            'is_active' => 1,
        ]);
    }

    private function createDraft(User $creator): Newsletter
    {
        $project = Project::create(['name' => 'Sperr-Projekt ' . bin2hex(random_bytes(4))]);

        return Newsletter::create([
            'project_id' => $project->id,
            'title' => 'Gesperrter Entwurf',
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);
    }

    public function testASecondWriterCannotOverwriteAnActiveLock(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $draft = $this->createDraft($userA);

        // Beide Requests haben den Newsletter geladen, bevor irgendjemand
        // gesperrt hat - beide Instanzen sehen "nicht gesperrt".
        $forUserA = Newsletter::findOrFail($draft->id);
        $forUserB = Newsletter::findOrFail($draft->id);

        $service = new NewsletterLockingService();

        $this->assertTrue($service->acquireLock($forUserA, (int) $userA->id));
        $this->assertFalse(
            $service->acquireLock($forUserB, (int) $userB->id),
            'Eine fremde, gültige Sperre darf nicht übernommen werden.'
        );

        $stored = Newsletter::findOrFail($draft->id);
        $this->assertSame((int) $userA->id, (int) $stored->locked_by);
    }

    public function testTheOwnerCanRenewItsOwnLock(): void
    {
        $userA = $this->createUser();
        $draft = $this->createDraft($userA);

        $service = new NewsletterLockingService();

        $this->assertTrue($service->acquireLock($draft, (int) $userA->id));
        $this->assertTrue(
            $service->acquireLock($draft, (int) $userA->id),
            'Der Inhaber muss seine eigene Sperre verlängern können.'
        );
        $this->assertSame((int) $userA->id, (int) Newsletter::findOrFail($draft->id)->locked_by);
    }

    public function testAnExpiredLockIsTakenOverByTheNextWriter(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $draft = $this->createDraft($userA);

        $draft->update([
            'locked_by' => $userA->id,
            'locked_at' => Carbon::now()->subHours(2),
        ]);

        $service = new NewsletterLockingService();

        $this->assertTrue(
            $service->acquireLock(Newsletter::findOrFail($draft->id), (int) $userB->id),
            'Eine abgelaufene Sperre darf übernommen werden.'
        );
        $this->assertSame((int) $userB->id, (int) Newsletter::findOrFail($draft->id)->locked_by);
    }

    public function testReleasingALockClearsBothColumns(): void
    {
        $userA = $this->createUser();
        $draft = $this->createDraft($userA);

        $service = new NewsletterLockingService();
        $service->acquireLock($draft, (int) $userA->id);
        $service->releaseLock($draft);

        $stored = Newsletter::findOrFail($draft->id);
        $this->assertNull($stored->locked_by);
        $this->assertNull($stored->locked_at);
    }
}
