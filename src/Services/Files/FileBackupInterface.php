<?php

declare(strict_types=1);

namespace App\Services\Files;

/**
 * Sicherung der Dateien aus der Dateiverwaltung, eingehängt in BackupService.
 *
 * Gespeicherte Dateien sind unveränderlich, darum reicht ein gemeinsamer Pool
 * neben den Dumps: jede Datei liegt dort einmal, egal wie viele Backups sie
 * brauchen. Jedes Backup führt ein Manifest mit Pfad und Prüfsumme.
 */
interface FileBackupInterface
{
    /**
     * Vor dem Dump: alle vorhandenen Dateien in den Pool bringen.
     *
     * @return array<string, array{storage_driver: string, storage_path: string, size: int, sha256: string}>
     */
    public function prepare(string $backupDir): array;

    /**
     * Nach dem Dump: Nachzügler kopieren und das Manifest schreiben. Ohne
     * gesicherte Dateien entsteht kein Manifest, und das Ergebnis ist null.
     *
     * @param array<string, array{storage_driver: string, storage_path: string, size: int, sha256: string}> $prepared
     * @return array{count: int, bytes: int, missing: int}|null
     */
    public function finalize(string $backupDir, string $backupId, array $prepared): ?array;

    /** Wirft, wenn eine Datei des Manifests im Pool fehlt oder verändert ist. */
    public function verify(string $backupDir, string $backupId): void;

    /** Fehlende Dateien aus dem Pool in die Ablage zurückschreiben. */
    public function restore(string $backupDir, string $backupId): int;

    /** Manifest entfernen und Pool-Dateien löschen, die kein Backup mehr braucht. */
    public function forget(string $backupDir, string $backupId): void;

    /**
     * Manifest und Dateien als .tar.gz in einer Temp-Datei, oder null ohne Manifest.
     */
    public function archive(string $backupDir, string $backupId): ?string;
}
