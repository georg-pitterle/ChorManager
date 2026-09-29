<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attachment;
use App\Services\NewsletterAttachmentService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die eine Stelle, die Schwelle, Summe und Modus kennt. Läge das verteilt in
 * Controller, Versand, Mail-Renderer und Queue-Worker, entschiede jede der vier
 * Stellen für sich, und eine spätere Änderung bliebe irgendwo liegen.
 */
final class NewsletterAttachmentServiceTest extends TestCase
{
    private const NEWSLETTER_ID = 987670;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    protected function tearDown(): void
    {
        Capsule::table('attachments')
            ->where('entity_type', 'newsletter')
            ->whereIn('entity_id', [self::NEWSLETTER_ID, self::NEWSLETTER_ID + 1])
            ->delete();

        parent::tearDown();
    }

    public function testSmallFileIsSuggestedAsAttachment(): void
    {
        $service = new NewsletterAttachmentService();

        $this->assertSame(
            'attach',
            $service->suggestMode(NewsletterAttachmentService::ATTACH_SUGGESTION_LIMIT - 1)
        );
    }

    public function testFileAtTheThresholdIsSuggestedAsLink(): void
    {
        $service = new NewsletterAttachmentService();

        $this->assertSame(
            'link',
            $service->suggestMode(NewsletterAttachmentService::ATTACH_SUGGESTION_LIMIT)
        );
    }

    public function testTotalCountsOnlyAttachedFiles(): void
    {
        $this->createAttachment('anhang.pdf', 1000, 'attach');
        $this->createAttachment('link.pdf', 5000, 'link');

        $service = new NewsletterAttachmentService();

        $this->assertSame(1000, $service->attachedTotalBytes(self::NEWSLETTER_ID));
    }

    public function testAttachedFilesCarryContentNameAndMimeType(): void
    {
        $this->createAttachment('anhang.pdf', 12, 'attach');
        $this->createAttachment('link.pdf', 12, 'link');

        $attached = (new NewsletterAttachmentService())->attachedFiles(self::NEWSLETTER_ID);

        $this->assertCount(1, $attached);
        $this->assertSame('anhang.pdf', $attached[0]['name']);
        $this->assertSame('application/pdf', $attached[0]['mime']);
        $this->assertSame('xxxxxxxxxxxx', $attached[0]['content']);
    }

    public function testLinkedFilesCarryNameSizeAndDownloadUrl(): void
    {
        $id = $this->createAttachment('programm.pdf', 5000, 'link');

        $linked = (new NewsletterAttachmentService())->linkedFiles(self::NEWSLETTER_ID, 'https://chor.example/');

        $this->assertCount(1, $linked);
        $this->assertSame('programm.pdf', $linked[0]['name']);
        $this->assertSame(5000, $linked[0]['size']);
        $this->assertSame('https://chor.example/attachments/' . $id . '/download', $linked[0]['url']);
    }

    public function testSetModeRejectsAnAttachmentOfAnotherNewsletter(): void
    {
        $id = $this->createAttachment('fremd.pdf', 100, 'link');

        $service = new NewsletterAttachmentService();

        $this->assertFalse($service->setMode(self::NEWSLETTER_ID + 1, $id, 'attach'));
        $this->assertSame('link', Attachment::query()->find($id)->delivery_mode);
    }

    public function testSetModeRejectsAnUnknownMode(): void
    {
        $id = $this->createAttachment('datei.pdf', 100, 'link');

        $service = new NewsletterAttachmentService();

        $this->assertFalse($service->setMode(self::NEWSLETTER_ID, $id, 'inline'));
        $this->assertSame('link', Attachment::query()->find($id)->delivery_mode);
    }

    public function testSetModeSwitchesTheOwnAttachment(): void
    {
        $id = $this->createAttachment('datei.pdf', 100, 'link');

        $service = new NewsletterAttachmentService();

        $this->assertTrue($service->setMode(self::NEWSLETTER_ID, $id, 'attach'));
        $this->assertSame('attach', Attachment::query()->find($id)->delivery_mode);
    }

    private function createAttachment(string $name, int $size, string $mode): int
    {
        return (int) Attachment::create([
            'entity_type' => 'newsletter',
            'entity_id' => self::NEWSLETTER_ID,
            'filename' => bin2hex(random_bytes(4)) . '_' . $name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'file_size' => $size,
            'file_content' => str_repeat('x', min($size, 12)),
            'delivery_mode' => $mode,
        ])->id;
    }
}
