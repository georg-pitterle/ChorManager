<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolderShare as Share;
use App\Models\FilePublicLink;
use App\Models\FileShare;
use App\Models\StoredFile;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileShareService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FileShareServiceFeatureTest extends TestCase
{
    use FileFixtures;

    private FileShareService $shares;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $access = new FileAccessService();
        $folders = new FileFolderService($access, new FileQuotaService($access, 0), new NullLogger());
        $this->shares = new FileShareService($access, $folders, new NullLogger());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownFileFixtures();
    }

    /** @return array{0: StoredFile, 1: \App\Services\Files\FileActor, 2: \App\Services\Files\FileActor} */
    private function fileWithManagerAndEditor(): array
    {
        $manager = $this->createMember('Verwalter');
        $editor = $this->createMember('Bearbeiter');
        $folder = $this->createFolder('Ordner');
        $this->share($folder, 'user', (int) $manager->id, Share::LEVEL_MANAGE);
        $this->share($folder, 'user', (int) $editor->id, Share::LEVEL_EDIT);
        $file = StoredFile::create([
            'folder_id' => $folder->id,
            'name' => 'a.pdf',
            'size' => 1,
            'mime_type' => 'application/pdf',
        ]);

        return [$file, $this->actor($manager), $this->actor($editor)];
    }

    public function testManagerSetsFileSharesWithReadAndEditOnly(): void
    {
        [$file, $manager] = $this->fileWithManagerAndEditor();
        $role = $this->createRoleFor($this->createMember());

        $this->shares->setShares($manager, $file, [
            ['level' => Share::LEVEL_EDIT, 'conditions' => ['role' => [(int) $role->id]]],
            ['level' => Share::LEVEL_READ, 'all' => '1'],
            ['level' => Share::LEVEL_MANAGE, 'conditions' => ['user' => [$manager->userId]]],
            ['level' => Share::LEVEL_UPLOAD, 'conditions' => ['user' => [$manager->userId]]],
        ]);

        $levels = FileShare::query()->where('file_id', $file->id)->orderBy('level')->pluck('level')->all();
        $this->assertSame([Share::LEVEL_READ, Share::LEVEL_EDIT], $levels);
    }

    public function testEditorMayNotShare(): void
    {
        [$file, , $editor] = $this->fileWithManagerAndEditor();

        $this->expectException(FileManagementException::class);
        $this->shares->setShares($editor, $file, []);
    }

    public function testPublicLinkStoresOnlyHashAndResolvesByToken(): void
    {
        [$file, $manager] = $this->fileWithManagerAndEditor();

        [$link, $token] = $this->shares->createLink($manager, $file, 'Presse', null, null);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $token);
        $this->assertSame(hash('sha256', $token), FilePublicLink::find($link->id)->token_hash);
        $this->assertStringNotContainsString($token, json_encode(FilePublicLink::find($link->id)->toArray()));
        $this->assertSame((int) $link->id, (int) $this->shares->resolvePublic($token)?->id);
        $this->assertNull($this->shares->resolvePublic($token . 'x'));
        $this->assertNull($this->shares->resolvePublic('../../etc'));
    }

    public function testExpiredRevokedOrTrashedLinksStopWorking(): void
    {
        [$file, $manager] = $this->fileWithManagerAndEditor();

        [, $expiring] = $this->shares->createLink($manager, $file, null, Carbon::now()->addDay(), null);
        Carbon::setTestNow(Carbon::now()->addDays(2));
        $this->assertNull($this->shares->resolvePublic($expiring));
        Carbon::setTestNow();

        [$revocable, $revokedToken] = $this->shares->createLink($manager, $file, null, null, null);
        $this->shares->revokeLink($manager, (int) $revocable->id);
        $this->assertNull($this->shares->resolvePublic($revokedToken));

        [, $token] = $this->shares->createLink($manager, $file, null, null, null);
        $file->delete();
        $this->assertNull($this->shares->resolvePublic($token));
    }

    public function testPasswordIsHashedAndChecked(): void
    {
        [$file, $manager] = $this->fileWithManagerAndEditor();

        [$link, $token] = $this->shares->createLink($manager, $file, null, null, 'Chor2026');

        $resolved = $this->shares->resolvePublic($token);
        $this->assertTrue($resolved->hasPassword());
        $this->assertNotSame('Chor2026', FilePublicLink::find($link->id)->password_hash);
        $this->assertTrue($this->shares->checkPassword($resolved, 'Chor2026'));
        $this->assertFalse($this->shares->checkPassword($resolved, 'falsch'));
    }

    public function testExpiryInThePastIsRejected(): void
    {
        [$file, $manager] = $this->fileWithManagerAndEditor();

        $this->expectException(FileManagementException::class);
        $this->shares->createLink($manager, $file, null, Carbon::now()->subDay(), null);
    }

    public function testEditorMayNotCreateOrRevokeLinks(): void
    {
        [$file, $manager, $editor] = $this->fileWithManagerAndEditor();
        [$link] = $this->shares->createLink($manager, $file, null, null, null);

        try {
            $this->shares->revokeLink($editor, (int) $link->id);
            $this->fail('Widerruf mit Stufe Bearbeiten erlaubt.');
        } catch (FileManagementException $exception) {
            $this->assertSame(403, $exception->status);
        }

        $this->expectException(FileManagementException::class);
        $this->shares->createLink($editor, $file, null, null, null);
    }

    public function testDownloadIsCounted(): void
    {
        [$file, $manager] = $this->fileWithManagerAndEditor();
        [$link] = $this->shares->createLink($manager, $file, null, null, null);

        $this->shares->recordDownload($link);
        $this->shares->recordDownload($link->fresh());

        $this->assertSame(2, FilePublicLink::find($link->id)->download_count);
        $this->assertNotNull(FilePublicLink::find($link->id)->last_used_at);
    }
}
