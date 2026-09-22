<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Song;
use App\Models\User;
use App\Services\WebdavTreeService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der Baum ist die einzige Stelle, die entscheidet, was über WebDAV sichtbar
 * ist. Es gibt keine zweite Rechteprüfung beim Ausliefern: Was der Baum nicht
 * hergibt, ist nicht adressierbar. Entsprechend prüft dieser Test beides -
 * dass fremde Projekte fehlen und dass ein Name im Baum eindeutig bleibt.
 */
final class WebdavTreeServiceTest extends TestCase
{
    private User $member;
    private User $stranger;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->member = $this->createUser('mitglied');
        $this->stranger = $this->createUser('fremd');

        $this->project = Project::create([
            'name' => 'Frühlingskonzert',
            'description' => 'Testprojekt',
            'start_date' => '2026-03-01',
            'end_date' => '2026-05-31',
        ]);

        Capsule::table('project_users')->insert([
            'project_id' => (int) $this->project->id,
            'user_id' => (int) $this->member->id,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheRootHoldsOnlyProjectsWithMembership(): void
    {
        $service = new WebdavTreeService();

        $names = $this->childNames($service, (int) $this->member->id, '');
        $this->assertContains('Frühlingskonzert', $names);

        $this->assertSame([], $this->childNames($service, (int) $this->stranger->id, ''));
    }

    public function testASongOfAForeignProjectIsNotResolvable(): void
    {
        $this->assignSong('Ave Verum', 'noten.pdf');
        $service = new WebdavTreeService();

        $this->assertNotNull($service->resolve((int) $this->member->id, 'Frühlingskonzert/Ave Verum/noten.pdf'));
        $this->assertNull($service->resolve((int) $this->stranger->id, 'Frühlingskonzert/Ave Verum/noten.pdf'));
    }

    public function testTwoSongsWithTheSameTitleKeepDistinctNames(): void
    {
        $this->assignSong('Halleluja', 'a.pdf');
        $this->assignSong('Halleluja', 'b.pdf');

        $names = $this->childNames(new WebdavTreeService(), (int) $this->member->id, 'Frühlingskonzert');

        $this->assertCount(2, $names);
        $this->assertSame($names, array_unique($names));
    }

    public function testASlashInATitleDoesNotOpenASecondLevel(): void
    {
        $this->assignSong('Kyrie / Gloria', 'satz.pdf');

        $names = $this->childNames(new WebdavTreeService(), (int) $this->member->id, 'Frühlingskonzert');

        $this->assertCount(1, $names);
        $this->assertStringNotContainsString('/', $names[0]);
    }

    public function testBuildingTheTreeDoesNotLoadTheFileContent(): void
    {
        $this->assignSong('Sanctus', 'sanctus.pdf');

        $queries = [];
        $connection = Capsule::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        $service = new WebdavTreeService();
        $node = $service->resolve((int) $this->member->id, 'Frühlingskonzert/Sanctus/sanctus.pdf');

        foreach ($connection->getQueryLog() as $entry) {
            $queries[] = (string) $entry['query'];
        }
        $connection->disableQueryLog();

        $this->assertNotNull($node);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('file_content', $query);
        }
    }

    /**
     * @return list<string>
     */
    private function childNames(WebdavTreeService $service, int $userId, string $path): array
    {
        $node = $service->resolve($userId, $path);
        if ($node === null) {
            return [];
        }

        return array_map(
            static fn($child) => $child->name,
            $service->children($userId, $node)
        );
    }

    private function assignSong(string $title, string $fileName): Song
    {
        $song = Song::create([
            'title' => $title,
            'composer' => 'Testkomponist',
            'created_by_user_id' => (int) $this->member->id,
        ]);

        Capsule::table('project_song_assignments')->insert([
            'project_id' => (int) $this->project->id,
            'song_id' => (int) $song->id,
            'note' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $content = 'Noten für ' . $title;
        Attachment::create([
            'entity_type' => 'song',
            'entity_id' => (int) $song->id,
            'filename' => bin2hex(random_bytes(8)) . '_' . $fileName,
            'original_name' => $fileName,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($content),
            'file_content' => $content,
        ]);

        return $song;
    }

    private function createUser(string $prefix): User
    {
        return User::create([
            'email' => $prefix . '-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }
}
