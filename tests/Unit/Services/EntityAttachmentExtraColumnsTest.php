<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attachment;
use App\Services\EntityAttachmentService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\UploadedFile;
use Tests\Unit\Bootstrap;

/**
 * Der Dienst bleibt fachlich neutral: Welche zusätzliche Spalte eine Datei
 * trägt, entscheidet der Aufrufer je Datei - den Zustellmodus braucht der
 * Newsletter, sonst niemand.
 *
 * Die Kennungen der gespeicherten Zeilen kommen mit zurück, weil der Aufrufer
 * sonst raten müsste, welche Zeilen gerade entstanden sind.
 */
final class EntityAttachmentExtraColumnsTest extends TestCase
{
    private const ENTITY_ID = 987660;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    protected function tearDown(): void
    {
        Capsule::table('attachments')
            ->where('entity_type', 'newsletter')
            ->where('entity_id', self::ENTITY_ID)
            ->delete();

        parent::tearDown();
    }

    public function testExtraColumnsCallbackSeesFileSizeAndIsStored(): void
    {
        $service = new EntityAttachmentService(new NullLogger());

        $result = $service->storeUploads(
            [$this->uploadedFile('klein.pdf', 'abc')],
            'newsletter',
            self::ENTITY_ID,
            static fn (int $size): array => ['delivery_mode' => $size < 10 ? 'attach' : 'link']
        );

        $this->assertSame(1, $result['stored']);
        $this->assertCount(1, $result['ids']);
        $this->assertSame('attach', Attachment::query()->find($result['ids'][0])->delivery_mode);
    }

    public function testWithoutCallbackTheColumnKeepsItsDefault(): void
    {
        $service = new EntityAttachmentService(new NullLogger());

        $result = $service->storeUploads(
            [$this->uploadedFile('gross.pdf', 'abcdefghijkl')],
            'newsletter',
            self::ENTITY_ID
        );

        $this->assertSame(1, $result['stored']);
        $this->assertSame('link', Attachment::query()->find($result['ids'][0])->delivery_mode);
    }

    public function testWithoutFilesNothingIsReported(): void
    {
        $service = new EntityAttachmentService(new NullLogger());

        $result = $service->storeUploads(null, 'newsletter', self::ENTITY_ID);

        $this->assertSame(0, $result['stored']);
        $this->assertSame([], $result['ids']);
    }

    private function uploadedFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'application/pdf', strlen($contents), UPLOAD_ERR_OK);
    }
}
