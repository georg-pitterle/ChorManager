<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Services\EntityAttachmentService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * Der Dateiname kommt vom Hochladenden und ist damit beliebig lang.
 *
 * `attachments.filename` und `attachments.original_name` fassen 255 Zeichen;
 * der gespeicherte Name trägt zusätzlich ein 33 Zeichen langes Präfix. Ohne
 * Kürzung endete ein langer Name in einem Datenbankfehler und damit in einer
 * Fehlerseite - statt in einem gespeicherten Anhang.
 */
final class AttachmentLongFilenameFeatureTest extends TestCase
{
    private const ENTITY_TYPE = 'test_long_filename';

    protected function setUp(): void
    {
        parent::setUp();
        \Tests\Unit\Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnOverlongFilenameIsStoredTruncatedInsteadOfFailing(): void
    {
        $longName = str_repeat('Vertragsentwurf-', 30) . '.pdf';
        $this->assertGreaterThan(255, strlen($longName), 'Der Testname muss die Spalte sprengen.');

        $result = (new EntityAttachmentService(new NullLogger()))->storeUploads(
            $this->uploadedFile($longName),
            self::ENTITY_TYPE,
            4711
        );

        $this->assertSame(1, $result['stored']);
        $this->assertNull($result['error']);

        $attachment = Attachment::where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', 4711)
            ->firstOrFail();

        $this->assertLessThanOrEqual(255, mb_strlen((string) $attachment->filename));
        $this->assertLessThanOrEqual(255, mb_strlen((string) $attachment->original_name));
        $this->assertStringEndsWith('.pdf', (string) $attachment->original_name);
        $this->assertStringEndsWith('.pdf', (string) $attachment->filename);
    }

    /**
     * Ein normaler Name bleibt unangetastet - die Kürzung darf nur greifen,
     * wo sie muss.
     */
    public function testAnOrdinaryFilenameIsKeptAsItIs(): void
    {
        (new EntityAttachmentService(new NullLogger()))->storeUploads(
            $this->uploadedFile('Probenplan Mai.pdf'),
            self::ENTITY_TYPE,
            4712
        );

        $attachment = Attachment::where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', 4712)
            ->firstOrFail();

        $this->assertSame('Probenplan Mai.pdf', (string) $attachment->original_name);
        $this->assertStringEndsWith('_Probenplan Mai.pdf', (string) $attachment->filename);
    }

    /**
     * FinanceController, SongLibraryController und TaskController wickeln ihren
     * Upload noch selbst ab. Sie bauten ihren Namen dabei von Hand zusammen und
     * hatten die Kürzung nie mitbekommen - ein langer Dateiname endete dort in
     * "Data too long for column" und damit in einer Fehlerseite. Seit sie die
     * Namen über den Dienst beziehen, gilt die Spaltenbreite auch für sie.
     *
     * Der Wächter liest nur Dateien, wie Tests\Unit\TestSuite\NoHandBuiltSchemaTest.
     */
    public function testNoControllerBuildsAStoredAttachmentNameByHand(): void
    {
        $offenders = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/Controllers/*.php') ?: [] as $path) {
            $content = (string) file_get_contents($path);

            // Das Namensschema des Dienstes: Zufallspräfix plus Unterstrich.
            if (preg_match('/random_bytes\(16\)\)\s*\.\s*._/', $content) === 1) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Diese Controller bauen den Ablagenamen selbst und umgehen damit die Kürzung.\n"
                . "Nutze EntityAttachmentService::storedName()/originalName():\n"
                . implode("\n", $offenders)
        );
    }

    public function testTheGuardActuallyScansTheControllers(): void
    {
        // Ohne Gegenprobe bliebe der Wächter auch grün, wenn er nach einer
        // Umbenennung des Verzeichnisses gar keine Datei mehr fände.
        $files = glob(dirname(__DIR__, 2) . '/src/Controllers/*.php') ?: [];

        $this->assertGreaterThan(30, count($files), 'Der Wächter findet die Controller nicht mehr.');
    }

    /**
     * Die beiden öffentlichen Namensbauer des Dienstes - sie sind es, die die
     * drei Controller oben nutzen.
     */
    public function testTheSharedNameBuildersRespectTheColumnWidth(): void
    {
        $longName = str_repeat('Kontoauszug-', 40) . '.pdf';
        $this->assertGreaterThan(255, mb_strlen($longName), 'Der Testname muss die Spalte sprengen.');

        $stored = EntityAttachmentService::storedName($longName);
        $original = EntityAttachmentService::originalName($longName);

        $this->assertLessThanOrEqual(255, mb_strlen($stored));
        $this->assertLessThanOrEqual(255, mb_strlen($original));
        $this->assertStringEndsWith('.pdf', $stored);
        $this->assertStringEndsWith('.pdf', $original);

        // Das Zufallspräfix trennt zwei Uploads desselben Namens.
        $this->assertNotSame($stored, EntityAttachmentService::storedName($longName));
        $this->assertSame('Probenplan.pdf', EntityAttachmentService::originalName('Probenplan.pdf'));
    }

    private function uploadedFile(string $clientFilename): UploadedFile
    {
        $content = '%PDF-1.4 Testinhalt';

        return new UploadedFile(
            (new StreamFactory())->createStream($content),
            $clientFilename,
            'application/pdf',
            strlen($content),
            UPLOAD_ERR_OK
        );
    }
}
