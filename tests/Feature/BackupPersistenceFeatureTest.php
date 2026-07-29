<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Guards the persistence of created backups across container recreation.
 *
 * Without a named volume the backup directory lives in the container's writable
 * layer, so every stack update, image pull or recreate silently discards every
 * backup while the app just creates an empty directory again. A freshly created
 * volume is owned by root, so the entrypoint has to hand it to the PHP-FPM
 * worker user before the app can write into it.
 */
class BackupPersistenceFeatureTest extends TestCase
{
    private const BACKUP_PATH = '/var/backups/chormanager';

    private function productionCompose(): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/dist/docker-compose.prod.yml');
        $this->assertIsString($content);

        return $content;
    }

    private function entrypoint(): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/entrypoint.sh');
        $this->assertIsString($content);

        return $content;
    }

    public function testProductionStackMountsANamedVolumeForBackups(): void
    {
        $compose = $this->productionCompose();

        $this->assertStringContainsString('- backup_data:${BACKUP_DIR:-' . self::BACKUP_PATH . '}', $compose);
        $this->assertMatchesRegularExpression('/^volumes:\n(?:.*\n)*?  backup_data:$/m', $compose);
    }

    public function testProductionStackPointsTheAppAtTheMountedBackupDirectory(): void
    {
        $this->assertStringContainsString(
            'BACKUP_DIR: ${BACKUP_DIR:-' . self::BACKUP_PATH . '}',
            $this->productionCompose()
        );
    }

    public function testEntrypointPreparesTheBackupDirectoryForTheWorkerUser(): void
    {
        $entrypoint = $this->entrypoint();

        $this->assertStringContainsString('BACKUP_DIR="${BACKUP_DIR:-/var/www/html/var/backups}"', $entrypoint);
        $this->assertStringContainsString('mkdir -p "${BACKUP_DIR}"', $entrypoint);
        $this->assertStringContainsString('chown -R www-data:www-data "${BACKUP_DIR}"', $entrypoint);
        $this->assertStringContainsString('chmod 750 "${BACKUP_DIR}"', $entrypoint);
    }

    public function testEntrypointPreparesBackupsBeforePhpFpmStarts(): void
    {
        $entrypoint = $this->entrypoint();

        $chownPosition = strpos($entrypoint, 'chown -R www-data:www-data "${BACKUP_DIR}"');
        $fpmPosition = strpos($entrypoint, 'php-fpm -F');

        $this->assertIsInt($chownPosition);
        $this->assertIsInt($fpmPosition);
        $this->assertLessThan($fpmPosition, $chownPosition);
    }

    public function testDeploymentEnvExampleDocumentsTheBackupDirectory(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/dist/.env.example');

        $this->assertIsString($content);
        $this->assertStringContainsString('BACKUP_DIR=' . self::BACKUP_PATH, $content);
    }
}
