<?php

declare(strict_types=1);

namespace App\Services\Files;

/**
 * Wählt den Treiber nach `file_versions.storage_driver`. Ein unbekannter Treiber
 * wirft, statt still auf die Platte zurückzufallen - sonst würde eine Datei als
 * fehlend gemeldet, die in Wahrheit nur woanders liegt.
 */
final class FileStorageRegistry
{
    /** @var array<string, FileStorage> */
    private array $drivers = [];

    public function __construct(private readonly FileStorage $default, FileStorage ...$others)
    {
        foreach ([$default, ...$others] as $driver) {
            $this->drivers[$driver->name()] = $driver;
        }
    }

    public function default(): FileStorage
    {
        return $this->default;
    }

    public function for(string $driverName): FileStorage
    {
        if (!isset($this->drivers[$driverName])) {
            throw new \RuntimeException('Unknown file storage driver: ' . $driverName);
        }

        return $this->drivers[$driverName];
    }
}
