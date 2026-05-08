<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SheetArchive;
use App\Models\SheetArchiveLineItem;
use App\Models\Song;
use Tests\Unit\Bootstrap;
use PHPUnit\Framework\TestCase;

class SheetArchiveTest extends TestCase
{
    private Song $song;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();

        // Create a test song
        $this->song = Song::create([
            'title' => 'Test Song',
            'composer' => 'Test Composer',
        ]);
    }

    public function testArchiveCanBeCreatedForSong(): void
    {
        $archive = SheetArchive::create([
            'song_id' => $this->song->id,
            'archive_number' => 'ARCH-001',
            'location' => 'Shelf A',
        ]);

        $this->assertInstanceOf(SheetArchive::class, $archive);
        $this->assertEquals($this->song->id, $archive->song_id);
        $this->assertEquals('ARCH-001', $archive->archive_number);
        $this->assertEquals('Shelf A', $archive->location);
    }

    public function testLineItemsCanBeAddedToArchive(): void
    {
        $archive = SheetArchive::create([
            'song_id' => $this->song->id,
            'archive_number' => 'ARCH-001',
            'location' => 'Shelf A',
        ]);

        SheetArchiveLineItem::create([
            'sheet_archive_id' => $archive->id,
            'voice_category' => 'Sopran',
            'count' => 5,
            'sort_order' => 0,
        ]);

        SheetArchiveLineItem::create([
            'sheet_archive_id' => $archive->id,
            'voice_category' => 'Alt',
            'count' => 4,
            'sort_order' => 1,
        ]);

        $this->assertEquals(2, count($archive->lineItems));
        $this->assertEquals(9, $archive->getTotalCount());
    }

    public function testSongHasArchiveRelationship(): void
    {
        $archive = SheetArchive::create([
            'song_id' => $this->song->id,
            'archive_number' => 'ARCH-002',
            'location' => 'Cabinet B',
        ]);

        $song = Song::find($this->song->id);
        $this->assertNotNull($song->sheetArchive);
        $this->assertEquals($archive->id, $song->sheetArchive->id);
    }

    public function testTotalCountCalculation(): void
    {
        $archive = SheetArchive::create([
            'song_id' => $this->song->id,
            'archive_number' => 'ARCH-003',
            'location' => 'Cabinet C',
        ]);

        SheetArchiveLineItem::create([
            'sheet_archive_id' => $archive->id,
            'voice_category' => 'Sopran',
            'count' => 10,
            'sort_order' => 0,
        ]);

        SheetArchiveLineItem::create([
            'sheet_archive_id' => $archive->id,
            'voice_category' => 'Alt',
            'count' => 8,
            'sort_order' => 1,
        ]);

        SheetArchiveLineItem::create([
            'sheet_archive_id' => $archive->id,
            'voice_category' => 'Tenor',
            'count' => 7,
            'sort_order' => 2,
        ]);

        $this->assertEquals(25, $archive->getTotalCount());
    }

    public function testArchiveCanBeCascadeDeleted(): void
    {
        $archive = SheetArchive::create([
            'song_id' => $this->song->id,
            'archive_number' => 'ARCH-004',
            'location' => 'Cabinet D',
        ]);

        SheetArchiveLineItem::create([
            'sheet_archive_id' => $archive->id,
            'voice_category' => 'Sopran',
            'count' => 5,
            'sort_order' => 0,
        ]);

        $archiveId = $archive->id;
        $lineItemId = $archive->lineItems->first()->id;

        // Delete song (should cascade delete archive)
        $this->song->delete();

        // Verify both archive and line items are deleted
        $this->assertNull(SheetArchive::find($archiveId));
        $this->assertNull(SheetArchiveLineItem::find($lineItemId));
    }
}
