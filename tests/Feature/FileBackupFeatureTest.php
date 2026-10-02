<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolderShare as Share;
use App\Models\FileVersion;
use App\Models\StoredFile;
use App\Services\BackupService;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use App\Services\Files\FileBackupService;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileService;
use App\Services\Files\FileStorageRegistry;
use App\Services\SessionInvalidationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Unit\Services\Fakes\FakeDumpRunner;

/**
 * Dateien der Dateiverwaltung gehören ins Backup. Gesichert wird inkrementell in
 * einen Pool neben den Dumps; jedes Backup hält ein Manifest mit Pfad und Prüfsumme.
 */
class FileBackupFeatureTest extends TestCase
{
    use FileFixtures;

    private string $backupDir;
    private FakeDumpRunner $dumpRunner;
    private int $clock = 1_800_000_000;
    private FileService $files;
    private FileActor $actor;
    private \App\Models\FileFolder $folder;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $this->backupDir = sys_get_temp_dir() . '/files-backup-' . bin2hex(random_bytes(6));
        $this->dumpRunner = new FakeDumpRunner();

        $access = new FileAccessService();
        $quota = new FileQuotaService($access, 0);
        $this->files = new FileService(
            $access,
            new FileFolderService($access, $quota, new NullLogger()),
            $quota,
            new FileStorageRegistry($this->storage()),
            new NullLogger(),
            1 << 20,
            5
        );

