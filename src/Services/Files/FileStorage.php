<?php

declare(strict_types=1);

namespace App\Services\Files;

/**
 * Ablage der Dateiverwaltung. Ein Treiber kennt nur Pfade, keine Rechte - die
 * prüft FileAccessService, bevor überhaupt ein Pfad hierher gelangt.
 *
 * Gespeicherte Inhalte sind unveränderlich: `put()` vergibt immer einen neuen
 * Pfad. Darauf verlässt sich die inkrementelle Dateisicherung im Backup.
 * Die Schnittstelle folgt bewusst docs/attachment-storage-backends.md, damit
 * ein späterer WebDAV-Treiber hineinpasst.
 */
interface FileStorage
{
    /** Kennung, die in `file_versions.storage_driver` steht. */
    public function name(): string;

    /**
     * Kopiert die Datei unter einen neuen, zufälligen Pfad und liefert ihn.
     * Die Quelle bleibt liegen.
     */
    public function put(string $sourcePath): string;

    /**
     * Schreibt Inhalt unter einen vorgegebenen Pfad. Nur für die Wiederherstellung
     * aus dem Backup, wo der Pfad aus der Datenbank feststeht.
     *
     * @param resource $stream
     */
    public function writeAt(string $storagePath, $stream): void;

    public function read(string $storagePath): string;

    /**
     * @return resource
     */
    public function readStream(string $storagePath);

    public function readRange(string $storagePath, int $start, int $length): string;

    public function size(string $storagePath): int;

    public function exists(string $storagePath): bool;

    /**
     * Pfad im lokalen Dateisystem, falls der Treiber einen hat - erlaubt ZIP und
     * Backup, große Dateien zu übernehmen, ohne sie in den Speicher zu laden.
     */
    public function localPath(string $storagePath): ?string;

    /** Idempotent: ein schon fehlender Pfad ist kein Fehler. */
    public function delete(string $storagePath): void;
}
