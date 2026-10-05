<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * Eine Bearbeitungssitzung im Office-Editor ergibt genau eine Version: das erste
 * Speichern legt sie an, jedes weitere ersetzt ihren Inhalt unter neuem Pfad.
 */
class FileServiceOfficeSaveFeatureTest extends TestCase
{
    use FileFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownFileFixtures();
    }

    private function service(int $maxUpload = 1024 * 1024, int $maxVersions = 10): FileService
    {
        $access = new FileAccessService();
        $quota = new FileQuotaService($access, 0);

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

    private function upload(string $content, string $name = 'Protokoll.odt'): UploadedFile
    {
        $stream = (new StreamFactory())->createStream($content);

        return new UploadedFile($stream, $name, 'application/octet-stream', strlen($content));
    }

    /** @return array{0: FileActor, 1: StoredFile} */
    private function fileWithLevel(int $level = Share::LEVEL_EDIT, ?int $quota = null, string $content = 'Stand 1'): array
    {
        $member = $this->createMember();
        $root = $this->createFolder('Vorstand ' . bin2hex(random_bytes(3)), null, $quota);
        $this->share($root, 'user', (int) $member->id, $level);
        $file = $this->service()->upload(new FileActor((int) $member->id, true), $root, $this->upload($content))->file;

        return [$this->actor($member), $file];
    }

    private function versionCount(StoredFile $file): int
    {
        return FileVersion::query()->where('file_id', $file->id)->count();
    }

    public function testFirstSaveCreatesVersionAndFurtherSavesReplaceIt(): void
    {
        [$actor, $file] = $this->fileWithLevel();
        $service = $this->service();

        $first = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), false);
        $this->assertSame(2, $first->version_number);
        $this->assertTrue($first->office_session_open);
        $firstPath = (string) $first->storage_path;

        $second = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand BB'), false);

        $this->assertSame((int) $first->id, (int) $second->id, 'Dieselbe Version.');
        $this->assertSame(2, $this->versionCount($file));
        $this->assertSame('Stand BB', $this->storage()->read((string) $second->storage_path));
        $this->assertNotSame($firstPath, $second->storage_path, 'Neuer Pfad statt Überschreiben.');
        $this->assertFalse($this->storage()->exists($firstPath), 'Pfad ohne Version wird gelöscht.');
        $this->assertSame(8, StoredFile::findOrFail($file->id)->size);
        $this->assertSame((int) $second->id, StoredFile::findOrFail($file->id)->current_version_id);
    }

    public function testExitSaveClosesSessionSoNextSaveStartsNewVersion(): void
    {
        [$actor, $file] = $this->fileWithLevel();
        $service = $this->service();

        $closed = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), true);
        $this->assertFalse($closed->office_session_open);

        $next = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand B'), false);
        $this->assertSame(3, $next->version_number);
    }

    public function testSessionIdleForAnHourStartsNewVersion(): void
    {
        [$actor, $file] = $this->fileWithLevel();
        $service = $this->service();

        Carbon::setTestNow('2026-10-04 10:00:00');
        $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), false);
        Carbon::setTestNow('2026-10-04 10:59:00');
        $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand B'), false);
        $this->assertSame(2, $this->versionCount($file), 'Innerhalb einer Stunde dieselbe Version.');

        Carbon::setTestNow('2026-10-04 12:00:00');
        $late = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand C'), false);
        $this->assertSame(3, $late->version_number);
    }

    public function testUploadThroughFileManagerEndsTheOfficeSession(): void
    {
        [$actor, $file] = $this->fileWithLevel();
        $service = $this->service();

        $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), false);
        $service->replace($actor, (int) $file->id, $this->upload('Hochgeladen'));
        $after = $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand B'), false);

        $this->assertSame(4, $after->version_number, 'Die hochgeladene Fassung bleibt erhalten.');
    }

    public function testQuotaCountsOnlyGrowthWhenReplacing(): void
    {
        [$actor, $file] = $this->fileWithLevel(Share::LEVEL_EDIT, 30, str_repeat('x', 10));
        $service = $this->service();

        $service->saveFromOffice($actor, (int) $file->id, $this->upload(str_repeat('y', 10)), false);
        // 10 + 15 = 25 passt; zählte die volle Größe, wären es 10 + 10 + 15 = 35.
        $service->saveFromOffice($actor, (int) $file->id, $this->upload(str_repeat('z', 15)), false);
        $this->assertSame(2, $this->versionCount($file));

        try {
            $service->saveFromOffice($actor, (int) $file->id, $this->upload(str_repeat('w', 25)), false);
            $this->fail('Kontingent überschritten muss abgewiesen werden.');
        } catch (FileManagementException $exception) {
            $this->assertSame(413, $exception->status);
        }
    }

    /**
     * Zwischen Ablegen des Inhalts und Transaktion lädt jemand über die Ablage eine
     * neue Fassung hoch. Gewählt und geprüft wird unter der Sperre: Das Speichern
     * aus dem Editor endet als Konflikt, statt die alte Version zu überschreiben.
     */
    public function testVersionUploadedWhileSavingIsAConflict(): void
    {
        [$actor, $file] = $this->fileWithLevel();
        $this->service()->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), false);

        $inner = $this->storage();
        $racing = null;
        $storage = new class ($inner, function () use (&$racing, $actor, $file): void {
            // Einmalig: replace() legt selbst ab und landete sonst wieder hier.
            [$service, $racing] = [$racing, null];
            $service?->replace($actor, (int) $file->id, $this->upload('Hochgeladen'));
        }) implements \App\Services\Files\FileStorage {
            public function __construct(private readonly \App\Services\Files\FileStorage $inner, private readonly \Closure $afterPut)
            {
            }

            public function name(): string
            {
                return $this->inner->name();
            }

            public function put(string $sourcePath): string
            {
                $path = $this->inner->put($sourcePath);
                ($this->afterPut)();

                return $path;
            }

            public function writeAt(string $storagePath, $stream): void
            {
                $this->inner->writeAt($storagePath, $stream);
            }

            public function read(string $storagePath): string
            {
                return $this->inner->read($storagePath);
            }

            public function readStream(string $storagePath)
            {
                return $this->inner->readStream($storagePath);
            }

            public function readRange(string $storagePath, int $start, int $length): string
            {
                return $this->inner->readRange($storagePath, $start, $length);
            }

            public function size(string $storagePath): int
            {
                return $this->inner->size($storagePath);
            }

            public function exists(string $storagePath): bool
            {
                return $this->inner->exists($storagePath);
            }

            public function localPath(string $storagePath): ?string
            {
                return $this->inner->localPath($storagePath);
            }

            public function delete(string $storagePath): void
            {
                $this->inner->delete($storagePath);
            }
        };
        $access = new FileAccessService();
        $quota = new FileQuotaService($access, 0);
        $service = new FileService(
            $access,
            new FileFolderService($access, $quota, new NullLogger()),
            $quota,
            new FileStorageRegistry($storage),
            new NullLogger(),
            1024 * 1024,
            10
        );
        $racing = $service;

        try {
            $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand B'), false);
            $this->fail('Eine inzwischen hochgeladene Fassung muss als Konflikt enden.');
        } catch (FileManagementException $exception) {
            $this->assertSame(409, $exception->status);
        }

        $current = FileVersion::findOrFail(StoredFile::findOrFail($file->id)->current_version_id);
        $this->assertSame('Hochgeladen', $this->storage()->read((string) $current->storage_path));
        $this->assertSame(3, $this->versionCount($file), 'Office-Version und Upload bleiben, nichts überschrieben.');
        $stored = FileVersion::query()->where('file_id', $file->id)->pluck('storage_path')->all();
        $this->assertNotContains('Stand B', array_map(fn (string $p): string => $this->storage()->read($p), $stored));
    }

    public function testStaleTimestampIsRejectedUnderTheLock(): void
    {
        [$actor, $file] = $this->fileWithLevel();
        $service = $this->service();

        try {
            $service->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), false, 'veraltet');
            $this->fail('Abweichender Zeitstempel muss als Konflikt enden.');
        } catch (FileManagementException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame(1, $this->versionCount($file));
    }

    public function testReaderCannotSave(): void
    {
        [$actor, $file] = $this->fileWithLevel(Share::LEVEL_READ);

        try {
            $this->service()->saveFromOffice($actor, (int) $file->id, $this->upload('Stand A'), false);
            $this->fail('Lesende dürfen nicht speichern.');
        } catch (FileManagementException $exception) {
            $this->assertSame(403, $exception->status);
        }
        $this->assertSame(1, $this->versionCount($file));
    }

    public function testOversizedContentIsRejected(): void
    {
        [$actor, $file] = $this->fileWithLevel();

        try {
            $this->service(maxUpload: 5)->saveFromOffice($actor, (int) $file->id, $this->upload('zu groß'), false);
            $this->fail('Zu große Inhalte müssen abgewiesen werden.');
        } catch (FileManagementException $exception) {
            $this->assertSame(413, $exception->status);
        }
        $this->assertSame(1, $this->versionCount($file));
    }

    public function testEmptyContentIsRejected(): void
    {
        [$actor, $file] = $this->fileWithLevel();

        try {
            $this->service()->saveFromOffice($actor, (int) $file->id, $this->upload(''), false);
            $this->fail('Ein leerer Inhalt würde das Dokument löschen.');
        } catch (FileManagementException $exception) {
            $this->assertSame(400, $exception->status);
        }
        $this->assertSame(1, $this->versionCount($file));
    }
}
