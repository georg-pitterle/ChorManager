<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\BackupController;
use App\Models\AppSetting;
use App\Services\BackupPackageService;
use App\Services\BackupService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Tests\Unit\Services\Fakes\FakeDumpRunner;
use Tests\Unit\Services\Fakes\FakeMigrationRunner;

final class BackupControllerHttpTest extends TestCase
{
    use TestHttpHelpers;

    private string $backupDir;
    private BackupController $controller;
    private BackupService $backupService;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->backupDir = sys_get_temp_dir() . '/chormanager_backup_http_test_' . bin2hex(random_bytes(4));
        $this->backupService = new BackupService(
            new FakeDumpRunner(),
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version'
        );

        $twig = $this->createStub(Twig::class);
        $this->controller = new BackupController(
            $twig,
            $this->backupService,
            new BackupPackageService($this->backupService, $this->backupDir, null, new NullLogger()),
            new NullLogger()
        );

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }
        AppSetting::query()->where('setting_key', 'session_valid_after')->delete();

        parent::tearDown();
    }

    public function testStoreCreatesManualBackupAndRedirectsWithSuccessFlash(): void
    {
        $_SESSION['user_id'] = 1;

        $request = $this->makeRequest('POST', '/backups');
        $response = $this->controller->store($request, $this->makeResponse());

        $this->assertRedirect($response, '/backups');
        $this->assertSame('Backup erfolgreich erstellt.', $_SESSION['success']);
        $this->assertCount(1, $this->backupService->list());
    }

    public function testStoreSetsErrorFlashWhenManualLimitReached(): void
    {
        $_SESSION['user_id'] = 1;
        for ($i = 0; $i < 5; $i++) {
            $this->backupService->create(BackupService::TYPE_MANUAL, 1);
        }

        $request = $this->makeRequest('POST', '/backups');
        $response = $this->controller->store($request, $this->makeResponse());

        $this->assertRedirect($response, '/backups');
        $this->assertStringContainsString('Maximale Anzahl', $_SESSION['error']);
    }

    public function testRestoreClearsSessionAndRedirectsToLogin(): void
    {
        $metadata = $this->backupService->create(BackupService::TYPE_MANUAL, 1);
        $_SESSION['user_id'] = 1;

        $request = $this->makeRequest('POST', '/backups/' . $metadata['id'] . '/restore');
        $response = $this->controller->restore($request, $this->makeResponse(), ['id' => $metadata['id']]);

        $this->assertRedirect($response, '/login');
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testRestoreWithFailedMigrationNamesTheMigrationInTheFlash(): void
    {
        $migrations = new FakeMigrationRunner();
        $migrations->failWith = new \RuntimeException('Table already exists');
        $service = new BackupService(
            new FakeDumpRunner(),
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version',
            migrationRunner: $migrations
        );
        $controller = new BackupController(
            $this->createStub(Twig::class),
            $service,
            new BackupPackageService($service, $this->backupDir, null, new NullLogger()),
            new NullLogger()
        );
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $response = $controller->restore(
            $this->makeRequest('POST', '/backups/' . $metadata['id'] . '/restore'),
            $this->makeResponse(),
            ['id' => $metadata['id']]
        );

        $this->assertRedirect($response, '/backups');
        $this->assertStringContainsString('Migration', $_SESSION['error']);
    }

    public function testDeleteRemovesBackupAndRedirectsWithSuccessFlash(): void
    {
        $metadata = $this->backupService->create(BackupService::TYPE_MANUAL, 1);

        $request = $this->makeRequest('POST', '/backups/' . $metadata['id'] . '/delete');
        $response = $this->controller->delete($request, $this->makeResponse(), ['id' => $metadata['id']]);

        $this->assertRedirect($response, '/backups');
        $this->assertSame('Backup gelöscht.', $_SESSION['success']);
        $this->assertCount(0, $this->backupService->list());
    }

    public function testDownloadStreamsExistingBackupFile(): void
    {
        $metadata = $this->backupService->create(BackupService::TYPE_MANUAL, 1);

        $request = $this->makeRequest('GET', '/backups/' . $metadata['id'] . '/download');
        $response = $this->controller->download($request, $this->makeResponse(), ['id' => $metadata['id']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString(
            'filename="' . $metadata['id'] . '.zip"',
            $response->getHeaderLine('Content-Disposition')
        );
        $this->assertStringStartsWith('PK', (string) $response->getBody());
    }

    public function testDownloadReturnsNotFoundForUnknownId(): void
    {
        $request = $this->makeRequest('GET', '/backups/does-not-exist/download');
        $response = $this->controller->download($request, $this->makeResponse(), ['id' => 'does-not-exist']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRoutesRegisterBackupEndpointsBehindBackupPermission(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/backups'", $routesContent);
        $this->assertStringContainsString('requiresBackupManagement', $routesContent);
    }

    private function renderIndex(?string $mailKeyId = null): string
    {
        $service = new BackupService(
            new FakeDumpRunner(),
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version',
            $mailKeyId
        );
        // Das Layout braucht Navigation, Assets und Sitzung; geprüft wird hier nur die
        // Backup-Seite selbst, deshalb ein Layout, das nur ihre Blöcke ausgibt.
        $loader = new \Twig\Loader\ChainLoader([
            new \Twig\Loader\ArrayLoader([
                'layout.twig' => '{% block page_header %}{% endblock %}{% block content %}{% endblock %}'
                    . '{% block scripts %}{% endblock %}',
            ]),
            new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]);
        $controller = new BackupController(
            new Twig($loader),
            $service,
            new BackupPackageService($service, $this->backupDir, null, new NullLogger()),
            new NullLogger()
        );

        $response = $controller->index($this->makeRequest('GET', '/backups'), $this->makeResponse());

        return (string) $response->getBody();
    }

    public function testIndexShowsWaitingAndFailedPackages(): void
    {
        $waiting = 'backup_manual_20260101T000000Z_0000aaaa.zip';
        $failed = 'backup_auto_20260101T000000Z_0000bbbb.zip.failed';
        file_put_contents($this->backupDir . '/' . $waiting, 'x');
        file_put_contents($this->backupDir . '/' . $failed, 'x');

        $html = $this->renderIndex();

        $this->assertStringContainsString('Im Backup-Ordner liegen Pakete, die noch nicht übernommen sind:', $html);
        $this->assertStringContainsString($waiting, $html);
        $this->assertStringContainsString('Diese Pakete konnten nicht übernommen werden:', $html);
        $this->assertStringContainsString($failed, $html);
    }

    public function testIndexShowsNoPackageHintsWithoutPackages(): void
    {
        $html = $this->renderIndex();

        $this->assertStringNotContainsString('noch nicht übernommen sind', $html);
        $this->assertStringNotContainsString('konnten nicht übernommen werden', $html);
    }

    public function testIndexMarksMailKeyMismatchOnRow(): void
    {
        (new BackupService(
            new FakeDumpRunner(),
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version',
            'aaaa1111'
        ))->create(BackupService::TYPE_MANUAL, 1);

        $foreign = $this->renderIndex('bbbb2222');
        $own = $this->renderIndex('aaaa1111');

        $this->assertStringContainsString('anderer Mail-Schlüssel', $foreign);
        $this->assertStringContainsString('data-mail-key-mismatch="1"', $foreign);
        $this->assertStringNotContainsString('anderer Mail-Schlüssel</span>', $own);
        $this->assertStringContainsString('data-mail-key-mismatch="0"', $own);
        $this->assertStringContainsString('id="restoreMailKeyWarning"', $own);
    }

    public function testIndexHasNoFilesDownloadButton(): void
    {
        $this->backupService->create(BackupService::TYPE_MANUAL, 1);

        $html = $this->renderIndex();

        $this->assertStringNotContainsString('download-files', $html);
        $this->assertStringContainsString('/download"', $html);
    }

    public function testDownloadFilesRouteIsGone(): void
    {
        $routesContent = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertStringNotContainsString('download-files', $routesContent);
    }
}
