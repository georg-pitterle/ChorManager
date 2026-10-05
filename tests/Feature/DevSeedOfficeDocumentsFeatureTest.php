<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DevSeedAttachmentFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Damit sich die Bearbeitung im Browser lokal ausprobieren lässt, legt der Seed
 * echte ODF- und OOXML-Dateien an.
 */
final class DevSeedOfficeDocumentsFeatureTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }

    private function open(string $content): \ZipArchive
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'office');
        $this->tempFiles[] = $path;
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        return $zip;
    }

    public function testOdtHasUncompressedMimetypeFirstAndTheText(): void
    {
        $fixture = DevSeedAttachmentFixtures::odt('Protokoll der Vorstandssitzung');
        $zip = $this->open($fixture['content']);

        $this->assertSame('odt', $fixture['extension']);
        // ODF verlangt "mimetype" als ersten, unkomprimierten Eintrag.
        $this->assertSame('mimetype', $zip->statIndex(0)['name']);
        $this->assertSame(\ZipArchive::CM_STORE, $zip->statIndex(0)['comp_method']);
        $this->assertSame('application/vnd.oasis.opendocument.text', $zip->getFromName('mimetype'));
        $this->assertStringContainsString('Protokoll der Vorstandssitzung', (string) $zip->getFromName('content.xml'));
    }

    public function testDocxContainsDocumentPartAndText(): void
    {
        $fixture = DevSeedAttachmentFixtures::docx('Probenplan Herbst & Winter');
        $zip = $this->open($fixture['content']);

        $this->assertSame('docx', $fixture['extension']);
        $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
        $this->assertStringContainsString('Probenplan Herbst &amp; Winter', (string) $zip->getFromName('word/document.xml'));
    }

    public function testDevSeedUploadsOfficeDocumentsAndResetsTokens(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertStringContainsString('DevSeedAttachmentFixtures::odt(', $content);
        $this->assertStringContainsString('DevSeedAttachmentFixtures::docx(', $content);
        $this->assertStringContainsString("'office_access_tokens',", $content);
    }
}
