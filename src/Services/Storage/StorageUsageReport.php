<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Ergebnis einer vollständigen Messung: die Wurzelknoten der Bereiche.
 */
final class StorageUsageReport
{
    /**
     * @param list<StorageUsageNode> $areas
     */
    public function __construct(
        public readonly array $areas,
        public readonly \DateTimeImmutable $generatedAt
    ) {
    }

    public function totalBytes(): int
    {
        return array_sum(array_map(static fn (StorageUsageNode $area): int => $area->bytes, $this->areas));
    }

    public function area(string $key): ?StorageUsageNode
    {
        foreach ($this->areas as $area) {
            if ($area->key === $key) {
                return $area;
            }
        }

        return null;
    }
}
