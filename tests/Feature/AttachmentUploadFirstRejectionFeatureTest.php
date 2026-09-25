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
use Tests\Unit\Bootstrap;

/**
 * Bei mehreren beanstandeten Dateien wird die *erste* Beanstandung gemeldet.
 *
 * Der Klassenkommentar von EntityAttachmentService sagt das seit immer, der Rumpf
 * tat es nicht: Eine schlichte Zuweisung überschrieb den Wert bei jeder weiteren
 * Ablehnung, sodass die letzte Meldung gewann. Wer fünf Dateien wählt und bei der
 * ersten etwas falsch macht, soll darüber lesen und nicht über die fünfte.
 */
final class AttachmentUploadFirstRejectionFeatureTest extends TestCase
{
    private const ENTITY_TYPE = 'test_first_rejection';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
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

    public function testTheFirstRejectionIsReported(): void
    {
        $result = (new EntityAttachmentService(new NullLogger()))->storeUploads(
            [
                // Verbotener Typ - die erste Beanstandung.
                $this->uploadedFile('skript.php', 'application/x-php'),
                // Zweite Beanstandung, anderer Text. Sie darf die erste nicht verdrängen.
                $this->uploadedFile('film.mp4', 'video/mp4'),
                // Eine gültige Datei dazwischen: Sie wird gespeichert, der Lauf
                // bricht nicht ab.
                $this->uploadedFile('notiz.pdf', 'application/pdf'),
            ],
            self::ENTITY_TYPE,
            9100
        );

        $this->assertSame(1, $result['stored'], 'Die gültige Datei wird gespeichert.');
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('application/x-php', (string) $result['error']);
        $this->assertStringNotContainsString('video/mp4', (string) $result['error']);

        $stored = Attachment::where('entity_type', self::ENTITY_TYPE)->where('entity_id', 9100)->get();
        $this->assertCount(1, $stored);
        $this->assertSame('notiz.pdf', (string) $stored->first()->original_name);
    }

    private function uploadedFile(string $clientFilename, string $mediaType): UploadedFile
    {
        $content = 'Testinhalt';

        return new UploadedFile(
            (new StreamFactory())->createStream($content),
            $clientFilename,
            $mediaType,
            strlen($content),
            UPLOAD_ERR_OK
        );
    }
}
