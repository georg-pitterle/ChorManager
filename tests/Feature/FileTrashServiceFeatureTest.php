<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolder;
use App\Models\FileFolderShare as Share;
use App\Models\FileVersion;
use App\Models\StoredFile;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileService;
use App\Services\Files\FileStorageRegistry;
use App\Services\Files\FileTrashService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

class FileTrashServiceFeatureTest extends TestCase
{
    use FileFixtures;

    private FileService $files;
    private FileFolderService $folders;
    private FileTrashService $trash;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $access = new FileAccessService();
        $quota = new FileQuotaService($access, 0);
        $this->folders = new FileFolderService($access, $quota, new NullLogger());
        $registry = new FileStorageRegistry($this->storage());
        $this->files = new FileService($access, $this->folders, $quota, $registry, new NullLogger(), 1 << 20, 5);
        $this->trash = new FileTrashService($access, $this->files, $registry, new NullLogger(), 30);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownFileFixtures();
    }

    private function upload(FileFolder $folder, \App\Services\Files\FileActor $actor, string $name, string $content): StoredFile
    {
        $stream = (new StreamFactory())->createStream($content);

        return $this->files->upload($actor, $folder, new UploadedFile($stream, $name, 'text/plain', strlen($content)))
            ->file;
    }

    public function testListShowsTopLevelTrashForEditorsOnly(): void
    {
        $editor = $this->createMember('Bearbeiter');
        $reader = $this->createMember('Leser');
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', (int) $editor->id, Share::LEVEL_EDIT);
        $this->share($root, 'user', (int) $reader->id, Share::LEVEL_READ);
        $child = $this->createFolder('Alt', $root);
        $inChild = $this->upload($child, $this->actor($editor), 'drin.txt', 'x');
        $loose = $this->upload($root, $this->actor($editor), 'lose.txt', 'y');

        $this->files->trashFile($this->actor($editor), $inChild);
        $this->folders->trash($this->actor($editor), $child);
        $this->files->trashFile($this->actor($editor), $loose);

        $items = $this->trash->listFor($this->actor($editor));
        $labels = array_map(static fn (array $item): string => $item['type'] . ':' . $item['name'], $items);
        sort($labels);
        $this->assertSame(['file:lose.txt', 'folder:Alt'], $labels, 'Datei im gelöschten Ordner erscheint nicht einzeln.');
        $this->assertSame([], $this->trash->listFor($this->actor($reader)));
    }

    public function testRestoreFileRenamesOnConflict(): void
    {
        $editor = $this->actor($this->createMember());
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', $editor->userId, Share::LEVEL_EDIT);
        $old = $this->upload($root, $editor, 'Plan.txt', 'alt');
        $this->files->trashFile($editor, $old);
        $this->upload($root, $editor, 'Plan.txt', 'neu');

        $restored = $this->trash->restoreFile($editor, (int) $old->id);

        $this->assertNull($restored->deleted_at);
        $this->assertSame('Plan (wiederhergestellt).txt', $restored->name);
    }

    public function testRestoreFolderBringsContentBack(): void
    {
        $editor = $this->actor($this->createMember());
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', $editor->userId, Share::LEVEL_EDIT);
        $child = $this->createFolder('Kind', $root);
        $file = $this->upload($child, $editor, 'a.txt', 'a');
        $this->folders->trash($editor, $child);

        $this->trash->restoreFolder($editor, (int) $child->id);

        $this->assertSame((int) $file->id, (int) $this->files->findReadable($editor, (int) $file->id)->id);
    }

    public function testPurgeNeedsManageAndRemovesStorage(): void
    {
        $editor = $this->actor($this->createMember('Bearbeiter'));
        $manager = $this->actor($this->createMember('Verwalter'));
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', $editor->userId, Share::LEVEL_EDIT);
        $this->share($root, 'user', $manager->userId, Share::LEVEL_MANAGE);
        $child = $this->createFolder('Kind', $root);
        $file = $this->upload($child, $editor, 'a.txt', 'a');
        $path = FileVersion::find($file->current_version_id)->storage_path;
        $this->folders->trash($editor, $child);

        try {
            $this->trash->purgeFolder($editor, (int) $child->id);
            $this->fail('Endgültig gelöscht mit Stufe Bearbeiten.');
        } catch (FileManagementException $exception) {
            $this->assertSame(403, $exception->status);
        }

        $this->trash->purgeFolder($manager, (int) $child->id);

        $this->assertNull(FileFolder::withTrashed()->find($child->id));
        $this->assertNull(StoredFile::withTrashed()->find($file->id));
        $this->assertFalse($this->storage()->exists($path));
    }

    public function testPurgeRemovesFiltersOfShares(): void
    {
        $manager = $this->actor($this->createMember());
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', $manager->userId, Share::LEVEL_MANAGE);
        $child = $this->createFolder('Kind', $root);
        $childShare = $this->share($child, 'all_members', 0, Share::LEVEL_READ);
        $this->folders->trash($manager, $child);

        $this->trash->purgeFolder($manager, (int) $child->id);

        $this->assertSame(0, \App\Models\AudienceFilter::query()->where('file_folder_share_id', $childShare->id)->count());
    }

    public function testPurgeRefusesLiveItems(): void
    {
        $manager = $this->actor($this->createMember());
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', $manager->userId, Share::LEVEL_MANAGE);
        $file = $this->upload($root, $manager, 'a.txt', 'a');

        $this->expectException(FileManagementException::class);
        $this->trash->purgeFile($manager, (int) $file->id);
    }

    public function testPurgeExpiredRemovesOnlyOldEntries(): void
    {
        $editor = $this->actor($this->createMember());
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', $editor->userId, Share::LEVEL_EDIT);
        $old = $this->upload($root, $editor, 'alt.txt', 'a');
        $fresh = $this->upload($root, $editor, 'frisch.txt', 'b');
        $oldFolder = $this->createFolder('Alter Ordner', $root);

        Carbon::setTestNow(Carbon::now()->subDays(31));
        $this->files->trashFile($editor, $old);
        $this->folders->trash($editor, $oldFolder);
        Carbon::setTestNow();
        $this->files->trashFile($editor, $fresh);

        $result = $this->trash->purgeExpired();

        $this->assertSame(['files' => 1, 'folders' => 1], $result);
        $this->assertNull(StoredFile::withTrashed()->find($old->id));
        $this->assertNotNull(StoredFile::withTrashed()->find($fresh->id));
    }

    public function testOrphansAreFilesWithoutVersionRow(): void
    {
        $editor = $this->actor($this->createMember());
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', $editor->userId, Share::LEVEL_EDIT);
        $this->upload($root, $editor, 'bleibt.txt', 'a');
        $source = tempnam(sys_get_temp_dir(), 'orph');
        file_put_contents($source, 'verwaist');
        $orphan = $this->storage()->put($source);
        unlink($source);

        $this->assertSame([], $this->trash->findOrphans($this->storage()), 'Junge Dateien gelten nicht als verwaist.');
        $this->assertSame([$orphan], $this->trash->findOrphans($this->storage(), 0));
        $this->assertSame(1, $this->trash->deleteOrphans($this->storage(), 0));
        $this->assertFalse($this->storage()->exists($orphan));
        $this->assertSame([], $this->trash->findOrphans($this->storage(), 0));
    }
}
