<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\BackupController;
use App\Models\AppSetting;
use App\Services\BackupService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Tests\Unit\Services\Fakes\FakeDumpRunner;

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

        $twig = $this->createMock(Twig::class);
        $this->controller = new BackupController($twig, $this->backupService, new NullLogger());

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
        $this->assertSame('application/gzip', $response->getHeaderLine('Content-Type'));
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
}
