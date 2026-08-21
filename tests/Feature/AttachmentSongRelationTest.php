<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Song;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Anhaenge haengen polymorph an entity_type/entity_id. Die Rueckrichtung auf das
 * Lied muss diese Unterscheidung treffen, ohne dabei ueber die eigene Spalte in
 * der Abfrage auf songs zu stolpern.
 */
final class AttachmentSongRelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        Capsule::connection()->rollBack();
        parent::tearDown();
    }

    public function testASongAttachmentResolvesItsSong(): void
    {
        $song = Song::create(['title' => 'Anhang-Lied ' . bin2hex(random_bytes(4))]);
        $attachment = $this->attachment('song', (int) $song->id);

        $this->assertSame((int) $song->id, (int) $attachment->song?->id);
    }

    /**
     * entity_type steht auf der Anhang-Zeile selbst. Als Relations-Bedingung
     * landete die Spalte in der Abfrage auf songs und liess die Relation mit
     * einem SQL-Fehler auflaufen.
     */
    public function testAnAttachmentOfAnotherEntityHasNoSong(): void
    {
        $song = Song::create(['title' => 'Fremd-Lied ' . bin2hex(random_bytes(4))]);
        $attachment = $this->attachment('finance', (int) $song->id);

        $this->assertNull($attachment->song);
    }

    private function attachment(string $entityType, int $entityId): Attachment
    {
        return Attachment::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'filename' => 'noten.pdf',
            'original_name' => 'noten.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3,
            'file_content' => 'pdf',
        ]);
    }
}
