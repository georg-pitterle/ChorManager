<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Größe eines Verzeichnisbaums. Symbolische Links werden nicht verfolgt - sie
 * könnten auf Daten außerhalb zeigen oder einen Kreis bilden. Ausgeschlossene
 * Verzeichnisse (reale Pfade) misst ein anderer Bereich; sie fallen in jeder Tiefe
 * heraus, damit nichts doppelt zählt.
 */
final class DirectorySize
{
    /**
     * @param list<string> $excludedRealPaths
     * @return array{bytes: int, count: int}
     */
    public static function measure(string $path, array $excludedRealPaths = []): array
    {
        if (is_link($path)) {
            return ['bytes' => 0, 'count' => 0];
        }
        if (is_file($path)) {
            return ['bytes' => (int) filesize($path), 'count' => 1];
        }
        $real = realpath($path);
        if (!is_dir($path) || $real === false || in_array($real, $excludedRealPaths, true)) {
            return ['bytes' => 0, 'count' => 0];
        }

        $filter = static function (\SplFileInfo $item) use ($excludedRealPaths): bool {
            if ($item->isLink()) {
                return false;
            }
            if ($item->isDir()) {
                return !in_array($item->getRealPath(), $excludedRealPaths, true);
            }

            return true;
        };

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                $filter
            )
        );

        $bytes = 0;
        $count = 0;
        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $bytes += (int) $item->getSize();
                $count++;
            }
        }

        return ['bytes' => $bytes, 'count' => $count];
    }
}
