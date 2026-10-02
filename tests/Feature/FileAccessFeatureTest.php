<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolderShare as Share;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use PHPUnit\Framework\TestCase;

/**
 * Wer welchen Ordner mit welcher Stufe sieht. Die Stufe eines Ordners ist die
 * höchste passende Freigabe auf ihm oder einem Vorfahren.
 */
class FileAccessFeatureTest extends TestCase
{
    use FileFixtures;

    private FileAccessService $access;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $this->access = new FileAccessService();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    public function testWithoutShareThereIsNoAccess(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Vorstand');

        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($this->actor($member), $root));
        $this->assertNotContains((int) $root->id, $this->access->rootsFor($this->actor($member))->pluck('id')->all());
    }

    public function testEachTargetTypeGrantsItsLevel(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $group = $this->createVoiceGroupFor($member);
        $project = $this->createProjectFor($member);
        $cases = [
            ['role', (int) $role->id, Share::LEVEL_MANAGE],
            ['user', (int) $member->id, Share::LEVEL_EDIT],
            ['voice_group', (int) $group->id, Share::LEVEL_READ],
            ['project_members', (int) $project->id, Share::LEVEL_UPLOAD],
            ['all_members', 0, Share::LEVEL_READ],
        ];

        foreach ($cases as [$type, $reference, $level]) {
            $folder = $this->createFolder('Ordner ' . $type);
            $this->share($folder, $type, $reference, $level);

            $this->assertSame($level, $this->access->levelFor($this->actor($member), $folder), $type);
        }
    }

    public function testSharesOfOtherGroupsDoNotApply(): void
    {
        $member = $this->createMember();
        $other = $this->createMember('Andere');
        $foreignRole = $this->createRoleFor($other);
        $foreignGroup = $this->createVoiceGroupFor($other);
        $folder = $this->createFolder('Fremd');
        $this->share($folder, 'role', (int) $foreignRole->id, Share::LEVEL_MANAGE);
        $this->share($folder, 'voice_group', (int) $foreignGroup->id, Share::LEVEL_MANAGE);
        $this->share($folder, 'user', (int) $other->id, Share::LEVEL_MANAGE);

        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($this->actor($member), $folder));
    }

    public function testSubfolderInheritsAndCanOnlyExtend(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Noten');
        $child = $this->createFolder('Sopran', $root);
        $grandChild = $this->createFolder('Proben', $child);
        $this->share($root, 'all_members', 0, Share::LEVEL_READ);
        $this->share($child, 'user', (int) $member->id, Share::LEVEL_EDIT);

        $this->assertSame(Share::LEVEL_READ, $this->access->levelFor($this->actor($member), $root));
        $this->assertSame(Share::LEVEL_EDIT, $this->access->levelFor($this->actor($member), $child));
        $this->assertSame(Share::LEVEL_EDIT, $this->access->levelFor($this->actor($member), $grandChild));
    }

    public function testHighestMatchingShareWins(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $folder = $this->createFolder('Mehrfach');
        $this->share($folder, 'all_members', 0, Share::LEVEL_READ);
        $this->share($folder, 'role', (int) $role->id, Share::LEVEL_UPLOAD);

        $this->assertSame(Share::LEVEL_UPLOAD, $this->access->levelFor($this->actor($member), $folder));
    }

    public function testFileAdminManagesEverything(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Geheim');
        $child = $this->createFolder('Tiefer', $root);

        $this->assertSame(Share::LEVEL_MANAGE, $this->access->levelFor($this->actor($member, true), $child));
        $this->assertContains((int) $root->id, $this->access->rootsFor($this->actor($member, true))->pluck('id')->all());
    }

    public function testTrashedAncestorHidesTheWholeSubtree(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Alt');
        $child = $this->createFolder('Kind', $root);
        $this->share($root, 'user', (int) $member->id, Share::LEVEL_MANAGE);

        $root->delete();

        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($this->actor($member), $child->fresh()));
        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($this->actor($member, true), $child->fresh()));
        $this->assertNotContains((int) $root->id, $this->access->rootsFor($this->actor($member, true))->pluck('id')->all());
    }

    public function testDeepShareBecomesEntryPointWithoutRootAccess(): void
    {
        $member = $this->createMember();
        $group = $this->createVoiceGroupFor($member);
        $root = $this->createFolder('Stimmproben');
        $child = $this->createFolder('Sopran', $root);
        $this->share($child, 'voice_group', (int) $group->id, Share::LEVEL_READ);

        $actor = $this->actor($member);

        $this->assertNotContains((int) $root->id, $this->access->rootsFor($actor)->pluck('id')->all());
        $this->assertContains((int) $child->id, $this->access->sharedEntryPointsFor($actor)->pluck('id')->all());
        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($actor, $root));
    }

    public function testEntryPointBelowAccessibleFolderIsNotListedTwice(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Offen');
        $child = $this->createFolder('Mehr', $root);
        $this->share($root, 'all_members', 0, Share::LEVEL_READ);
        $this->share($child, 'user', (int) $member->id, Share::LEVEL_EDIT);

        $this->assertSame([], $this->access->sharedEntryPointsFor($this->actor($member))->pluck('id')->all());
    }

    public function testActorFromSession(): void
    {
        $this->assertNull(FileActor::fromSession([]));

        $actor = FileActor::fromSession(['user_id' => '7', 'can_manage_files' => true]);

        $this->assertNotNull($actor);
        $this->assertSame(7, $actor->userId);
        $this->assertTrue($actor->isFileAdmin);
    }
}
