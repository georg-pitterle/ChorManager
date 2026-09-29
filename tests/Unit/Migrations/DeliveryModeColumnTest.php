<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use App\Models\Attachment;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der Zustellmodus entscheidet, ob eine Datei an der Mail hängt oder nur darin
 * verlinkt wird. Fehlt die Spalte, liefe der Versand auf eine stille
 * Standardannahme hinaus - deshalb steht sie hier fest.
 *
 * Der Standard ist `link`: Eine Zeile ohne bewusst gesetzten Modus bläht keine
 * Mail auf. Das gilt auch für die Anhänge aller anderen Bereiche, die die
 * Spalte mittragen und nie auswerten.
 */
final class DeliveryModeColumnTest extends TestCase
{
    private const ENTITY_ID = 987654;

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

    public function testColumnExistsWithLinkAsDefault(): void
    {
        $attachment = $this->createAttachment([]);

        $stored = Capsule::table('attachments')->where('id', $attachment->id)->first();

        $this->assertSame('link', $stored->delivery_mode);
    }

    public function testModeIsFillable(): void
    {
        $attachment = $this->createAttachment(['delivery_mode' => 'attach']);

        $this->assertSame('attach', Attachment::query()->find($attachment->id)->delivery_mode);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createAttachment(array $overrides): Attachment
    {
        return Attachment::create(array_merge([
            'entity_type' => 'newsletter',
            'entity_id' => self::ENTITY_ID,
            'filename' => bin2hex(random_bytes(4)) . '_probe.pdf',
            'original_name' => 'probe.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3,
            'file_content' => 'pdf',
        ], $overrides));
    }
}