        $member = $this->createMember();
        $this->folder = $this->createFolder('Sicherung');
        $this->share($this->folder, 'user', (int) $member->id, Share::LEVEL_EDIT);
        $this->actor = $this->actor($member);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->backupDir);
        $this->tearDownFileFixtures();
    }

    private function backups(bool $withFiles = true): BackupService
    {
        return new BackupService(
            $this->dumpRunner,
            new NullLogger(),
            $this->backupDir,
            0,
            0,
            true,
            'db_test',
            'test',
            null,
            new SessionInvalidationService(),
            fn (): int => $this->clock++,
            $withFiles ? new FileBackupService($this->storage(), new NullLogger()) : null
        );
    }

    private function upload(string $name, string $content): StoredFile
    {
        $stream = (new StreamFactory())->createStream($content);

        return $this->files->upload($this->actor, $this->folder, new UploadedFile($stream, $name, 'text/plain', 1))->file;
    }

    private function pathOf(StoredFile $file): string
    {
        return (string) FileVersion::findOrFail($file->current_version_id)->storage_path;
    }

    /** Pfade der Versionen, die diese Test-Transaktion angelegt hat. */
    private function poolHas(string $path): bool
    {
        return is_file($this->backupDir . '/files/' . $path);
    }

    public function testBackupWritesManifestAndCopiesFilesIntoPool(): void
    {
        $file = $this->upload('Noten.pdf', 'Inhalt A');

        $meta = $this->backups()->create(BackupService::TYPE_MANUAL, null);

        $this->assertGreaterThanOrEqual(1, $meta['files_count']);
        $this->assertFileExists($this->backupDir . '/' . $meta['id'] . '.files.json');
        $this->assertTrue($this->poolHas($this->pathOf($file)));
    }

    public function testSecondBackupDoesNotCopyAgain(): void
    {
        $file = $this->upload('Noten.pdf', 'Inhalt A');
        $service = $this->backups();
        $service->create(BackupService::TYPE_MANUAL, null);
        $poolFile = $this->backupDir . '/files/' . $this->pathOf($file);
        touch($poolFile, 1_000_000_000);
        clearstatcache();

        $service->create(BackupService::TYPE_MANUAL, null);
        clearstatcache();

        $this->assertSame(1_000_000_000, filemtime($poolFile));
    }

    public function testRestoreBringsBackMissingStoredFile(): void
    {
        $file = $this->upload('Noten.pdf', 'Inhalt A');
        $path = $this->pathOf($file);
        $service = $this->backups();
        $meta = $service->create(BackupService::TYPE_MANUAL, null);
        $this->storage()->delete($path);

        $service->restore($meta['id']);

        $this->assertSame(1, $this->dumpRunner->restoreCallCount);
        $this->assertSame('Inhalt A', $this->storage()->read($path));
    }

    public function testDamagedPoolAbortsBeforeDatabaseIsTouched(): void
    {
        $file = $this->upload('Noten.pdf', 'Inhalt A');
        $service = $this->backups();
        $meta = $service->create(BackupService::TYPE_MANUAL, null);
        file_put_contents($this->backupDir . '/files/' . $this->pathOf($file), 'verfälscht');

        try {
            $service->restore($meta['id']);
            $this->fail('Verfälschte Pool-Datei nicht bemerkt.');
        } catch (\RuntimeException) {
            $this->assertSame(0, $this->dumpRunner->restoreCallCount);
        }

        unlink($this->backupDir . '/files/' . $this->pathOf($file));
        $this->expectException(\RuntimeException::class);
        $service->restore($meta['id']);
    }

    public function testDeletingBackupsCollectsOnlyUnreferencedPoolFiles(): void
    {
        $first = $this->upload('eins.txt', 'eins');
        $service = $this->backups();
        $a = $service->create(BackupService::TYPE_MANUAL, null);
        $second = $this->upload('zwei.txt', 'zwei');
        $b = $service->create(BackupService::TYPE_MANUAL, null);

        $service->delete($a['id']);
        $this->assertTrue($this->poolHas($this->pathOf($first)), 'Backup B braucht die Datei noch.');
        $this->assertTrue($this->poolHas($this->pathOf($second)));

        $service->delete($b['id']);
        $this->assertFalse($this->poolHas($this->pathOf($first)));
        $this->assertFalse($this->poolHas($this->pathOf($second)));
    }

    public function testDeletingBackupSparesFilesOfBackupStillInProgress(): void
    {
        $service = $this->backups();
        $this->upload('alt.txt', 'alt');
        $old = $service->create(BackupService::TYPE_MANUAL, null);
        $fresh = $this->upload('neu.txt', 'neu');

        // Ein zweites Backup hat seine Dateien schon im Pool, das Manifest fehlt noch.
        $inProgress = new FileBackupService($this->storage(), new NullLogger());
        $inProgress->prepare($this->backupDir);

        $service->delete($old['id']);

        $this->assertTrue($this->poolHas($this->pathOf($fresh)));
    }

    public function testLegacyBackupWithoutManifestStillRestores(): void
    {
        $meta = $this->backups(false)->create(BackupService::TYPE_MANUAL, null);
        $this->assertArrayNotHasKey('files_count', $meta);

        $this->backups()->restore($meta['id']);

        $this->assertSame(1, $this->dumpRunner->restoreCallCount);
    }

    public function testMissingStoredFileIsSkippedInsteadOfBreakingEveryBackup(): void
    {
        $lost = $this->upload('weg.txt', 'weg');
        $kept = $this->upload('da.txt', 'da');
        $this->storage()->delete($this->pathOf($lost));

        $meta = $this->backups()->create(BackupService::TYPE_MANUAL, null);

        $this->assertSame(1, $meta['files_missing']);
        $this->assertTrue($this->poolHas($this->pathOf($kept)));
    }

    public function testContainerWiresFileBackupIntoBackupService(): void
    {
        $builder = new \DI\ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($builder);
        $container = $builder->build();
        $container->set(\Illuminate\Database\Capsule\Manager::class, \Tests\Unit\Bootstrap::getCapsule());

        $service = $container->get(BackupService::class);

        $property = new \ReflectionProperty(BackupService::class, 'fileBackup');
        $this->assertInstanceOf(FileBackupService::class, $property->getValue($service));
    }

    public function testFilesArchiveContainsManifestAndFiles(): void
    {
        $file = $this->upload('Noten.pdf', 'Inhalt A');
        $service = $this->backups();
        $meta = $service->create(BackupService::TYPE_MANUAL, null);

        $archive = $service->getFilesArchive($meta['id']);

        $this->assertNotNull($archive);
        $phar = new \PharData($archive['path']);
        $this->assertTrue(isset($phar['manifest.json']));
        $this->assertSame('Inhalt A', file_get_contents($phar['files/' . $this->pathOf($file)]->getPathname()));
        unset($phar);
        unlink($archive['path']);
    }
}
