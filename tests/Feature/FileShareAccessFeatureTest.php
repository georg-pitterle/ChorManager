<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolderShare as Share;
use App\Models\FileShare;
use App\Models\StoredFile;
use App\Services\Files\FileAccessService;
use PHPUnit\Framework\TestCase;

/**
 * Freigaben auf einzelne Dateien: Sie erweitern die Stufe aus dem Ordner und
 * machen eine Datei in einem sonst unsichtbaren Ordner erreichbar.
 */
class FileShareAccessFeatureTest extends TestCase
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

    private function file(\App\Models\FileFolder $folder, string $name): StoredFile
    {
        return StoredFile::create([
            'folder_id' => $folder->id,
            'name' => $name,
            'size' => 1,
            'mime_type' => 'text/plain',
        ]);
    }

    private function shareFile(StoredFile $file, string $type, int $reference, int $level): void
    {
        $this->shareFileWith($file, self::legacyConditions($type, $reference), $level);
    }

    public function testFileShareGrantsAccessWithoutFolderAccess(): void
    {
        $member = $this->createMember();
        $group = $this->createVoiceGroupFor($member);
        $folder = $this->createFolder('Vorstand');
        $file = $this->file($folder, 'Einladung.pdf');
        $other = $this->file($folder, 'Geheim.pdf');
        $this->shareFile($file, 'voice_group', (int) $group->id, Share::LEVEL_READ);
        $actor = $this->actor($member);

        $this->assertSame(Share::LEVEL_READ, $this->access->fileLevelFor($actor, $file));
        $this->assertSame(Share::LEVEL_NONE, $this->access->fileLevelFor($actor, $other));
        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($actor, $folder), 'Der Ordner bleibt zu.');
        $this->assertSame([(int) $file->id], $this->access->sharedFilesFor($actor)->pluck('id')->all());
    }

    public function testFileShareOnlyExtendsFolderLevel(): void
    {
        $member = $this->createMember();
        $folder = $this->createFolder('Noten');
        $this->share($folder, 'user', (int) $member->id, Share::LEVEL_UPLOAD);
        $file = $this->file($folder, 'Partitur.pdf');
        $this->shareFile($file, 'user', (int) $member->id, Share::LEVEL_READ);
        $actor = $this->actor($member);

        $this->assertSame(Share::LEVEL_UPLOAD, $this->access->fileLevelFor($actor, $file));

        FileShare::query()->where('file_id', $file->id)->update(['level' => Share::LEVEL_EDIT]);
        // Direkt am Modell geschrieben, nicht über die Dienste: Der Zwischenspeicher
        // von FileAccessService erfährt davon nur hierüber.
        FileAccessService::invalidate();
        $this->assertSame(Share::LEVEL_EDIT, $this->access->fileLevelFor($actor, $file));
        $shared = $this->access->sharedFilesFor($actor)->pluck('id')->all();
        $this->assertSame([], $shared, 'Im sichtbaren Ordner kein Extra-Eintrag.');
    }

    public function testTrashedFileOrFolderEndsFileShare(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Wurzel');
        $folder = $this->createFolder('Kind', $root);
        $file = $this->file($folder, 'a.pdf');
        $this->shareFile($file, 'all_members', 0, Share::LEVEL_READ);
        $actor = $this->actor($member);

        $root->delete();
        // Direkt am Modell geschrieben, nicht über die Dienste: Der Zwischenspeicher
        // von FileAccessService erfährt davon nur hierüber.
        FileAccessService::invalidate();
        $this->assertSame(Share::LEVEL_NONE, $this->access->fileLevelFor($actor, $file->fresh()));
        $this->assertSame([], $this->access->sharedFilesFor($actor)->pluck('id')->all());

        $root->restore();
        // Direkt am Modell geschrieben, nicht über die Dienste: Der Zwischenspeicher
        // von FileAccessService erfährt davon nur hierüber.
        FileAccessService::invalidate();
        $file->delete();
        $this->assertSame(Share::LEVEL_NONE, $this->access->fileLevelFor($actor, StoredFile::withTrashed()->find($file->id)));
    }

    public function testSharedFileAppearsInSearchAndFavoritesWithoutFolderPath(): void
    {
        $member = $this->createMember();
        $folder = $this->createFolder('Geheimer Vorstand');
        $token = 'qq' . bin2hex(random_bytes(3));
        $file = $this->file($folder, "Einladung {$token}.pdf");
        $this->file($folder, "Protokoll {$token}.pdf");
        $this->shareFile($file, 'user', (int) $member->id, Share::LEVEL_READ);
        $actor = $this->actor($member);

        $result = (new \App\Services\Files\FileSearchService($this->access))->search($actor, $token);
        $this->assertSame(["Einladung {$token}.pdf"], array_column($result['files'], 'name'));
        $this->assertSame([], $result['files'][0]['path'], 'Kein Ordnername für Unbefugte.');

        $favorites = new \App\Services\Files\FileFavoriteService($this->access);
        $this->assertTrue($favorites->toggle($actor, 'file', (int) $file->id));
        $this->assertSame([(int) $file->id], array_map(
            static fn (StoredFile $f): int => (int) $f->id,
            $favorites->listFor($actor)['files']
        ));
    }

    public function testFileShareEditorMayNotRenameMoveOrTrash(): void
    {
        $editor = $this->createMember();
        $folder = $this->createFolder('Vorstand');
        $target = $this->createFolder('Ziel');
        $this->share($target, 'user', (int) $editor->id, Share::LEVEL_EDIT);
        $file = $this->file($folder, 'Vertrag.pdf');
        $this->shareFile($file, 'user', (int) $editor->id, Share::LEVEL_EDIT);
        $actor = $this->actor($editor);

        $quota = new \App\Services\Files\FileQuotaService($this->access, 0);
        $folders = new \App\Services\Files\FileFolderService($this->access, $quota, new \Psr\Log\NullLogger());
        $service = new \App\Services\Files\FileService(
            $this->access,
            $folders,
            $quota,
            new \App\Services\Files\FileStorageRegistry($this->storage()),
            new \Psr\Log\NullLogger(),
            1 << 20,
            5
        );

        $attempts = [
            'umbenennen' => fn () => $service->renameFile($actor, $file, 'anders.pdf'),
            'verschieben' => fn () => $service->moveFile($actor, $file, $target),
            'löschen' => fn () => $service->trashFile($actor, $file),
        ];
        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail('Mit Dateifreigabe erlaubt: ' . $label);
            } catch (\App\Services\Files\FileManagementException $exception) {
                $this->assertSame(404, $exception->status, $label);
            }
        }
        $this->assertSame('Vertrag.pdf', $file->fresh()->name);
    }

    public function testSopranoAndProjectCombinedOnFolder(): void
    {
        $sopran = $this->createMember('Sopran im Projekt');
        $soprano = $this->createVoiceGroupFor($sopran, 'Sopran');
        $project = $this->createProjectFor($sopran, 'Frühjahrskonzert');
        $outside = $this->createMember('Sopran außerhalb');
        $outside->voiceGroups()->attach($soprano->id);
        $alto = $this->createMember('Alt im Projekt');
        $this->createVoiceGroupFor($alto, 'Alt');
        $alto->projects()->attach($project->id);
        $folder = $this->createFolder('Stimmproben');
        $this->shareWith(
            $folder,
            ['voice_group' => [(int) $soprano->id], 'project' => [(int) $project->id]],
            Share::LEVEL_READ
        );

        $this->assertSame(Share::LEVEL_READ, $this->access->levelFor($this->actor($sopran), $folder));
        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($this->actor($outside), $folder));
        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($this->actor($alto), $folder));
    }

    public function testSharesOfOthersDoNotApply(): void
    {
        $member = $this->createMember();
        $other = $this->createMember('Andere');
        $role = $this->createRoleFor($other);
        $folder = $this->createFolder('Fremd');
        $file = $this->file($folder, 'x.pdf');
        $this->shareFile($file, 'role', (int) $role->id, Share::LEVEL_EDIT);
        $this->shareFile($file, 'user', (int) $other->id, Share::LEVEL_EDIT);

        $this->assertSame(Share::LEVEL_NONE, $this->access->fileLevelFor($this->actor($member), $file));
    }

    public function testFileAdminStillManagesEverything(): void
    {
        $folder = $this->createFolder('Alles');
        $file = $this->file($folder, 'y.pdf');

        $this->assertSame(
            Share::LEVEL_MANAGE,
            $this->access->fileLevelFor($this->actor($this->createMember(), true), $file)
        );
    }
}
