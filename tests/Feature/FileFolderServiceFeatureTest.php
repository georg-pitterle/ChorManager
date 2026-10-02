<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolder;
use App\Models\FileFolderShare as Share;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileQuotaService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FileFolderServiceFeatureTest extends TestCase
{
    use FileFixtures;

    private FileFolderService $folders;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $access = new FileAccessService();
        $this->folders = new FileFolderService($access, new FileQuotaService($access, 0), new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    public function testOnlyFileAdminCreatesTeamFolders(): void
    {
        $member = $this->createMember();

        $root = $this->folders->createRoot($this->actor($member, true), 'Noten', 5 * 1024 * 1024);
        $this->assertTrue($root->isRoot());
        $this->assertSame(5 * 1024 * 1024, $root->quota_bytes);

        $this->expectException(FileManagementException::class);
        $this->folders->createRoot($this->actor($member), 'Heimlich', null);
    }

    public function testCreateSubfolderNeedsUploadLevel(): void
    {
        $reader = $this->createMember('Leser');
        $uploader = $this->createMember('Hochlader');
        $root = $this->createFolder('Projekt');
        $this->share($root, 'user', (int) $reader->id, Share::LEVEL_READ);
        $this->share($root, 'user', (int) $uploader->id, Share::LEVEL_UPLOAD);

        $child = $this->folders->create($this->actor($uploader), $root, 'Plakate');
        $this->assertSame((int) $root->id, $child->parent_id);

        $this->expectException(FileManagementException::class);
        $this->folders->create($this->actor($reader), $root, 'Nicht erlaubt');
    }

    public function testNamesAreCleanedAndMustBeUniqueAmongLiveSiblings(): void
    {
        $admin = $this->actor($this->createMember(), true);
        $root = $this->createFolder('Wurzel');

        $child = $this->folders->create($admin, $root, "  Pro/ben\x07  ");
        $this->assertSame('Pro_ben', $child->name);

        try {
            $this->folders->create($admin, $root, 'pro_ben');
            $this->fail('Doppelter Name trotz anderer Schreibweise angenommen.');
        } catch (FileManagementException $exception) {
            $this->assertSame(409, $exception->status);
        }

        $this->folders->trash($admin, $child);
        $this->assertSame('Pro_ben', $this->folders->create($admin, $root, 'Pro_ben')->name);
    }

    public function testRejectsEmptyAndDotNames(): void
    {
        $admin = $this->actor($this->createMember(), true);
        $root = $this->createFolder('Wurzel');

        foreach (['', '   ', '.', '..'] as $name) {
            try {
                $this->folders->create($admin, $root, $name);
                $this->fail('Name angenommen: "' . $name . '"');
            } catch (FileManagementException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }
    }

    public function testRenameNeedsEditLevel(): void
    {
        $uploader = $this->createMember();
        $root = $this->createFolder('Wurzel');
        $child = $this->createFolder('Alt', $root);
        $this->share($root, 'user', (int) $uploader->id, Share::LEVEL_UPLOAD);

        $this->expectException(FileManagementException::class);
        $this->folders->rename($this->actor($uploader), $child, 'Neu');
    }

    public function testMoveNeedsEditOnSourceAndTargetAndRejectsCycles(): void
    {
        $editor = $this->createMember();
        $rootA = $this->createFolder('A');
        $rootB = $this->createFolder('B');
        $child = $this->createFolder('Kind', $rootA);
        $grandChild = $this->createFolder('Enkel', $child);
        $this->share($rootA, 'user', (int) $editor->id, Share::LEVEL_EDIT);
        $this->share($rootB, 'user', (int) $editor->id, Share::LEVEL_READ);
        $actor = $this->actor($editor);

        try {
            $this->folders->move($actor, $child, $rootB);
            $this->fail('Verschieben in Ordner mit Stufe Lesen erlaubt.');
        } catch (FileManagementException $exception) {
            $this->assertSame(403, $exception->status);
        }

        try {
            $this->folders->move($actor, $child, $grandChild);
            $this->fail('Ordner in eigenen Unterordner verschoben.');
        } catch (FileManagementException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $sibling = $this->createFolder('Geschwister', $rootA);
        $this->folders->move($actor, $grandChild, $sibling);
        $this->assertSame((int) $sibling->id, $grandChild->fresh()->parent_id);
    }

    public function testTeamFolderCannotBeMovedOrTrashedWithoutAdmin(): void
    {
        $manager = $this->createMember();
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', (int) $manager->id, Share::LEVEL_MANAGE);

        $this->expectException(FileManagementException::class);
        $this->folders->trash($this->actor($manager), $root);
    }

    public function testTrashRecordsWhoAndWhen(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Wurzel');
        $child = $this->createFolder('Weg', $root);
        $this->share($root, 'user', (int) $member->id, Share::LEVEL_EDIT);

        $this->folders->trash($this->actor($member), $child);

        $trashed = FileFolder::withTrashed()->find($child->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame((int) $member->id, $trashed->deleted_by);
    }

    public function testSetSharesStoresFiltersMergesDuplicatesAndRejectsEmptyRows(): void
    {
        $manager = $this->createMember('Verwalter');
        $role = $this->createRoleFor($this->createMember());
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', (int) $manager->id, Share::LEVEL_MANAGE);
        $actor = $this->actor($manager);

        $this->folders->setShares($actor, $root, [
            ['level' => Share::LEVEL_MANAGE, 'conditions' => ['user' => [(int) $manager->id]]],
            ['level' => Share::LEVEL_READ, 'conditions' => ['role' => [(int) $role->id]]],
            ['level' => Share::LEVEL_EDIT, 'conditions' => ['role' => [(string) $role->id]]],
            ['level' => Share::LEVEL_READ, 'all' => '1'],
        ]);

        $shares = Share::query()->where('folder_id', $root->id)->get();
        $this->assertCount(3, $shares, 'Doppelte Rollen-Zeile zusammengelegt.');
        $levels = $shares->pluck('level')->sort()->values()->all();
        $this->assertSame([Share::LEVEL_READ, Share::LEVEL_EDIT, Share::LEVEL_MANAGE], $levels);

        try {
            $this->folders->setShares($actor, $root, [
                ['level' => Share::LEVEL_MANAGE, 'conditions' => ['user' => [(int) $manager->id]]],
                ['level' => Share::LEVEL_READ, 'conditions' => []],
            ]);
            $this->fail('Leere Zeile ohne "Alle Mitglieder" angenommen.');
        } catch (FileManagementException $exception) {
            $this->assertSame(422, $exception->status);
        }
        $this->assertCount(3, Share::query()->where('folder_id', $root->id)->get(), 'Nichts geändert.');
    }

    public function testResavingShareWithDeletedRoleDoesNotWidenIt(): void
    {
        $manager = $this->createMember('Verwalter');
        $sopran = $this->createMember('Sopran');
        $group = $this->createVoiceGroupFor($sopran);
        $role = $this->createRoleFor($this->createMember('Andere'));
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', (int) $manager->id, Share::LEVEL_MANAGE);
        $this->shareWith($root, ['role' => [(int) $role->id], 'voice_group' => [(int) $group->id]], Share::LEVEL_READ);
        $role->users()->detach();
        $role->delete();

        // Das Formular schickt, was es anzeigt - samt dem gelöschten Wert.
        try {
            $this->folders->setShares($this->actor($manager), $root, [
                ['level' => Share::LEVEL_MANAGE, 'conditions' => ['user' => [(string) $manager->id]]],
                ['level' => Share::LEVEL_READ, 'conditions' => [
                    'role' => [(string) $role->id],
                    'voice_group' => [(string) $group->id],
                ]],
            ]);
            $this->fail('Freigabe mit gelöschter Rolle still erweitert.');
        } catch (FileManagementException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $access = new \App\Services\Files\FileAccessService();
        $this->assertSame(Share::LEVEL_NONE, $access->levelFor($this->actor($sopran), $root));
    }

    public function testReplacingSharesDeletesTheirFilters(): void
    {
        $manager = $this->createMember();
        $root = $this->createFolder('Wurzel');
        $old = $this->share($root, 'all_members', 0, Share::LEVEL_READ);
        $this->share($root, 'user', (int) $manager->id, Share::LEVEL_MANAGE);

        $this->folders->setShares($this->actor($manager), $root, [
            ['level' => Share::LEVEL_MANAGE, 'conditions' => ['user' => [(int) $manager->id]]],
        ]);

        $this->assertNull(\App\Models\AudienceFilter::find($old->audience_filter_id));
    }

    public function testManagerCannotLockThemselvesOutWithAFilter(): void
    {
        $manager = $this->createMember();
        $project = \App\Models\Project::create(['name' => 'Fremdes Projekt ' . bin2hex(random_bytes(3))]);
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', (int) $manager->id, Share::LEVEL_MANAGE);

        try {
            $this->folders->setShares($this->actor($manager), $root, [
                ['level' => Share::LEVEL_MANAGE, 'conditions' => ['project' => [(int) $project->id]]],
            ]);
            $this->fail('Selbstaussperren über Filter erlaubt.');
        } catch (FileManagementException $exception) {
            $this->assertSame(422, $exception->status);
        }
    }

    public function testManagerCannotLockThemselvesOutOfOwnFolder(): void
    {
        $manager = $this->createMember();
        $root = $this->createFolder('Wurzel');
        $this->share($root, 'user', (int) $manager->id, Share::LEVEL_MANAGE);

        try {
            $this->folders->setShares($this->actor($manager), $root, []);
            $this->fail('Eigene Verwaltung entzogen.');
        } catch (FileManagementException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertSame(1, Share::query()->where('folder_id', $root->id)->count());
    }

    public function testQuotaOnlyForAdminAndOnlyOnTeamFolders(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Wurzel');

        $this->folders->setQuota($this->actor($member, true), $root, 1024);
        $this->assertSame(1024, $root->fresh()->quota_bytes);

        $this->folders->setQuota($this->actor($member, true), $root, null);
        $this->assertNull($root->fresh()->quota_bytes);

        $this->expectException(FileManagementException::class);
        $this->folders->setQuota($this->actor($member), $root, 1);
    }
}
