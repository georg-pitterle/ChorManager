<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Die Sperre, die sich alle Backup-Vorgänge teilen: Erstellen, Löschen und das
 * Übernehmen eines Pakets.
 *
 * Nötig ist sie wegen des gemeinsamen Datei-Pools. Jeder Vorgang legt Dateien
 * hinein oder räumt sie weg, und das Manifest, das sie festhält, entsteht immer
 * erst danach. Bricht ein Vorgang ab, räumt er seine Pool-Dateien auf - und trifft
 * damit genau die Dateien, die ein gleichzeitig laufender gerade in sein eigenes
 * Manifest schreibt. Übrig bliebe ein Backup, dem Dateien fehlen, und bemerkt
 * würde das erst beim Restore. Die Einschränkung auf das eigene Manifest in
 * FileBackupService::forget() hilft dagegen nicht: Beide Backups sichern dieselbe
 * Installation, ihre Manifeste enthalten darum dieselben Pfade.
 *
 * Gewartet wird nicht. Ein Backup dauert Minuten, und ein Web-Request, der so
 * lange auf eine Sperre wartet, läuft in sein Zeitlimit statt in eine Antwort.
 * Wer die Sperre nicht bekommt, wird mit BackupLockedException abgewiesen und
 * kann es gleich wieder versuchen.
 *
 * Verschachtelte Läufe zählen nur mit, statt sich selbst auszusperren:
 * `flock()` hängt an der geöffneten Datei, nicht am Prozess, und ein zweites
 * Öffnen derselben Datei im selben Prozess bekommt die Sperre nicht. Genau das
 * täte BackupService::create(), das beim Rotieren seinerseits delete() aufruft.
 */
final class BackupLock
{
    public const LOCK_FILE = '.backup.lock';

    /** @var resource|null */
    private $handle = null;

    private int $depth = 0;

    public function __construct(private readonly string $backupDir)
    {
    }

    /**
     * Führt die Arbeit unter der Sperre aus und gibt sie danach wieder frei -
     * auch wenn die Arbeit mit einer Ausnahme endet.
     *
     * @template TResult
     * @param \Closure(): TResult $work
     * @return TResult
     * @throws BackupLockedException wenn ein anderer Vorgang die Sperre hält
     */
    public function run(\Closure $work): mixed
    {
        $this->acquire();

        try {
            return $work();
        } finally {
            $this->release();
        }
    }

    private function acquire(): void
    {
        if ($this->depth > 0) {
            $this->depth++;

            return;
        }

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0750, true);
        }

        $handle = fopen($this->backupDir . '/' . self::LOCK_FILE, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle !== false) {
                fclose($handle);
            }

            throw new BackupLockedException('Another backup operation is running.');
        }

        $this->handle = $handle;
        $this->depth = 1;
    }

    private function release(): void
    {
        $this->depth--;
        if ($this->depth > 0 || $this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
