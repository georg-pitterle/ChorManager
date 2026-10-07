<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Kurzfassung für die Dashboard-Kachel: nur die Wurzeln. Wird als JSON
 * zwischengespeichert, damit das Dashboard nicht bei jedem Aufruf Verzeichnisse
 * durchläuft.
 */
final class StorageUsageSummary
{
    /**
     * @param list<array{key: string, label: string, bytes: int, failed: bool}> $areas
     */
    public function __construct(
        public readonly array $areas,
        public readonly int $totalBytes,
        public readonly \DateTimeImmutable $generatedAt
    ) {
    }

    public static function fromReport(StorageUsageReport $report): self
    {
        $areas = [];
        foreach ($report->areas as $area) {
            $areas[] = [
                'key' => $area->key,
                'label' => $area->label,
                'bytes' => $area->bytes,
                'failed' => $area->error !== null,
            ];
        }

        return new self($areas, $report->totalBytes(), $report->generatedAt);
    }

    /**
     * @return array{
     *     generated_at: int,
     *     total_bytes: int,
     *     areas: list<array{key: string, label: string, bytes: int, failed: bool}>
     * }
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->getTimestamp(),
            'total_bytes' => $this->totalBytes,
            'areas' => $this->areas,
        ];
    }

    /**
     * Liefert null, sobald irgendein Feld nicht stimmt - eine halb kaputte Datei
     * gilt wie eine fehlende.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $generatedAt = $data['generated_at'] ?? null;
        $totalBytes = $data['total_bytes'] ?? null;
        $rawAreas = $data['areas'] ?? null;
        if (!is_int($generatedAt) || !is_int($totalBytes) || !is_array($rawAreas)) {
            return null;
        }

        $areas = [];
        foreach ($rawAreas as $area) {
            if (
                !is_array($area)
                || !is_string($area['key'] ?? null)
                || !is_string($area['label'] ?? null)
                || !is_int($area['bytes'] ?? null)
                || !is_bool($area['failed'] ?? null)
            ) {
                return null;
            }
            $areas[] = [
                'key' => $area['key'],
                'label' => $area['label'],
                'bytes' => $area['bytes'],
                'failed' => $area['failed'],
            ];
        }

        return new self($areas, $totalBytes, (new \DateTimeImmutable())->setTimestamp($generatedAt));
    }
}
