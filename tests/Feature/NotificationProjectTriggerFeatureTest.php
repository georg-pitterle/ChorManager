<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use App\Persistence\ProjectPersistence;
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Services\NotificationService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Wer einem Projekt zugeordnet wird, findet das auch in der Glocke - mit einem
 * Link auf die Mitgliederliste des Projekts.
 */
final class NotificationProjectTriggerFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $manager;
    private User $member;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->manager = $this->createUser('Mia', 'Meise');
        $this->member = $this->createUser('Nils', 'Nachtigall');
        $this->project = Project::create([
            'name' => 'Sommerkonzert ' . bin2hex(random_bytes(3)),
            'description' => 'Projekt für die Glocke',
        ]);

        $_SESSION = ['user_id' => (int) $this->manager->id];
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

    public function testAddingAMemberRingsTheirBell(): void
    {
        $this->controller()->addMember(
            $this->makeRequest('POST', '/projects/' . $this->project->id . '/members', [
                'user_id' => (string) $this->member->id,
            ]),
            $this->makeResponse(),
            ['id' => (string) $this->project->id]
        );

        $entry = UserNotification::where('user_id', $this->member->id)->firstOrFail();
        $this->assertSame('Neues Projekt: ' . $this->project->name, $entry->title);
        $this->assertSame('/projects/' . $this->project->id . '/members', $entry->link);
        $this->assertSame('project', $entry->entity_type);
        $this->assertSame((int) $this->project->id, $entry->entity_id);
        $this->assertSame((int) $this->manager->id, $entry->actor_user_id);
    }

    private function controller(): ProjectController
    {
        $policy = $this->createStub(ProjectMemberPolicy::class);
        $policy->method('canAddMember')->willReturn(true);
        $policy->method('canManageMember')->willReturn(true);

        return new ProjectController(
            $this->createStub(Twig::class),
            new ProjectQuery(new NameFormatterService()),
            $this->createStub(ProjectPersistence::class),
            $policy,
            new NullLogger(),
            new NotificationService(
                new MailQueueService(),
                Twig::create(dirname(__DIR__, 2) . '/templates'),
                new NullLogger(),
                ['tasks' => true, 'sponsoring' => true]
            )
        );
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'project.bell.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }
}
