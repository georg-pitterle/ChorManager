<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\UploadValidator;
use PHPUnit\Framework\TestCase;

class UploadLimitFeatureTest extends TestCase
{
    public function testUploadValidatorProvidesHelpfulMessageForSizeLimitErrors(): void
    {
        $message = UploadValidator::getUploadErrorMessage(UPLOAD_ERR_INI_SIZE, 'Datei');

        $this->assertIsString($message);
        $this->assertStringContainsString('Upload-Limit', $message);
        $this->assertStringContainsString('Datei', $message);
    }

    public function testUploadValidatorIgnoresNoFileErrorForOptionalInputs(): void
    {
        $this->assertNull(UploadValidator::getUploadErrorMessage(UPLOAD_ERR_NO_FILE, 'Datei'));
    }

    public function testUploadControllersUseCentralUploadErrorMapping(): void
    {
        $files = [
            dirname(__DIR__, 2) . '/src/Controllers/AppSettingController.php',
            dirname(__DIR__, 2) . '/src/Controllers/FinanceController.php',
            dirname(__DIR__, 2) . '/src/Controllers/SongLibraryController.php',
            dirname(__DIR__, 2) . '/src/Controllers/SponsorshipController.php',
            dirname(__DIR__, 2) . '/src/Controllers/TaskController.php',
        ];

        foreach ($files as $path) {
            $content = file_get_contents($path);
            $this->assertIsString($content);
            $this->assertStringContainsString('UploadValidator::getUploadErrorMessage(', $content);
        }
    }

    public function testProductionNginxImageBakesFixedClientMaxBodySize(): void
    {
        $nginxConfContent = file_get_contents(dirname(__DIR__, 2) . '/nginx.conf');

        $this->assertIsString($nginxConfContent);
        $this->assertStringContainsString('client_max_body_size 100m;', $nginxConfContent);
    }

    public function testProductionComposeDoesNotBindMountAnNginxTemplate(): void
    {
        $composeContent = file_get_contents(dirname(__DIR__, 2) . '/dist/docker-compose.prod.yml');

        $this->assertIsString($composeContent);
        $this->assertStringNotContainsString('CLIENT_MAX_BODY_SIZE', $composeContent);
        $this->assertStringNotContainsString('default.conf.template', $composeContent);
        $this->assertStringNotContainsString('/etc/nginx/conf.d', $composeContent);
    }

    public function testUploadHelperChecksHardLimitBeforeSubmit(): void
    {
        $jsContent = file_get_contents(dirname(__DIR__, 2) . '/public/js/upload-helper.js');

        $this->assertIsString($jsContent);
        $this->assertStringContainsString('HARD_UPLOAD_LIMIT = 100 * 1024 * 1024', $jsContent);
        $this->assertStringContainsString('form.dataset.uploadHardLimitBytes', $jsContent);
        $this->assertStringContainsString('ueberschreitet das Upload-Limit', $jsContent);
        $this->assertStringContainsString('Die gesamte Upload-Groesse', $jsContent);
    }
}
