<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolder;
use App\Models\FileFolderShare as Share;
use App\Models\FileVersion;
use App\Models\StoredFile;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileService;
use App\Services\Files\FileStorageRegistry;
use App\Services\Office\OfficeDocumentCreator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Neue, leere Office-Dokumente: Wer im Ordner hochladen darf, darf sie anlegen.
 * Ein vorhandener Name wird nie überschrieben.
 */
class OfficeDocumentCreatorFeatureTest extends TestCase
{
    use FileFixtures;
    use OfficeFixtures;

    private const TEMPLATE_DIR = __DIR__ . '/../../assets/office-templates';

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownOfficeFixtures();
        $this->tearDownFileFixtures();
    }

    private function files(): FileService
    {
        $access = new FileAccessService();
        $quota = new FileQuotaService($access, 0);

        return new FileService(
            $access,
            new FileFolderService($access, $quota, new NullLogger()),
            $quota,
            new FileStorageRegistry($this->storage()),
            new NullLogger(),
            1024 * 1024,
            10
        );
    }

    private function creator(?\Closure $fetch = null): OfficeDocumentCreator
    {
        return new OfficeDocumentCreator(
            $this->files(),
            $this->discovery($fetch, null, $fetch !== null),
            self::TEMPLATE_DIR,
            new NullLogger()
        );
    }

    /** @return array{0: FileActor, 1: FileFolder} */
    private function folderWithLevel(int $level): array
    {
        $member = $this->createMember();
        $folder = $this->createFolder('Vorstand ' . bin2hex(random_bytes(3)));
        $this->share($folder, 'user', (int) $member->id, $level);

        return [$this->actor($member), $folder];
    }

    public function testUploaderCreatesDocumentFromTemplate(): void
    {
        [$actor, $folder] = $this->folderWithLevel(Share::LEVEL_UPLOAD);

        $file = $this->creator()->create($actor, $folder, 'text', 'Protokoll Oktober');

        $this->assertSame('Protokoll Oktober.docx', $file->name);
        $this->assertSame((int) $folder->id, (int) $file->folder_id);
        $version = FileVersion::findOrFail($file->current_version_id);
        $this->assertSame(
            (string) file_get_contents(self::TEMPLATE_DIR . '/text.docx'),
            $this->storage()->read((string) $version->storage_path)
        );
    }

    public function testEachTypeGetsItsExtension(): void
    {
        [$actor, $folder] = $this->folderWithLevel(Share::LEVEL_UPLOAD);

        $this->assertSame('Kassa.xlsx', $this->creator()->create($actor, $folder, 'spreadsheet', 'Kassa')->name);
        $this->assertSame(
            'Brief.docx',
            $this->creator()->create($actor, $folder, 'text', 'Brief.docx')->name,
            'Eine schon getippte Endung wird nicht verdoppelt.'
        );
    }

    public function testReaderMayNotCreate(): void
    {
        [$actor, $folder] = $this->folderWithLevel(Share::LEVEL_READ);

        try {
            $this->creator()->create($actor, $folder, 'text', 'Protokoll');
            $this->fail('Ohne Stufe Hochladen kein neues Dokument.');
        } catch (FileManagementException $exception) {
            $this->assertSame(403, $exception->status);
        }
        $this->assertSame(0, StoredFile::query()->where('folder_id', $folder->id)->count());
    }

    public function testExistingNameIsNeverOverwritten(): void
    {
        [$actor, $folder] = $this->folderWithLevel(Share::LEVEL_EDIT);
        $first = $this->creator()->create($actor, $folder, 'text', 'Protokoll');

        try {
            $this->creator()->create($actor, $folder, 'text', 'Protokoll');
            $this->fail('Ein vorhandener Name darf nicht zu einer neuen Version werden.');
        } catch (FileManagementException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame(1, FileVersion::query()->where('file_id', $first->id)->count());
    }

    public function testUnknownTypeAndEmptyNameAreRejected(): void
    {
        [$actor, $folder] = $this->folderWithLevel(Share::LEVEL_UPLOAD);

        foreach ([['macro', 'Makro'], ['text', '   '], ['text', '..'], ['text', '.docx']] as [$type, $name]) {
            try {
                $this->creator()->create($actor, $folder, $type, $name);
                $this->fail('Abgewiesen werden muss: ' . $type . ' / ' . $name);
            } catch (FileManagementException $exception) {
                $this->assertSame(422, $exception->status, $type . ' / ' . $name);
            }
        }
    }

    public function testOnlyTypesTheOfficeServerCanEditAreOffered(): void
    {
        $this->assertSame(
            ['text' => 'Textdokument', 'spreadsheet' => 'Tabelle'],
            array_map(static fn (array $type): string => $type['label'], $this->creator()->availableTypes()),
            'Die Fixture-Discovery kennt docx und xlsx, aber kein pptx.'
        );
        $this->assertSame([], $this->creator(static fn (string $url): ?string => null)->availableTypes());

        [$actor, $folder] = $this->folderWithLevel(Share::LEVEL_UPLOAD);
        try {
            $this->creator()->create($actor, $folder, 'presentation', 'Konzert');
            $this->fail('Was der Office-Server nicht bearbeiten kann, wird nicht angelegt.');
        } catch (FileManagementException $exception) {
            $this->assertSame(422, $exception->status);
        }
    }
}
