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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

class FileServiceFeatureTest extends TestCase
{
    use FileFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function service(int $maxUpload = 1024 * 1024, int $maxVersions = 3, int $totalQuota = 0): FileService
    {
        $access = new FileAccessService();
        $quota = new FileQuotaService($access, $totalQuota);

        return new FileService(
            $access,
            new FileFolderService($access, $quota, new NullLogger()),
            $quota,
            new FileStorageRegistry($this->storage()),
            new NullLogger(),
            $maxUpload,
            $maxVersions
        );
    }

    private function upload(string $name, string $content, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $stream = (new StreamFactory())->createStream($content);

        return new UploadedFile($stream, $name, 'application/octet-stream', strlen($content), $error);
    }

    /** @return array{0: FileFolder, 1: \App\Services\Files\FileActor} */
    private function folderWithLevel(int $level, ?int $quota = null): array
    {
        $member = $this->createMember();
        $root = $this->createFolder('Teamordner', null, $quota);
        $this->share($root, Share::TYPE_USER, (int) $member->id, $level);

        return [$root, $this->actor($member)];
    }

    public function testUploadStoresFileAndFirstVersion(): void
    {
        [$folder, $actor] = $this->folderWithLevel(Share::LEVEL_UPLOAD);

        $result = $this->service()->upload($actor, $folder, $this->upload('Probenplan.pdf', '%PDF-1.4 Inhalt'));

        $file = $result->file;
        $this->assertFalse($result->isNewVersion);
        $this->assertSame('Probenplan.pdf', $file->name);
        $this->assertSame(15, $file->size);
        $version = FileVersion::find($file->current_version_id);
        $this->assertSame(1, $version->version_number);
        $this->assertSame(hash('sha256', '%PDF-1.4 Inhalt'), $version->sha256);
        $this->assertSame('%PDF-1.4 Inhalt', $this->storage()->read($version->storage_path));
    }

    public function testReaderCannotUpload(): void
    {
        [$folder, $actor] = $this->folderWithLevel(Share::LEVEL_READ);

        try {
            $this->service()->upload($actor, $folder, $this->upload('a.txt', 'x'));
            $this->fail('Hochladen mit Stufe Lesen erlaubt.');
        } catch (FileManagementException $exception) {
            $this->assertSame(403, $exception->status);
        }
        $this->assertSame(0, StoredFile::query()->where('folder_id', $folder->id)->count());
    }

    public function testSameNameCreatesNewVersionOnlyWithEditLevel(): void
    {
        [$folder, $actor] = $this->folderWithLevel(Share::LEVEL_UPLOAD);
        $service = $this->service();
        $service->upload($actor, $folder, $this->upload('Noten.pdf', 'v1'));

        try {
            $service->upload($actor, $folder, $this->upload('noten.pdf', 'v2'));
            $this->fail('Überschreiben mit Stufe Hochladen erlaubt.');
        } catch (FileManagementException $exception) {
            $this->assertSame(409, $exception->status);
        }

        [$editFolder, $editor] = $this->folderWithLevel(Share::LEVEL_EDIT);
        $service->upload($editor, $editFolder, $this->upload('Noten.pdf', 'v1'));
        $result = $service->upload($editor, $editFolder, $this->upload('Noten.pdf', 'v2-länger'));

        $this->assertTrue($result->isNewVersion);
        $this->assertSame(1, StoredFile::query()->where('folder_id', $editFolder->id)->count());
        $this->assertSame(2, $result->file->currentVersion->version_number);
        $this->assertSame(strlen('v2-länger'), $result->file->size);
    }

    public function testOldestVersionsArePrunedWithTheirStorage(): void
    {
        [$folder, $actor] = $this->folderWithLevel(Share::LEVEL_EDIT);
        $service = $this->service(maxVersions: 2);

        $first = $service->upload($actor, $folder, $this->upload('Plan.txt', 'eins'))->file;
        $firstPath = FileVersion::find($first->current_version_id)->storage_path;
        $service->upload($actor, $folder, $this->upload('Plan.txt', 'zwei'));
        $service->upload($actor, $folder, $this->upload('Plan.txt', 'drei'));

        $numbers = FileVersion::query()->where('file_id', $first->id)->orderBy('version_number')
            ->pluck('version_number')->all();
        $this->assertSame([2, 3], $numbers);
        $this->assertFalse($this->storage()->exists($firstPath));
    }

    public function testRejectsOversizedBlockedAndBrokenUploads(): void
    {
        [$folder, $actor] = $this->folderWithLevel(Share::LEVEL_UPLOAD);
        $service = $this->service(maxUpload: 10);

        $cases = [
            'zu groß' => [$this->upload('gross.txt', str_repeat('x', 11)), 413],
            'PHP-Datei' => [$this->upload('shell.php', '<?php echo 1;'), 422],
            'Programm' => [$this->upload('setup.EXE', 'MZ'), 422],
            'abgebrochen' => [$this->upload('teil.txt', 'x', UPLOAD_ERR_PARTIAL), 422],
            'ohne Namen' => [$this->upload('', 'x'), 422],
        ];

        foreach ($cases as $label => [$upload, $status]) {
            try {
                $service->upload($actor, $folder, $upload);
                $this->fail('Angenommen: ' . $label);
            } catch (FileManagementException $exception) {
                $this->assertSame($status, $exception->status, $label);
            }
        }
        $this->assertSame(0, StoredFile::query()->where('folder_id', $folder->id)->count());
    }

