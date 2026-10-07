<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Alles Übrige unter var/: Zwischenspeicher, Importe, Anmeldebegrenzung. Die
 * Verzeichnisse von Dateiablage und Backups misst je ein eigener Bereich; sie
 * fallen hier heraus, egal ob sie direkt unter var/, tiefer oder ganz woanders
 * liegen.
 */
final class VarDirectoryUsageProvider implements StorageUsageProvider
{
    private const LABELS = [
        'cache' => 'Zwischenspeicher',
        'import' => 'Import',
        'bank_statement_import' => 'Kontoauszug-Import',
        'htmlpurifier' => 'HTML-Filter-Cache',
        'rate-limits' => 'Anmeldebegrenzung',
    ];

    /**
     * @param list<string> $excludedPaths
     */
    public function __construct(
        private readonly string $varDir,
        private readonly array $excludedPaths
    ) {
    }

    public function key(): string
    {
        return 'var';
    }

    public function label(): string
    {
        return 'Sonstiges';
    }

    public function usage(): StorageUsageNode
    {
        if (!is_dir($this->varDir)) {
            return StorageUsageNode::sum($this->key(), $this->label(), []);
        }

        $excluded = [];
        foreach ($this->excludedPaths as $path) {
            $real = realpath($path);
            if ($real !== false) {
                $excluded[] = $real;
            }
        }

        $children = [];
        $loose = ['bytes' => 0, 'count' => 0];
        foreach (new \FilesystemIterator($this->varDir) as $item) {
            if (!$item instanceof \SplFileInfo || $item->isLink()) {
                continue;
            }
            $name = $item->getFilename();

            if ($item->isDir()) {
                if (in_array($item->getRealPath(), $excluded, true)) {
                    continue;
                }
                $measured = DirectorySize::measure($item->getPathname(), $excluded);
                $children[] = new StorageUsageNode(
                    'var.' . $name,
                    self::LABELS[$name] ?? $name,
                    $measured['bytes'],
                    $measured['count']
                );
                continue;
            }

            $loose['bytes'] += (int) $item->getSize();
            $loose['count']++;
        }

        usort($children, static fn (StorageUsageNode $a, StorageUsageNode $b): int => $b->bytes <=> $a->bytes);
        if ($loose['count'] > 0) {
            $children[] = new StorageUsageNode('var.loose', 'Einzelne Dateien', $loose['bytes'], $loose['count']);
        }

        return StorageUsageNode::sum($this->key(), $this->label(), $children);
    }
}
