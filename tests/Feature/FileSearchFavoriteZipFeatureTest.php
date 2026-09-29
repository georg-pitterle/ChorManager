<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolder;
use App\Models\FileFolderShare as Share;
use App\Models\StoredFile;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use App\Services\Files\FileFavoriteService;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileSearchService;
use App\Services\Files\FileService;
use App\Services\Files\FileStorageRegistry;
use App\Services\Files\FileZipService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

class FileSearchFavoriteZipFeatureTest extends TestCase
{
    use FileFixtures;

    private FileAccessService $access;
    private FileService $files;
    private FileFolderService $folders;
    private FileStorageRegistry $registry;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $this->access = new FileAccessService();
        $quota = new FileQuotaService($this->access, 0);
        $this->folders = new FileFolderService($this->access, $quota, new NullLogger());
        $this->registry = new FileStorageRegistry($this->storage());
        $this->files = new FileService($this->access, $this->folders, $quota, $this->registry, new NullLogger(), 1 << 20, 5);
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function put(FileFolder $folder, FileActor $actor, string $name, string $content): StoredFile
    {
        $stream = (new StreamFactory())->createStream($content);

        return $this->files->upload($actor, $folder, new UploadedFile($stream, $name, 'text/plain', strlen($content)))
            ->file;
    }

    public function testSearchFindsOnlyVisibleLiveEntriesWithPath(): void
    {
        $member = $this->actor($this->createMember());
        $admin = $this->actor($this->createMember(), true);
        $open = $this->createFolder('Noten ' . bin2hex(random_bytes(3)));
        $closed = $this->createFolder('Vorstand ' . bin2hex(random_bytes(3)));
        $sub = $this->createFolder('Messe-Unterordner', $open);
        $this->share($open, Share::TYPE_USER, $member->userId, Share::LEVEL_EDIT);
        $token = 'xq' . bin2hex(random_bytes(3));

        $this->put($sub, $member, "Messe {$token}.pdf", 'a');
        $trashed = $this->put($open, $member, "Alt {$token}.pdf", 'b');
        $this->files->trashFile($member, $trashed);
        $this->put($closed, $admin, "Geheim {$token}.pdf", 'c');

        $result = (new FileSearchService($this->access))->search($member, $token);

        $this->assertSame(["Messe {$token}.pdf"], array_column($result['files'], 'name'));
        $this->assertSame([$open->name, 'Messe-Unterordner'], array_column($result['files'][0]['path'], 'name'));
        $this->assertSame([], (new FileSearchService($this->access))->search($member, 'x')['files'], 'Zu kurz.');
    }

    public function testSearchEscapesWildcards(): void
    {
        $member = $this->actor($this->createMember());
        $root = $this->createFolder('Wurzel');
        $this->share($root, Share::TYPE_USER, $member->userId, Share::LEVEL_UPLOAD);
        $this->put($root, $member, 'abcdef.txt', 'a');

        $this->assertSame([], (new FileSearchService($this->access))->search($member, 'a%f')['files']);
    }

    public function testFavoritesToggleAndHideWhatIsNoLongerVisible(): void
    {
        $member = $this->actor($this->createMember());
        $root = $this->createFolder('Wurzel');
        $share = $this->share($root, Share::TYPE_USER, $member->userId, Share::LEVEL_UPLOAD);
        $file = $this->put($root, $member, 'Lieblings.pdf', 'a');
        $favorites = new FileFavoriteService($this->access);

        $this->assertTrue($favorites->toggle($member, 'file', (int) $file->id));
        $this->assertTrue($favorites->toggle($member, 'folder', (int) $root->id));
        $listed = $favorites->listFor($member);
        $this->assertSame(['Lieblings.pdf'], array_column($listed['files'], 'name'));
        $this->assertSame(['Wurzel'], array_column($listed['folders'], 'name'));

        $this->assertFalse($favorites->toggle($member, 'file', (int) $file->id));
        $this->assertSame([], $favorites->listFor($member)['files']);

        $share->delete();
        $this->assertSame([], $favorites->listFor($member)['folders']);
    }

    public function testFavoriteOfInvisibleEntryIsRejected(): void
    {
        $member = $this->actor($this->createMember());
        $root = $this->createFolder('Fremd');

        $this->expectException(FileManagementException::class);
        (new FileFavoriteService($this->access))->toggle($member, 'folder', (int) $root->id);
    }

    public function testZipContainsLiveTreeWithRelativePaths(): void
    {
        $member = $this->actor($this->createMember());
        $root = $this->createFolder('Konzert');
        $sub = $this->createFolder('Plakate', $root);
        $gone = $this->createFolder('Weg', $root);
        $this->share($root, Share::TYPE_USER, $member->userId, Share::LEVEL_EDIT);
        $this->put($root, $member, 'Programm.txt', 'Programm');
        $this->put($sub, $member, 'A3.txt', 'Plakat');
        $this->put($gone, $member, 'x.txt', 'x');
        $trashed = $this->put($root, $member, 'Entwurf.txt', 'alt');
        $this->files->trashFile($member, $trashed);
        $this->folders->trash($member, $gone);

        $zipPath = (new FileZipService($this->access, $this->folders, $this->registry, 1 << 20))->build($member, $root);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        $this->assertSame(['Konzert/Plakate/', 'Konzert/Plakate/A3.txt', 'Konzert/Programm.txt'], $names);
        $this->assertSame('Plakat', $zip->getFromName('Konzert/Plakate/A3.txt'));
        $zip->close();
        unlink($zipPath);
    }

    public function testZipRespectsSizeLimitAndAccess(): void
    {
        $member = $this->actor($this->createMember());
        $stranger = $this->actor($this->createMember());
        $root = $this->createFolder('Gross');
        $this->share($root, Share::TYPE_USER, $member->userId, Share::LEVEL_UPLOAD);
        $this->put($root, $member, 'a.txt', str_repeat('a', 20));

        try {
            (new FileZipService($this->access, $this->folders, $this->registry, 10))->build($member, $root);
            $this->fail('Größenlimit ignoriert.');
        } catch (FileManagementException $exception) {
            $this->assertSame(413, $exception->status);
        }

        $this->expectException(FileManagementException::class);
        (new FileZipService($this->access, $this->folders, $this->registry, 1 << 20))->build($stranger, $root);
    }
}
