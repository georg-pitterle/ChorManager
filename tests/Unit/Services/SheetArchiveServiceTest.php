<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SheetArchive;
use App\Models\SheetArchiveLineItem;
use App\Models\Song;
use App\Models\Project;
use App\Services\SheetArchiveService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class SheetArchiveServiceTest extends TestCase
{
    private SheetArchiveService $service;
    private int $testSongId;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $this->service = new SheetArchiveService();

        // Create a test song (no project_id - songs are repertoire-agnostic in the new schema)
        $song = Song::create([
            'title' => 'Test Song',
            'composer' => 'Test Composer',
        ]);
        $this->testSongId = $song->id;
    }

    public function testSaveArchiveDataCreatesNewArchive(): void
    {
        $lineItems = [
            ['voice_category' => 'Sopran', 'count' => 5],
            ['voice_category' => 'Alt', 'count' => 4],
        ];

        $result = $this->service->saveArchiveData(
            $this->testSongId,
            'ARCH-001',
            'Shelf A',
            $lineItems
        );

        $this->assertInstanceOf(SheetArchive::class, $result);
        $this->assertEquals('ARCH-001', $result->archive_number);
        $this->assertEquals('Shelf A', $result->location);
        $this->assertEquals(2, count($result->lineItems));
        $this->assertEquals(9, $result->getTotalCount());
    }

    public function testMergeDuplicateVoiceCategories(): void
    {
        $lineItems = [
            ['voice_category' => 'Sopran', 'count' => 3],
            ['voice_category' => 'Sopran', 'count' => 2],
            ['voice_category' => 'Alt', 'count' => 4],
        ];

        $result = $this->service->saveArchiveData(
            $this->testSongId,
            null,
            null,
            $lineItems
        );

        $this->assertEquals(2, count($result->lineItems));
        $sopranItem = $result->lineItems->where('voice_category', 'Sopran')->first();
        $this->assertEquals(5, $sopranItem->count);
        $this->assertEquals(9, $result->getTotalCount());
    }

    public function testFilterZeroCountsAndEmptyCategories(): void
    {
        $lineItems = [
            ['voice_category' => 'Sopran', 'count' => 0],
            ['voice_category' => 'Alt', 'count' => 3],
            ['voice_category' => 'Bass', 'count' => 0],
        ];

        $result = $this->service->saveArchiveData(
            $this->testSongId,
            null,
            null,
            $lineItems
        );

        $this->assertEquals(1, count($result->lineItems));
        $this->assertEquals('Alt', $result->lineItems[0]->voice_category);
        $this->assertEquals(3, $result->getTotalCount());
    }

    public function testValidationRejectsNegativeCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('count must be >= 0');

        $lineItems = [
            ['voice_category' => 'Sopran', 'count' => -1],
        ];

        $this->service->saveArchiveData($this->testSongId, null, null, $lineItems);
    }

    public function testValidationRejectsEmptyCategory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('voice_category must be a non-empty string');

        $lineItems = [
            ['voice_category' => '', 'count' => 5],
        ];

        $this->service->saveArchiveData($this->testSongId, null, null, $lineItems);
    }

    public function testGetAllVoiceCategories(): void
    {
        $this->service->saveArchiveData(
            $this->testSongId,
            null,
            null,
            [
                ['voice_category' => 'Sopran', 'count' => 5],
                ['voice_category' => 'Alt', 'count' => 4],
            ]
        );

        $categories = $this->service->getAllVoiceCategories();

        $this->assertContains('Alt', $categories);
        $this->assertContains('Sopran', $categories);
    }
}