    public function testQuotaOfTeamFolderIsEnforcedIncludingSubfoldersAndTrash(): void
    {
        [$root, $actor] = $this->folderWithLevel(Share::LEVEL_EDIT, 10);
        $child = $this->createFolder('Unterordner', $root);
        $service = $this->service();

        $file = $service->upload($actor, $child, $this->upload('a.txt', '123456'))->file;
        $service->trashFile($actor, $file);

        try {
            $service->upload($actor, $root, $this->upload('b.txt', '12345'));
            $this->fail('Kontingent überschritten.');
        } catch (FileManagementException $exception) {
            $this->assertSame(413, $exception->status);
        }

        $service->upload($actor, $root, $this->upload('c.txt', '1234'));
        $this->assertSame(10, (new FileQuotaService(new FileAccessService(), 0))->usedBytesInSubtree((int) $root->id));
    }

    public function testTotalQuotaIsEnforced(): void
    {
        [$folder, $actor] = $this->folderWithLevel(Share::LEVEL_UPLOAD);
        $used = (new FileQuotaService(new FileAccessService(), 0))->totalUsedBytes();

        $this->expectException(FileManagementException::class);
        $this->service(totalQuota: $used + 3)->upload($actor, $folder, $this->upload('a.txt', '1234'));
    }

    public function testRestoreVersionMakesOldContentCurrentWithoutCopy(): void
    {
        [$folder, $actor] = $this->folderWithLevel(Share::LEVEL_EDIT);
        $service = $this->service();
        $file = $service->upload($actor, $folder, $this->upload('Text.txt', 'alt'))->file;
        $oldVersion = FileVersion::find($file->current_version_id);
        $service->upload($actor, $folder, $this->upload('Text.txt', 'neu'));

        $restored = $service->restoreVersion($actor, $oldVersion);

        $current = $restored->currentVersion;
        $this->assertSame(3, $current->version_number);
        $this->assertSame($oldVersion->storage_path, $current->storage_path);
        $this->assertSame('alt', $this->storage()->read($current->storage_path));
    }

    public function testPrunedVersionKeepsStorageStillUsedByAnotherVersion(): void
    {
        [$folder, $actor] = $this->folderWithLevel(Share::LEVEL_EDIT);
        $service = $this->service(maxVersions: 2);
        $file = $service->upload($actor, $folder, $this->upload('T.txt', 'eins'))->file;
        $first = FileVersion::find($file->current_version_id);
        $service->upload($actor, $folder, $this->upload('T.txt', 'zwei'));

        $service->restoreVersion($actor, $first);

        $this->assertTrue($this->storage()->exists($first->storage_path));
        $this->assertSame('eins', $this->storage()->read($file->fresh()->currentVersion->storage_path));
    }

    public function testRenameAndMoveFile(): void
    {
        [$root, $actor] = $this->folderWithLevel(Share::LEVEL_EDIT);
        $target = $this->createFolder('Ziel', $root);
        $service = $this->service();
        $file = $service->upload($actor, $root, $this->upload('a.txt', 'a'))->file;
        $service->upload($actor, $target, $this->upload('b.txt', 'b'));

        $service->renameFile($actor, $file, 'erst.txt');
        $this->assertSame('erst.txt', $file->fresh()->name);

        $service->renameFile($actor, $file, 'b.txt');
        try {
            $service->moveFile($actor, $file->fresh(), $target);
            $this->fail('Verschieben auf vorhandenen Namen erlaubt.');
        } catch (FileManagementException $exception) {
            $this->assertSame(409, $exception->status);
        }

        $service->renameFile($actor, $file->fresh(), 'c.txt');
        $service->moveFile($actor, $file->fresh(), $target);
        $this->assertSame((int) $target->id, $file->fresh()->folder_id);
    }

    public function testFileInvisibleFolderIsNotFound(): void
    {
        $stranger = $this->actor($this->createMember());
        [$folder, $actor] = $this->folderWithLevel(Share::LEVEL_UPLOAD);
        $file = $this->service()->upload($actor, $folder, $this->upload('a.txt', 'a'))->file;

        try {
            $this->service()->findReadable($stranger, (int) $file->id);
            $this->fail('Fremde Datei gefunden.');
        } catch (FileManagementException $exception) {
            $this->assertSame(404, $exception->status);
        }

        $this->assertSame((int) $file->id, (int) $this->service()->findReadable($actor, (int) $file->id)->id);
    }
}
