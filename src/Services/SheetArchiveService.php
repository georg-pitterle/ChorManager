<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SheetArchive;
use App\Models\SheetArchiveLineItem;
use App\Models\Song;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;

class SheetArchiveService
{
    /**
     * Save archive data (metadata + line items)
     * Merges duplicate voice categories and validates data
     *
     * @param int $songId
     * @param string|null $archiveNumber
     * @param string|null $location
     * @param array<array{voice_category: string, count: int}> $lineItems
     */
    public function saveArchiveData(
        int $songId,
        ?string $archiveNumber,
        ?string $location,
        array $lineItems
    ): SheetArchive {
        // Validate input
        $this->validateLineItems($lineItems);

        // Find or create archive record
        $archive = SheetArchive::firstOrCreate(
            ['song_id' => $songId],
            [
                'archive_number' => $archiveNumber ? trim($archiveNumber) : null,
                'location' => $location ? trim($location) : null,
            ]
        );

        // Update metadata
        $archive->update([
            'archive_number' => $archiveNumber ? trim($archiveNumber) : null,
            'location' => $location ? trim($location) : null,
        ]);

        // Merge duplicate categories and filter empty entries
        $mergedItems = $this->mergeAndFilterLineItems($lineItems);

        // Delete all existing line items and recreate
        $archive->lineItems()->delete();

        // Create new line items
        $sortOrder = 0;
        foreach ($mergedItems as $item) {
            SheetArchiveLineItem::create([
                'sheet_archive_id' => $archive->id,
                'voice_category' => trim($item['voice_category']),
                'count' => (int) $item['count'],
                'sort_order' => $sortOrder++,
            ]);
        }

        return $archive->fresh();
    }

    /**
     * Get archive data for a song
     */
    public function getArchiveData(int $songId): ?SheetArchive
    {
        return SheetArchive::where('song_id', $songId)->with('lineItems')->first();
    }

    /**
     * Get all voice categories used across archives
     */
    public function getAllVoiceCategories(): array
    {
        return SheetArchiveLineItem::select('voice_category')
            ->distinct()
            ->orderBy('voice_category', 'asc')
            ->pluck('voice_category')
            ->toArray();
    }

    /**
     * Validate line items structure and content
     *
     * @throws InvalidArgumentException
     */
    private function validateLineItems(array $lineItems): void
    {
        foreach ($lineItems as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Each line item must be an array');
            }

            if (empty($item['voice_category']) || !is_string($item['voice_category'])) {
                throw new InvalidArgumentException('voice_category must be a non-empty string');
            }

            $category = trim($item['voice_category']);

            // Validate length (DB column: varchar(100))
            if (strlen($category) > 100) {
                throw new InvalidArgumentException('voice_category must not exceed 100 characters');
            }

            // Validate format (allow letters, numbers, spaces, common punctuation, umlauts)
            if (!preg_match('/^[\p{L}\p{N}\s\-\.\/\(\)äöüßÄÖÜ]+$/u', $category)) {
                throw new InvalidArgumentException('voice_category contains invalid characters');
            }

            if (!isset($item['count']) || !is_numeric($item['count'])) {
                throw new InvalidArgumentException('count must be numeric');
            }

            $count = (int) $item['count'];
            if ($count < 0) {
                throw new InvalidArgumentException('count must be >= 0');
            }
        }
    }

    /**
     * Merge duplicate voice categories (sum counts) and filter empty entries
     *
     * @param array<array{voice_category: string, count: int}> $lineItems
     * @return array<array{voice_category: string, count: int}>
     */
    private function mergeAndFilterLineItems(array $lineItems): array
    {
        $merged = [];

        foreach ($lineItems as $item) {
            $category = trim($item['voice_category']);
            $count = (int) $item['count'];

            // Skip empty categories or zero counts
            if (empty($category) || $count === 0) {
                continue;
            }

            if (!isset($merged[$category])) {
                $merged[$category] = 0;
            }
            $merged[$category] += $count;
        }

        // Convert back to indexed array format
        $result = [];
        foreach ($merged as $category => $count) {
            $result[] = [
                'voice_category' => $category,
                'count' => $count,
            ];
        }

        return $result;
    }
}
