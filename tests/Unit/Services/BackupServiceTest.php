<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\RememberLogin;
use App\Models\User;
use App\Services\BackupLimitReachedException;
use App\Services\BackupService;
use App\Services\SessionInvalidationService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;
use Tests\Unit\Services\Fakes\FakeDumpRunner;
use Tests\Unit\Services\Fakes\FakeMigrationRunner;
use App\Services\BackupMigrationFailedException;

final class BackupServiceTest extends TestCase
{
    private string $backupDir;
    private FakeDumpRunner $dumpRunner;

    /**
     * Kennung und Reihenfolge eines Backups haben Sekundenauflösung. Statt zwischen
     * zwei Backups eine echte Sekunde verstreichen zu lassen - sechsmal im Lauf, knapp
     * sieben Sekunden - stellt der Test die Uhr des Dienstes selbst weiter.
     */
    private int $clockSeconds = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clockSeconds = 1_700_000_000;
        Bootstrap::setupTestDatabase();
        // Der Dump läuft hier über FakeDumpRunner, also ohne zweite Verbindung: eine
        // Transaktion ist unbedenklich und nimmt die angelegte Person wieder zurück.
        Capsule::connection()->beginTransaction();

        $this->backupDir = sys_get_temp_dir() . '/chormanager_backup_test_' . bin2hex(random_bytes(4));
        $this->dumpRunner = new FakeDumpRunner();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }

        parent::tearDown();
    }

    private function makeService(int $maxManual = 5, int $maxAuto = 5, ?string $mailKeyId = null): BackupService
    {
        return new BackupService(
            $this->dumpRunner,
            new NullLogger(),
            $this->backupDir,
            $maxManual,
            $maxAuto,
            true,
            'chormanager_test',
            'test-version',
            $mailKeyId,
            new SessionInvalidationService(),
            fn(): int => $this->clockSeconds
        );
    }

    /**
     * Eine Sekunde weiter - genug, damit das nächste Backup eine eigene Kennung bekommt.
     */
    private function advanceClock(): void
    {
        $this->clockSeconds++;
    }

    public function testCreateManualBackupWritesDataAndMetadataFiles(): void
    {
        $service = $this->makeService();

        $metadata = $service->create(BackupService::TYPE_MANUAL, 7);

        $this->assertSame('manual', $metadata['type']);
        $this->assertSame(7, $metadata['created_by']);
        $this->assertFileExists($this->backupDir . '/' . $metadata['id'] . '.sql.gz');
        $this->assertFileExists($this->backupDir . '/' . $metadata['id'] . '.json');
        $this->assertSame(1, $this->dumpRunner->dumpCallCount);
    }

    /**
     * Ein Abgleich nach außen (rclone), der mitten in einen Dump fällt, darf unter
     * dem endgültigen Namen keine halbe Datei finden - er würde sie übertragen.
     */
    public function testDumpOnlyAppearsUnderItsFinalNameOnceItIsComplete(): void
    {
        $finalNamesWhileDumping = null;
        $this->dumpRunner->whileDumping = function () use (&$finalNamesWhileDumping): void {
            $finalNamesWhileDumping = glob($this->backupDir . '/backup_*.sql.gz') ?: [];
        };

        $metadata = $this->makeService()->create(BackupService::TYPE_MANUAL, null);

        $this->assertSame([], $finalNamesWhileDumping);
        $this->assertSame(
            [$this->backupDir . '/' . $metadata['id'] . '.sql.gz'],
            glob($this->backupDir . '/backup_*.sql.gz')
        );
    }

    public function testFailedDumpLeavesNoFileBehind(): void
    {
        $this->dumpRunner->whileDumping = function (): void {
            throw new \RuntimeException('mysqldump abgebrochen');
        };

        try {
            $this->makeService()->create(BackupService::TYPE_MANUAL, null);
            $this->fail('Ein abgebrochener Dump muss als Fehler ankommen.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('mysqldump abgebrochen', $exception->getMessage());
        }

        $this->assertSame([], glob($this->backupDir . '/*') ?: []);
    }

    /**
     * Ohne Metadaten ist ein Backup kein Backup: `list()` zeigt es nicht, und weil die
     * Rotation nur zählt, was dort steht, räumt sie den Dump auch nie wieder weg. Er
     * bliebe für immer liegen und füllte den Datenträger. Scheitert das Schreiben der
     * Metadaten - typischerweise eine volle Platte -, muss der Lauf deshalb genauso
     * aufräumen wie ein abgebrochener Dump.
     *
     * Im Test belegt ein Verzeichnis den Platz der Metadaten-Datei; file_put_contents()
     * scheitert daran so wie an einem vollen Datenträger.
     */
    public function testUnwritableMetadataLeavesNoOrphanedDumpBehind(): void
    {
        $this->dumpRunner->whileDumping = function (string $destinationPath): void {
            $base = basename($destinationPath, '.sql.gz.partial');
            mkdir(dirname($destinationPath) . '/' . $base . '.json');
        };

        $failure = null;
        try {
            $this->makeService()->create(BackupService::TYPE_MANUAL, null);
        } catch (\RuntimeException $caught) {
            $failure = $caught;
        }

        $this->assertNotNull($failure, 'Nicht schreibbare Metadaten müssen als Fehler ankommen.');
        $this->assertStringContainsString('metadata', $failure->getMessage());

        $this->assertSame([], glob($this->backupDir . '/backup_*.sql.gz') ?: []);
        $this->assertSame([], glob($this->backupDir . '/backup_*.partial') ?: []);
        $this->assertSame([], $this->makeService()->list());
    }

    public function testManualBackupBlocksWhenLimitReached(): void
    {
        $service = $this->makeService(maxManual: 1);

        $service->create(BackupService::TYPE_MANUAL, 1);

        $this->expectException(BackupLimitReachedException::class);
        $service->create(BackupService::TYPE_MANUAL, 1);
    }

    public function testAutoBackupRotatesOldestWhenLimitReached(): void
    {
        $service = $this->makeService(maxAuto: 2);

        $first = $service->create(BackupService::TYPE_AUTO, null);
        $this->advanceClock();
        $second = $service->create(BackupService::TYPE_AUTO, null);
        $this->advanceClock();
        $third = $service->create(BackupService::TYPE_AUTO, null);

        $remainingIds = array_column($service->list(), 'id');

        $this->assertCount(2, $remainingIds);
        $this->assertNotContains($first['id'], $remainingIds);
        $this->assertContains($second['id'], $remainingIds);
        $this->assertContains($third['id'], $remainingIds);
    }

    /**
     * Wird die Obergrenze nachträglich gesenkt, liegen mehrere Backups zu viel
     * herum. Ein einzelner Lauf muss bis unter das Limit rotieren, sonst bleibt
     * der Ordner dauerhaft über der Grenze.
     */
    public function testAutoBackupRotatesDownToTheLimitAfterItWasLowered(): void
    {
        $wideLimit = $this->makeService(maxAuto: 5);

        $wideLimit->create(BackupService::TYPE_AUTO, null);
        $this->advanceClock();
        $wideLimit->create(BackupService::TYPE_AUTO, null);
        $this->advanceClock();
        $third = $wideLimit->create(BackupService::TYPE_AUTO, null);
        $this->advanceClock();

        $narrowLimit = $this->makeService(maxAuto: 2);
        $fourth = $narrowLimit->create(BackupService::TYPE_AUTO, null);

        $remainingIds = array_column($narrowLimit->list(), 'id');

        $this->assertCount(2, $remainingIds);
        $this->assertContains($fourth['id'], $remainingIds);
        $this->assertContains($third['id'], $remainingIds);
    }

    public function testListReturnsEntriesSortedNewestFirst(): void
    {
        $service = $this->makeService();

        $first = $service->create(BackupService::TYPE_MANUAL, 1);
        $this->advanceClock();
        $second = $service->create(BackupService::TYPE_MANUAL, 1);

        $entries = $service->list();

        $this->assertSame($second['id'], $entries[0]['id']);
        $this->assertSame($first['id'], $entries[1]['id']);
    }

    public function testDeleteRemovesDataAndMetadataFiles(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $service->delete($metadata['id']);

        $this->assertFileDoesNotExist($this->backupDir . '/' . $metadata['id'] . '.sql.gz');
        $this->assertFileDoesNotExist($this->backupDir . '/' . $metadata['id'] . '.json');
        $this->assertCount(0, $service->list());
    }

    public function testRestoreVerifiesChecksumAndInvokesDumpRunner(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $service->restore($metadata['id']);

        $this->assertSame(1, $this->dumpRunner->restoreCallCount);
        $this->assertSame(
            $this->backupDir . '/' . $metadata['id'] . '.sql.gz',
            $this->dumpRunner->lastRestoredPath
        );
    }

    public function testRestoreInvalidatesAllRememberLoginTokens(): void
    {
        // Eigene Person statt der fest angenommenen Kennung 1: remember_logins hängt per
        // Fremdschlüssel an users, auf einer frisch aufgesetzten Datenbank schlägt das Anlegen
        // sonst mit einer Integritätsverletzung fehl.
        $user = User::create([
            'email' => 'backup_' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Bea',
            'last_name' => 'Sicherung',
            'is_active' => 1,
        ]);

        RememberLogin::create([
            'user_id' => (int) $user->id,
            'selector' => bin2hex(random_bytes(9)),
            'token_hash' => PasswordHasher::hash('irrelevant'),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
            'created_at' => date('Y-m-d H:i:s'),
            'chain_started_at' => date('Y-m-d H:i:s'),
        ]);

        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $service->restore($metadata['id']);

        $this->assertSame(0, RememberLogin::count());
    }

    /**
     * Der eingespielte Stand kennt die laufenden Anmeldungen nicht mehr. Dass sie
     * dabei entwertet werden, hängt nicht mehr daran, dass diese Klasse an die
     * Remember-Me-Token denkt - sie reicht die Sperre geschlossen weiter.
     */
    public function testRestoreDelegatesLoginInvalidationToTheSessionInvalidationService(): void
    {
        $sessionInvalidation = $this->createMock(SessionInvalidationService::class);
        $sessionInvalidation->expects($this->once())->method('invalidateAllLogins');

        $service = new BackupService(
            $this->dumpRunner,
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version',
            null,
            $sessionInvalidation
        );

        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $service->restore($metadata['id']);

        $this->assertSame(1, $this->dumpRunner->restoreCallCount);
    }

    public function testRestoreThrowsWhenChecksumMismatches(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        file_put_contents($this->backupDir . '/' . $metadata['id'] . '.sql.gz', 'tampered content');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        $service->restore($metadata['id']);
    }

    public function testRestoreRejectsInvalidId(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->restore('../../etc/passwd');
    }

    public function testGetFileReturnsPathFilenameAndSize(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $file = $service->getFile($metadata['id']);

        $this->assertSame($this->backupDir . '/' . $metadata['id'] . '.sql.gz', $file['path']);
        $this->assertSame($metadata['id'] . '.sql.gz', $file['filename']);
        $this->assertSame($metadata['size'], $file['size']);
    }

    public function testMetadataContainsMailKeyIdWhenConfigured(): void
    {
        $service = $this->makeService(mailKeyId: 'a1b2c3d4');

        $metadata = $service->create(BackupService::TYPE_MANUAL, null);

        $this->assertSame('a1b2c3d4', $metadata['mail_key_id']);
    }

    public function testMetadataMailKeyIdIsNullWhenUnavailable(): void
    {
        $service = $this->makeService();

        $metadata = $service->create(BackupService::TYPE_MANUAL, null);

        $this->assertArrayHasKey('mail_key_id', $metadata);
        $this->assertNull($metadata['mail_key_id']);
    }

    /**
     * `delete()`, `getFile()` und `restore()` prüfen die übergebene Kennung gegen ein festes
     * Muster, bauen den Pfad der Datendatei aber aus `id` **innerhalb** der Metadaten-JSON.
     * Trägt die ein `../`, zeigte der Pfad aus dem Backup-Verzeichnis heraus - und `delete()`
     * entfernte die Datei dort. Die geprüfte Kennung ist die einzige, die den Pfad bestimmen darf.
     */
    public function testDeleteNeverFollowsAForeignPathFromTheMetadataFile(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);
        $id = (string) $metadata['id'];

        $outsidePath = sys_get_temp_dir() . '/chormanager_outside_' . bin2hex(random_bytes(4)) . '.sql.gz';
        file_put_contents($outsidePath, 'fremde Datei');

        // Die Metadaten zeigen auf die fremde Datei, die Kennung der Datei selbst bleibt gültig.
        $metaPath = $this->backupDir . '/' . $id . '.json';
        $tampered = $metadata;
        $tampered['id'] = '../' . basename($outsidePath, '.sql.gz');
        file_put_contents($metaPath, (string) json_encode($tampered));

        try {
            $service->delete($id);

            $this->assertFileExists($outsidePath, 'Die Datei ausserhalb des Backup-Verzeichnisses wurde entfernt.');
        } finally {
            if (file_exists($outsidePath)) {
                unlink($outsidePath);
            }
        }
    }

    /**
     * Ein Eintrag, dessen Metadaten eine andere Kennung tragen als ihre Datei, ist nicht
     * stimmig und gehört nicht in die Liste - sonst stünde dort ein Backup, dessen Daten
     * woanders liegen.
     */
    public function testListSkipsEntriesWhoseMetadataIdDoesNotMatchTheFileName(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);
        $id = (string) $metadata['id'];

        $tampered = $metadata;
        $tampered['id'] = '../etc/passwd';
        file_put_contents($this->backupDir . '/' . $id . '.json', (string) json_encode($tampered));

        $this->assertSame([], $service->list());
    }

    public function testListFlagsMailKeyMismatch(): void
    {
        $this->makeService(mailKeyId: 'aaaa1111')->create(BackupService::TYPE_MANUAL, null);

        $listed = $this->makeService(mailKeyId: 'bbbb2222')->list();

        $this->assertTrue($listed[0]['mail_key_mismatch']);
    }

    public function testListDoesNotFlagMatchingMailKey(): void
    {
        $this->makeService(mailKeyId: 'aaaa1111')->create(BackupService::TYPE_MANUAL, null);

        $listed = $this->makeService(mailKeyId: 'aaaa1111')->list();

        $this->assertFalse($listed[0]['mail_key_mismatch']);
    }

    public function testListDoesNotFlagWhenEitherKeyIsUnknown(): void
    {
        $this->makeService(mailKeyId: 'aaaa1111')->create(BackupService::TYPE_MANUAL, null);
        $this->advanceClock();
        $this->makeService()->create(BackupService::TYPE_MANUAL, null);

        $this->assertSame([false, false], array_column($this->makeService()->list(), 'mail_key_mismatch'));
        $this->assertSame(
            [false, true],
            array_column($this->makeService(mailKeyId: 'bbbb2222')->list(), 'mail_key_mismatch'),
            'Neuestes zuerst: das ohne Schlüssel ist unbekannt, das mit fremdem Schlüssel weicht ab.'
        );
    }

    public function testListDoesNotFlagAnotherKnownIdOfTheSameKey(): void
    {
        // Vor der Umstellung auf v3 trug dasselbe Backup die v2-Kennung des Schlüssels.
        $this->makeService(mailKeyId: 'old0v2id')->create(BackupService::TYPE_MANUAL, null);
        $service = new BackupService(
            $this->dumpRunner,
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version',
            'new0v3id',
            acceptedMailKeyIds: ['new0v3id', 'old0v2id']
        );

        $this->assertFalse($service->list()[0]['mail_key_mismatch']);
    }

    public function testListFlagsKeyIdOutsideTheKnownIds(): void
    {
        $this->makeService(mailKeyId: 'foreign0')->create(BackupService::TYPE_MANUAL, null);
        $service = new BackupService(
            $this->dumpRunner,
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version',
            'new0v3id',
            acceptedMailKeyIds: ['new0v3id', 'old0v2id']
        );

        $this->assertTrue($service->list()[0]['mail_key_mismatch']);
    }

    private function makeServiceWithMigrations(
        FakeMigrationRunner $migrations,
        ?SessionInvalidationService $sessionInvalidation = null
    ): BackupService {
        return new BackupService(
            $this->dumpRunner,
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version',
            null,
            $sessionInvalidation ?? new SessionInvalidationService(),
            fn(): int => $this->clockSeconds,
            null,
            [],
            $migrations
        );
    }

    /**
     * Anlass: Ein Backup stammt aus der Zeit vor einem Schema-Update. Ohne Migration
     * liefe die Anwendung bis zum nächsten Containerstart auf dem alten Schema.
     */
    public function testRestoreRunsMigrationsAfterTheDatabaseWasRestored(): void
    {
        $migrations = new FakeMigrationRunner();
        $service = $this->makeServiceWithMigrations($migrations);
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $service->restore($metadata['id']);

        $this->assertSame(1, $this->dumpRunner->restoreCallCount);
        $this->assertSame(1, $migrations->callCount);
    }

    public function testRestoreWithoutMigrationRunnerStillWorks(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $service->restore($metadata['id']);

        $this->assertSame(1, $this->dumpRunner->restoreCallCount);
    }

    public function testFailedMigrationIsReportedAsItsOwnExceptionAndStillEndsAllLogins(): void
    {
        $migrations = new FakeMigrationRunner();
        $migrations->failWith = new \RuntimeException('Table already exists');
        $sessionInvalidation = $this->createMock(SessionInvalidationService::class);
        $sessionInvalidation->expects($this->once())->method('invalidateAllLogins');
        $service = $this->makeServiceWithMigrations($migrations, $sessionInvalidation);
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $this->expectException(BackupMigrationFailedException::class);
        $service->restore($metadata['id']);
    }

    public function testMigrationsDoNotRunWhenTheChecksumIsWrong(): void
    {
        $migrations = new FakeMigrationRunner();
        $service = $this->makeServiceWithMigrations($migrations);
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);
        file_put_contents($this->backupDir . '/' . $metadata['id'] . '.sql.gz', 'manipuliert');

        try {
            $service->restore($metadata['id']);
            $this->fail('Eine falsche Prüfsumme muss den Restore abbrechen.');
        } catch (\RuntimeException) {
            $this->assertSame(0, $migrations->callCount);
        }
    }
}
