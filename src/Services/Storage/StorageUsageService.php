<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Psr\Log\LoggerInterface;

/**
 * Sammelt die Bereiche der Speicherübersicht. Ein Bereich, der wirft, wird hier an
 * einer Stelle abgefangen und als "nicht ermittelbar" eingesetzt - die Seite bleibt
 * benutzbar, die übrigen Zahlen stimmen.
 *
 * Jede fehlerfreie Messung schreibt eine Kurzfassung in eine Cache-Datei. Die
 * Dashboard-Kachel liest nur sie (höchstens eine Stunde alt); die Seite /storage
 * misst immer neu.
 */
final class StorageUsageService
{
    private const SUMMARY_MAX_AGE_SECONDS = 3600;

    /**
     * @param list<StorageUsageProvider> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly string $summaryCachePath,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $clock = null
    ) {
    }

    public function collect(): StorageUsageReport
    {
        $areas = [];
        foreach ($this->providers as $provider) {
            try {
                $areas[] = $provider->usage();
            } catch (\Throwable $e) {
                $this->logger->error('Storage usage could not be determined.', [
                    'event' => 'storage.usage_failed',
                    'provider' => $provider->key(),
                    'exception' => $e,
                ]);
                $areas[] = StorageUsageNode::failed($provider->key(), $provider->label());
            }
        }

        $report = new StorageUsageReport($areas, (new \DateTimeImmutable())->setTimestamp($this->now()));

        // Eine Messung mit Fehler bleibt aus dem Cache - sonst hielte ein kurzer
        // Aussetzer die Kachel eine Stunde lang auf "nicht ermittelbar".
        $failed = array_filter($areas, static fn (StorageUsageNode $area): bool => $area->error !== null);
        if ($failed === []) {
            $this->writeSummary(StorageUsageSummary::fromReport($report));
        }

        return $report;
    }

    public function summary(): StorageUsageSummary
    {
        $cached = $this->readSummary();
        if ($cached !== null) {
            // Ein negatives Alter heißt verstellte Uhr - dann lieber neu messen, als
            // einen Stand unbegrenzt lange für frisch zu halten.
            $age = $this->now() - $cached->generatedAt->getTimestamp();
            if ($age >= 0 && $age < self::SUMMARY_MAX_AGE_SECONDS) {
                return $cached;
            }
        }

        return StorageUsageSummary::fromReport($this->collect());
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }

    private function readSummary(): ?StorageUsageSummary
    {
        if (!is_file($this->summaryCachePath)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($this->summaryCachePath), true);

        return is_array($decoded) ? StorageUsageSummary::fromArray($decoded) : null;
    }

    /**
     * Erst in eine Nachbardatei, dann umbenennen: Ein gleichzeitiger Leser sieht nie
     * eine halb geschriebene Datei.
     */
    private function writeSummary(StorageUsageSummary $summary): void
    {
        $directory = dirname($this->summaryCachePath);
        $temporary = $this->summaryCachePath . '.' . bin2hex(random_bytes(4)) . '.tmp';

        $written = (is_dir($directory) || @mkdir($directory, 0750, true))
            && @file_put_contents($temporary, (string) json_encode($summary->toArray())) !== false
            && @rename($temporary, $this->summaryCachePath);

        if (!$written) {
            @unlink($temporary);
            $this->logger->warning('Storage usage summary could not be cached.', [
                'event' => 'storage.summary_cache_write_failed',
                'path' => $this->summaryCachePath,
            ]);
        }
    }
}
