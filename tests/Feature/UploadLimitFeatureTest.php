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

    /**
     * Jeder Weg, über den eine Datei hereinkommt, muss den PHP-Fehlercode über
     * UploadValidator::getUploadErrorMessage() in Text übersetzen - sonst
     * verschwindet ein "Datei zu groß für das Upload-Limit" lautlos und die
     * hochladende Person sieht eine leere Erfolgsmeldung.
     *
     * Zwei Arten von Wegen: Wer noch selbst hochlädt, ruft die Abbildung selbst
     * auf. Wer über EntityAttachmentService geht, bekommt sie von dort - dann muss
     * die Datei den Dienst nachweisbar nutzen, statt die Prüfung stillschweigend
     * ganz zu verlieren. Ein Controller wandert von der ersten Liste in die
     * zweite, sobald er umgestellt ist.
     *
     * Beides zugleich ist erlaubt und kommt vor: FinanceController lädt die Belege
     * über den Dienst hoch, liest den Kontoauszug für den Import aber weiterhin
     * selbst ein. Geprüft wird deshalb, dass jeder Weg in mindestens einer Liste
     * steht - nicht in genau einer.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function uploadPaths(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            // Wickeln den Upload noch selbst ab.
            [
                $root . '/src/Controllers/AppSettingController.php',
                // Liest den Kontoauszug für den Import selbst ein.
                $root . '/src/Controllers/FinanceController.php',
                // Der Dienst selbst, für alle delegierenden Wege unten.
                $root . '/src/Services/EntityAttachmentService.php',
            ],
            // Laden über EntityAttachmentService::storeUploads() hoch.
            [
                $root . '/src/Controllers/FinanceController.php',
                $root . '/src/Controllers/SongLibraryController.php',
                $root . '/src/Controllers/SponsorController.php',
                $root . '/src/Controllers/SponsorshipController.php',
                $root . '/src/Controllers/TaskController.php',
            ],
        ];
    }

    public function testUploadControllersUseCentralUploadErrorMapping(): void
    {
        [$ownHandling] = $this->uploadPaths();

        foreach ($ownHandling as $path) {
            $content = file_get_contents($path);
            $this->assertIsString($content, $path);
            $this->assertStringContainsString(
                'UploadValidator::getUploadErrorMessage(',
                $content,
                basename($path) . ' lädt selbst hoch und muss die zentrale Fehlerabbildung aufrufen.'
            );
        }
    }

    public function testDelegatingControllersGoThroughTheSharedService(): void
    {
        [, $delegating] = $this->uploadPaths();

        foreach ($delegating as $path) {
            $content = file_get_contents($path);
            $this->assertIsString($content, $path);
            $this->assertStringContainsString(
                'storeUploads(',
                $content,
                basename($path) . ' soll den gemeinsamen Anhang-Dienst nutzen.'
            );
        }
    }

    /**
     * Gegenprobe: Kein Weg darf aus beiden Listen herausfallen. Ohne sie bliebe der
     * Wächter grün, wenn ein Controller beim Umstellen aus der ersten Liste
     * gestrichen und in die zweite vergessen wird.
     */
    public function testEveryUploadPathIsListedAtLeastOnce(): void
    {
        [$ownHandling, $delegating] = $this->uploadPaths();
        $all = array_values(array_unique([...$ownHandling, ...$delegating]));

        foreach ($all as $path) {
            $this->assertFileExists($path);
        }

        // Jede Datei in src/, die getUploadedFiles() auswertet, muss gelistet sein.
        $handlers = [];
        foreach ((array) glob(dirname(__DIR__, 2) . '/src/Controllers/*.php') as $path) {
            $content = (string) file_get_contents($path);
            if (str_contains($content, 'getUploadedFiles()')) {
                $handlers[] = $path;
            }
        }

        $this->assertNotEmpty($handlers, 'Der Wächter findet keinen Upload-Weg mehr.');
        $this->assertSame([], array_diff($handlers, $all), 'Diese Upload-Wege fehlen in den Listen.');
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
        $this->assertStringContainsString('Überschreitet das Upload-Limit', $jsContent);
        $this->assertStringContainsString('Die gesamte Upload-Größe', $jsContent);
    }
}
