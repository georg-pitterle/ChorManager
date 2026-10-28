<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolderShare as Share;
use App\Models\FileVersion;
use App\Models\StoredFile;
use App\Services\BackupLock;
use App\Services\BackupLockedException;
use App\Services\BackupPackageService;
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
 * Ein Backup als eine ZIP-Datei: herunterladen, in den Backup-Ordner einer anderen
 * Installation legen, dort übernehmen. Intern bleibt der gemeinsame Datei-Pool.
 */
class BackupPackageFeatureTest extends TestCase
{
    use FileFixtures;

    private string $backupDir;
    private FakeDumpRunner $dumpRunner;
    private int $clock = 1_800_000_000;
    private FileService $files;
    private FileActor $actor;
    private \App\Models\FileFolder $folder;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $this->backupDir = sys_get_temp_dir() . '/backup-package-' . bin2hex(random_bytes(6));
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
        $this->folder = $this->createFolder('Paket');
        $this->share($this->folder, 'user', (int) $member->id, Share::LEVEL_EDIT);
        $this->actor = $this->actor($member);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        self::removeDirectory($this->backupDir);
        self::removeDirectory($this->backupDir . '-b');
        $this->tearDownFileFixtures();
    }

    private function backups(bool $withFiles = true, ?string $dir = null, int $maxManual = 0): BackupService
    {
        return new BackupService(
            $this->dumpRunner,
            new NullLogger(),
            $dir ?? $this->backupDir,
            $maxManual,
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

    private function packages(BackupService $backups, bool $withFiles = true, ?string $dir = null): BackupPackageService
    {
        return new BackupPackageService(
            $backups,
            $dir ?? $this->backupDir,
            $withFiles ? new FileBackupService($this->storage(), new NullLogger()) : null,
            new NullLogger(),
            new BackupLock($dir ?? $this->backupDir)
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

    private function openZip(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'ZIP lässt sich nicht öffnen.');

        return $zip;
    }

    /** @return list<string> */
    private static function entryNames(\ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        sort($names);

        return $names;
    }

    public function testPackageContainsMetadataManifestDumpAndExactlyTheManifestFiles(): void
    {
        $file = $this->upload('Noten.pdf', 'Inhalt A');
        $backups = $this->backups();
        $meta = $backups->create(BackupService::TYPE_MANUAL, null);

        $package = $this->packages($backups)->build($meta['id']);
        $this->tempFiles[] = $package['path'];

        $this->assertSame($meta['id'] . '.zip', $package['filename']);
        $this->assertSame(filesize($package['path']), $package['size']);
        $zip = $this->openZip($package['path']);
        $this->assertSame(
            ['backup.json', 'database.sql.gz', 'files/' . $this->pathOf($file), 'manifest.json'],
            self::entryNames($zip)
        );
        $this->assertSame(file_get_contents($this->backupDir . '/' . $meta['id'] . '.json'), $zip->getFromName('backup.json'));
        $this->assertSame(
            file_get_contents($this->backupDir . '/' . $meta['id'] . '.files.json'),
            $zip->getFromName('manifest.json')
        );
        $this->assertSame($meta['sha256'], hash('sha256', (string) $zip->getFromName('database.sql.gz')));
        $this->assertSame('Inhalt A', $zip->getFromName('files/' . $this->pathOf($file)));
        $zip->close();
    }

    public function testPackageOfDatabaseOnlyBackupHasNoManifestAndNoFiles(): void
    {
        $backups = $this->backups(false);
        $meta = $backups->create(BackupService::TYPE_MANUAL, null);

        $package = $this->packages($backups, false)->build($meta['id']);
        $this->tempFiles[] = $package['path'];

        $zip = $this->openZip($package['path']);
        $this->assertSame(['backup.json', 'database.sql.gz'], self::entryNames($zip));
        $zip->close();
    }

    public function testPackageEntriesAreStoredUncompressed(): void
    {
        $this->upload('Noten.pdf', 'Inhalt A');
        $backups = $this->backups();
        $meta = $backups->create(BackupService::TYPE_MANUAL, null);

        $package = $this->packages($backups)->build($meta['id']);
        $this->tempFiles[] = $package['path'];

        $zip = $this->openZip($package['path']);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $this->assertSame(\ZipArchive::CM_STORE, $stat['comp_method'], (string) $stat['name']);
        }
        $zip->close();
    }

    /**
     * Das Paket wiegt so viel wie Dump und Dateiverwaltung zusammen - das Größte,
     * was die Anwendung schreibt. In sys_get_temp_dir() lag es bisher dort, wo auch
     * der HTMLPurifier-Zwischenspeicher und die Rate-Limit-Zähler lagen, bevor die
     * aus guten Gründen nach var/ gewandert sind: Das Temp-Verzeichnis gehört
     * niemandem, kann je nach Aufruf woanders liegen, wird von Systemdiensten
     * geleert - und ist als tmpfs der Arbeitsspeicher.
     */
    public function testPackageIsBuiltInsideTheProjectsVarDirectory(): void
    {
        $backups = $this->backups();
        $meta = $backups->create(BackupService::TYPE_MANUAL, null);

        $package = $this->packages($backups)->build($meta['id']);
        $this->tempFiles[] = $package['path'];

        $expectedDir = dirname(__DIR__, 2) . '/var/tmp';
        $this->assertSame($expectedDir, dirname($package['path']));
        $this->assertFileExists($package['path']);
    }

    /**
     * In sys_get_temp_dir() räumte der Systemdienst liegengebliebene Pakete weg;
     * in var/ tut das niemand. Bricht ein Download ab, bevor der Controller die
     * Datei entfernt, bliebe sie dort für immer - in der Größe eines ganzen
     * Backups. Der nächste Paketbau nimmt deshalb die alten mit.
     */
    public function testStalePackagesAreSweptOnTheNextBuild(): void
    {
        $backups = $this->backups();
        $meta = $backups->create(BackupService::TYPE_MANUAL, null);

        $firstPackage = $this->packages($backups)->build($meta['id']);
        $stale = dirname($firstPackage['path']) . '/backup_manual_20200101T000000Z_0badc0de_abcdabcd.zip';
        $fresh = dirname($firstPackage['path']) . '/backup_manual_20200102T000000Z_0badc0de_12341234.zip';
        $foreign = dirname($firstPackage['path']) . '/fremde-datei.zip';
        foreach ([$stale, $fresh, $foreign] as $file) {
            file_put_contents($file, 'alt');
            $this->tempFiles[] = $file;
        }
        touch($stale, time() - 2 * 86400);
        touch($foreign, time() - 2 * 86400);

        $secondPackage = $this->packages($backups)->build($meta['id']);
        $this->tempFiles[] = $firstPackage['path'];
        $this->tempFiles[] = $secondPackage['path'];

        $this->assertFileDoesNotExist($stale, 'Das liegengebliebene Paket muss weg sein.');
        $this->assertFileExists($fresh, 'Ein frisches Paket kann ein laufender Download sein.');
        $this->assertFileExists($foreign, 'Fremde Dateien gehören nicht diesem Dienst.');
        $this->assertFileExists($firstPackage['path'], 'Das eben gebaute Paket bleibt.');
    }

    public function testBuildRejectsUnknownBackup(): void
    {
        $backups = $this->backups();

        $this->expectException(\RuntimeException::class);
        $this->packages($backups)->build('backup_manual_20260101T000000Z_deadbeef');
    }

    // --- Übernahme in eine andere Installation (Verzeichnis B) ---

    private function targetDir(): string
    {
        $dir = $this->backupDir . '-b';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        return $dir;
    }

    /**
     * Ein Backup in A und sein Paket, abgelegt im Ordner von B.
     *
     * @return array{id: string, path: string, file: StoredFile}
     */
    private function packageInTarget(): array
    {
        $file = $this->upload('Noten.pdf', 'Inhalt A');
        $source = $this->backups();
        $meta = $source->create(BackupService::TYPE_MANUAL, null);
        $package = $this->packages($source)->build($meta['id']);
        $target = $this->targetDir() . '/' . $meta['id'] . '.zip';
        rename($package['path'], $target);

        return ['id' => $meta['id'], 'path' => $target, 'file' => $file];
    }

    private function targetPackages(): BackupPackageService
    {
        return $this->packages($this->backups(dir: $this->targetDir()), dir: $this->targetDir());
    }

    /** @param array<string, string> $entries Name im ZIP => Inhalt */
    private function writeZip(string $path, array $entries): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    /** @return array<string, string> */
    private function readZip(string $path): array
    {
        $zip = $this->openZip($path);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $entries;
    }

    /** Nichts von $id liegt in B, das Paket ist zu .zip.failed geworden. */
    private function assertNothingAdopted(string $id): void
    {
        $dir = $this->targetDir();
        $this->assertFileExists($dir . '/' . $id . '.zip.failed');
        $this->assertFileDoesNotExist($dir . '/' . $id . '.zip');
        $this->assertFileDoesNotExist($dir . '/' . $id . '.json');
        $this->assertFileDoesNotExist($dir . '/' . $id . '.sql.gz');
        $this->assertFileDoesNotExist($dir . '/' . $id . '.files.json');
        $this->assertSame([], glob($dir . '/files/*/*/*') ?: []);
    }

    public function testRoundTripPackageFromOtherInstallationRestoresDatabaseAndFiles(): void
    {
        $package = $this->packageInTarget();
        $target = $this->backups(dir: $this->targetDir());
        $packages = $this->packages($target, dir: $this->targetDir());

        $this->assertSame([], $target->list());
        $this->assertSame(['waiting' => [$package['id'] . '.zip'], 'failed' => []], $packages->pendingPackages());

        $this->assertSame([$package['id'] => null], $packages->importAll());

        $listed = $target->list();
        $this->assertCount(1, $listed);
        unset($listed[0]['mail_key_mismatch']);
        $this->assertSame(
            json_decode((string) file_get_contents($this->backupDir . '/' . $package['id'] . '.json'), true),
            $listed[0]
        );
        $this->assertFileDoesNotExist($package['path']);
        $this->assertSame(['waiting' => [], 'failed' => []], $packages->pendingPackages());

        $path = $this->pathOf($package['file']);
        $this->storage()->delete($path);
        $target->restore($package['id']);
        $this->assertSame($this->targetDir() . '/' . $package['id'] . '.sql.gz', $this->dumpRunner->lastRestoredPath);
        $this->assertSame('Inhalt A', $this->storage()->read($path));
    }

    public function testImportDeduplicatesFilesAlreadyInPool(): void
    {
        $package = $this->packageInTarget();
        $path = $this->pathOf($package['file']);
        $poolFile = $this->targetDir() . '/files/' . $path;
        mkdir(dirname($poolFile), 0750, true);
        file_put_contents($poolFile, 'Inhalt A');
        touch($poolFile, 1_000_000_000);
        clearstatcache();

        $this->assertSame([$package['id'] => null], $this->targetPackages()->importAll());
        clearstatcache();

        $this->assertSame(1_000_000_000, filemtime($poolFile));
    }

    public function testListNeverImportsAndDevResetLeavesWaitingPackage(): void
    {
        $package = $this->packageInTarget();
        $target = $this->backups(dir: $this->targetDir());

        foreach ($target->list() as $backup) {
            $target->delete($backup['id']);
        }

        $this->assertFileExists($package['path']);
        $this->assertFileDoesNotExist($this->targetDir() . '/' . $package['id'] . '.json');
    }

    public function testImportSucceedsBeyondManualLimit(): void
    {
        $package = $this->packageInTarget();
        $target = $this->backups(dir: $this->targetDir(), maxManual: 1);
        $target->create(BackupService::TYPE_MANUAL, null);

        $result = $this->packages($target, dir: $this->targetDir())->importAll();

        $this->assertSame([$package['id'] => null], $result);
        $this->assertCount(2, $target->list());
    }

    public function testImportRejectsExistingId(): void
    {
        $package = $this->packageInTarget();
        $dir = $this->targetDir();
        file_put_contents($dir . '/' . $package['id'] . '.json', 'vorhanden');
        file_put_contents($dir . '/' . $package['id'] . '.sql.gz', 'vorhandener Dump');

        $result = $this->targetPackages()->importAll();

        $this->assertSame([$package['id'] => 'Kennung existiert bereits.'], $result);
        $this->assertSame('vorhanden', file_get_contents($dir . '/' . $package['id'] . '.json'));
        $this->assertSame('vorhandener Dump', file_get_contents($dir . '/' . $package['id'] . '.sql.gz'));
        $this->assertFileExists($dir . '/' . $package['id'] . '.zip.failed');
        $this->assertFileDoesNotExist($package['path']);
        $this->assertSame([], glob($dir . '/files/*/*/*') ?: []);
    }

    public function testImportRejectsMetadataIdNotMatchingFileName(): void
    {
        $package = $this->packageInTarget();
        $entries = $this->readZip($package['path']);
        $meta = json_decode($entries['backup.json'], true);
        $meta['id'] = 'backup_manual_20260101T000000Z_ffffffff';
        $entries['backup.json'] = (string) json_encode($meta);
        $this->writeZip($package['path'], $entries);

        $result = $this->targetPackages()->importAll();

        $this->assertSame([$package['id'] => 'Paket ist unvollständig oder beschädigt.'], $result);
        $this->assertNothingAdopted($package['id']);
    }

    public function testImportRejectsPathTraversalEntry(): void
    {
        $package = $this->packageInTarget();
        $original = $this->readZip($package['path']);
        foreach (['files/../../evil.txt', '../evil.txt'] as $evil) {
            $entries = $original;
            $entries[$evil] = 'böse';
            $this->writeZip($package['path'], $entries);

            $result = $this->targetPackages()->importAll();

            $this->assertSame([$package['id'] => 'Paket ist unvollständig oder beschädigt.'], $result, $evil);
            $this->assertNothingAdopted($package['id']);
            $this->assertFileDoesNotExist(dirname($this->backupDir) . '/evil.txt');
            $this->assertFileDoesNotExist($this->targetDir() . '/evil.txt');
            $this->assertFileDoesNotExist(dirname($this->targetDir(), 2) . '/evil.txt');
            unlink($this->targetDir() . '/' . $package['id'] . '.zip.failed');
        }
    }

    public function testImportRejectsUnexpectedEntry(): void
    {
        $package = $this->packageInTarget();
        $entries = $this->readZip($package['path']);
        $entries['extra.txt'] = 'unerwartet';
        $this->writeZip($package['path'], $entries);

        $result = $this->targetPackages()->importAll();

        $this->assertSame([$package['id'] => 'Paket ist unvollständig oder beschädigt.'], $result);
        $this->assertNothingAdopted($package['id']);
    }

    public function testImportRejectsDumpChecksumMismatch(): void
    {
        $package = $this->packageInTarget();
        $entries = $this->readZip($package['path']);
        $entries['database.sql.gz'] = (string) gzencode('-- verfälscht --');
        $this->writeZip($package['path'], $entries);

        $result = $this->targetPackages()->importAll();

        $this->assertSame([$package['id'] => 'Prüfsumme der Datenbank stimmt nicht.'], $result);
        $this->assertNothingAdopted($package['id']);
    }

    public function testImportRejectsFileHashMismatch(): void
    {
        $package = $this->packageInTarget();
        $entries = $this->readZip($package['path']);
        $entries['files/' . $this->pathOf($package['file'])] = 'verfälscht';
        $this->writeZip($package['path'], $entries);

        $result = $this->targetPackages()->importAll();

        $this->assertSame([$package['id'] => 'Dateien des Pakets sind ungültig.'], $result);
        $this->assertNothingAdopted($package['id']);
    }

    public function testRollbackAfterAdoptedFilesSparesPoolFilesThatWereAlreadyThere(): void
    {
        $package = $this->packageInTarget();
        $poolFile = $this->targetDir() . '/files/' . $this->pathOf($package['file']);
        mkdir(dirname($poolFile), 0750, true);
        file_put_contents($poolFile, 'Inhalt A');
        // Ein Verzeichnis unter dem Namen der Metadaten lässt deren Schreiben scheitern,
        // nachdem Dump und Pool-Dateien schon übernommen sind.
        mkdir($this->targetDir() . '/' . $package['id'] . '.json');

        $result = $this->targetPackages()->importAll();

        $this->assertSame([$package['id'] => 'Paket ist unvollständig oder beschädigt.'], $result);
        $this->assertSame('Inhalt A', file_get_contents($poolFile), 'Die Datei lag schon vorher im Pool.');
        $this->assertFileDoesNotExist($this->targetDir() . '/' . $package['id'] . '.files.json');
        $this->assertFileDoesNotExist($this->targetDir() . '/' . $package['id'] . '.sql.gz');
        $this->assertFileExists($this->targetDir() . '/' . $package['id'] . '.zip.failed');
    }

    public function testRollbackAfterAdoptedFilesRemovesFilesThisImportWrote(): void
    {
        $package = $this->packageInTarget();
        mkdir($this->targetDir() . '/' . $package['id'] . '.json');

        $this->targetPackages()->importAll();

        $this->assertSame([], glob($this->targetDir() . '/files/*/*/*') ?: []);
    }

    public function testForeignZipNamesAreIgnored(): void
    {
        $foreign = $this->targetDir() . '/irgendwas.zip';
        file_put_contents($foreign, 'kein Paket');
        $packages = $this->targetPackages();

        $this->assertSame([], $packages->importAll());
        $this->assertSame(['waiting' => [], 'failed' => []], $packages->pendingPackages());
        $this->assertSame('kein Paket', file_get_contents($foreign));
    }

    public function testFailedPackageIsListedAsFailed(): void
    {
        $package = $this->packageInTarget();
        $entries = $this->readZip($package['path']);
        $entries['extra.txt'] = 'unerwartet';
        $this->writeZip($package['path'], $entries);
        $packages = $this->targetPackages();

        $packages->importAll();

        $this->assertSame(
            ['waiting' => [], 'failed' => [$package['id'] . '.zip.failed']],
            $packages->pendingPackages()
        );
    }

    public function testImportThrowsWhenLockIsHeld(): void
    {
        $this->packageInTarget();
        $lock = fopen($this->targetDir() . '/' . BackupLock::LOCK_FILE, 'c');
        flock($lock, LOCK_EX);

        try {
            $this->expectException(BackupLockedException::class);
            $this->targetPackages()->importAll();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
