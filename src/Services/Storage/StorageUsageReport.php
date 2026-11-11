<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Ergebnis einer vollständigen Messung: die Wurzelknoten der Bereiche und die
 * Ablagen, in die nicht geschrieben werden kann.
 */
final class StorageUsageReport
{
    /**
     * @param list<StorageUsageNode> $areas
     * @param list<array{label: string, path: string, consequence: string}> $writeProblems
     *     Ablagen ohne Schreibzugriff, aus WriteAccessCheck. Sie gehören in
     *     dieselbe Antwort wie die Zahlen: Wer auf die Speicherübersicht
     *     schaut, schaut nach dem Zustand der Ablagen - und ein Dienst, der
     *     still nichts mehr schreibt, ist genau das.
     */
    public function __construct(
        public readonly array $areas,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly array $writeProblems = []
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
