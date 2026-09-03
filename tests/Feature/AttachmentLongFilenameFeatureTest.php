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
