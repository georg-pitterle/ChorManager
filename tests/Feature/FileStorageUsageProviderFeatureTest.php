<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolder;
use App\Models\FileVersion;
use App\Models\StoredFile;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileService;
use App\Services\Files\FileStorageRegistry;
use App\Services\Storage\FileStorageUsageProvider;
use App\Services\Storage\StorageUsageNode;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * Zuordnung der Speicherpfade: aktuell vor älterer Version vor Papierkorb, jeder
 * Pfad genau einmal. Die Summe muss dem Kontingent-Zähler entsprechen.
 */
final class FileStorageUsageProviderFeatureTest extends TestCase
{
    use FileFixtures;

    private FileService $files;
    private FileFolderService $folders;
    private FileQuotaService $quota;
    private FileAccessService $access;
    private FileActor $admin;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $this->access = new FileAccessService();
        $this->quota = new FileQuotaService($this->access, 0);
        $this->folders = new FileFolderService($this->access, $this->quota, new NullLogger());
        $registry = new FileStorageRegistry($this->storage());
        $this->files = new FileService(
            $this->access,
            $this->folders,
            $this->quota,
            $registry,
            new NullLogger(),
            1 << 20,
            10
        );
        $this->admin = $this->actor($this->createMember('Admin'), true);
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function upload(FileFolder $folder, string $name, string $content): StoredFile
    {
        $stream = (new StreamFactory())->createStream($content);

        return $this->files->upload(
            $this->admin,
            $folder,
            new UploadedFile($stream, $name, 'text/plain', strlen($content))
        )->file;
    }

    private function usage(): StorageUsageNode
    {
        FileAccessService::invalidate();

        return (new FileStorageUsageProvider($this->access, $this->quota))->usage();
    }

    public function testCurrentOldAndTrashAreSeparated(): void
    {
        $root = $this->createFolder('Wurzel', null, 5000);
        $this->upload($root, 'plan.txt', str_repeat('a', 100));
        // Neue Version: die 100 Byte werden zur älteren Version.
        $this->upload($root, 'plan.txt', str_repeat('b', 40));
        $gone = $this->upload($root, 'weg.txt', str_repeat('c', 7));
        $this->files->trashFile($this->admin, $gone);
        $sub = $this->createFolder('Unterordner', $root);
        $this->upload($sub, 'drin.txt', str_repeat('d', 3));
        $this->folders->trash($this->admin, $sub);

        $node = $this->usage();

        $this->assertSame(40, $node->child('files.current')?->bytes);
        $this->assertSame(1, $node->child('files.current')?->count);
        $this->assertSame(100, $node->child('files.versions.old')?->bytes);
        $this->assertSame(10, $node->child('files.trash')?->bytes, 'Gelöschte Datei und Datei im gelöschten Ordner.');
        $this->assertSame(2, $node->child('files.trash')?->count);
        $this->assertSame(150, $node->bytes);
        $this->assertSame($this->quota->totalUsedBytes(), $node->bytes);
    }

    /**
     * Eine zurückgeholte alte Version zeigt auf denselben Pfad wie die alte. Der Pfad
     * zählt einmal - und zwar als aktuell, nicht zusätzlich als ältere Version.
     */
    public function testSharedStoragePathCountsOnceInTheHighestCategory(): void
    {
        $root = $this->createFolder('Wurzel');
        $this->upload($root, 'lied.txt', str_repeat('a', 100));
        $file = $this->upload($root, 'lied.txt', str_repeat('b', 40));
        $first = FileVersion::query()->where('file_id', $file->id)->orderBy('version_number')->firstOrFail();
        $this->files->restoreVersion($this->admin, $first);

        $node = $this->usage();

        $this->assertSame(100, $node->child('files.current')?->bytes);
        $this->assertSame(40, $node->child('files.versions.old')?->bytes);
        $this->assertSame(140, $node->bytes);
        $this->assertSame($this->quota->totalUsedBytes(), $node->bytes);
    }

    public function testTeamFoldersAreABreakdownWithQuota(): void
    {
        $choir = $this->createFolder('Chor', null, 1000);
        $board = $this->createFolder('Vorstand');
        $this->upload($choir, 'a.txt', str_repeat('a', 30));
        $this->upload($board, 'b.txt', str_repeat('b', 12));

        $node = $this->usage();
        $teams = $node->child('files.team_folders');

        $this->assertNotNull($teams);
        $this->assertTrue($teams->breakdown);
        $this->assertSame(42, $node->bytes, 'Die Aufschlüsselung verdoppelt die Summe nicht.');
        $choirNode = $teams->child('files.team_folders.' . $choir->id);
        $this->assertSame(30, $choirNode?->bytes);
        $this->assertSame(1000, $choirNode?->quotaBytes);
        $this->assertSame('Chor', $choirNode?->label);
        $this->assertNull($teams->child('files.team_folders.' . $board->id)?->quotaBytes);
    }

    public function testEmptyStorageIsZero(): void
    {
        $node = $this->usage();

        $this->assertSame('files', $node->key);
        $this->assertSame(0, $node->bytes);
        $this->assertSame(0, $node->child('files.trash')?->bytes);
    }
}
