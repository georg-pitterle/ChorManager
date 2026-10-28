<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Files\FileBackupInterface;
use App\Services\Files\LocalFileStorage;
use Psr\Log\LoggerInterface;

/**
 * Ein Backup als eine ZIP-Datei, zum Mitnehmen in eine andere Installation.
 *
 * Intern liegen die Dateien eines Backups im gemeinsamen Pool, damit jede nur
 * einmal Platz braucht. Das Paket packt genau die Dateien seines Manifests dazu,
 * zusammen mit Dump und Metadaten:
 *
 *   backup.json, manifest.json (fehlt bei "nur Datenbank"),
 *   database.sql.gz bzw. database.sql, files/<storage_path>
 *
 * Alle Einträge werden nur gespeichert, nicht komprimiert: Der Dump ist schon
 * gzip, und PDFs oder Audiodateien schrumpfen kaum. So kostet der Paketbau nicht
 * mehr als ein Kopieren.
 */
final class BackupPackageService
{
    private const PACKAGE_SUFFIX = '.zip';

    /** Ab wann ein Paket im Arbeitsverzeichnis als liegengeblieben gilt. */
    private const STALE_PACKAGE_SECONDS = 86400;
    private const FAILED_SUFFIX = '.zip.failed';
    private const FIXED_ENTRIES = ['backup.json', 'manifest.json', 'database.sql.gz', 'database.sql'];

    private const REASON_EXISTS = 'Kennung existiert bereits.';
    private const REASON_CORRUPT = 'Paket ist unvollständig oder beschädigt.';
    private const REASON_DUMP_CHECKSUM = 'Prüfsumme der Datenbank stimmt nicht.';
    private const REASON_FILES = 'Dateien des Pakets sind ungültig.';

    public function __construct(
        private readonly BackupService $backups,
        private readonly string $backupDir,
        private readonly ?FileBackupInterface $fileBackup,
        private readonly LoggerInterface $logger,
        /**
         * Dieselbe Sperre, die auch BackupService nimmt - sonst liefe eine
         * Übernahme gegen eine Backup-Erstellung, und beide greifen in den
         * gemeinsamen Datei-Pool. Verpflichtend, weil die Übernahme schon vor
         * der gemeinsamen Sperre nie ohne eine lief.
         */
        private readonly BackupLock $lock
    ) {
    }

    /**
     * Das Paket eines Backups als Temp-Datei; der Aufrufer löscht sie nach dem Ausliefern.
     *
     * @return array{path: string, filename: string, size: int}
     */
    public function build(string $id): array
    {
        $dump = $this->backups->getFile($id);
        $metaPath = $this->backupDir . '/' . $id . '.json';
        $contents = $this->fileBackup?->packageContents($this->backupDir, $id);

        $workDir = self::workDir();
        $this->sweepStalePackages($workDir);
        $path = $workDir . '/' . $id . '_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        try {
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Could not create backup package: ' . $id);
            }
            $this->addStored($zip, $metaPath, 'backup.json');
            $dumpName = str_ends_with($dump['path'], '.gz') ? 'database.sql.gz' : 'database.sql';
            $this->addStored($zip, $dump['path'], $dumpName);
            if ($contents !== null) {
                $zip->addFromString('manifest.json', $contents['manifest']);
                $zip->setCompressionName('manifest.json', \ZipArchive::CM_STORE);
                foreach ($contents['files'] as $storagePath => $local) {
                    $this->addStored($zip, $local, 'files/' . $storagePath);
                }
            }
            if (!$zip->close()) {
                throw new \RuntimeException('Could not write backup package: ' . $id);
            }
        } catch (\Throwable $exception) {
            try {
                $zip->close();
            } catch (\Throwable) {
                // Schon geschlossen oder nie geöffnet.
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        }

        $this->logger->info('Backup package built.', ['event' => 'backup.package.built', 'id' => $id]);

        return ['path' => $path, 'filename' => $id . '.zip', 'size' => (int) filesize($path)];
    }

    /**
     * Pakete im Backup-Ordner, nur nach Dateinamen; kein ZIP wird geöffnet.
     *
     * @return array{waiting: list<string>, failed: list<string>}
     */
    public function pendingPackages(): array
    {
        return [
            'waiting' => $this->packageNames(self::PACKAGE_SUFFIX),
            'failed' => $this->packageNames(self::FAILED_SUFFIX),
        ];
    }

    /**
     * Alle wartenden Pakete übernehmen. Ergebnis je Kennung: null bei Erfolg,
     * sonst die Ursache. Läuft nur über den Konsolenbefehl - nie aus der Liste
     * heraus, die auch der Dev-Reset und der nächtliche Lauf benutzen.
     *
     * @return array<string, string|null>
     */
    public function importAll(): array
    {
        $work = function (): array {
            $results = [];
            foreach ($this->packageNames(self::PACKAGE_SUFFIX) as $name) {
                $id = substr($name, 0, -strlen(self::PACKAGE_SUFFIX));
                $results[$id] = $this->importOne($id);
            }

            return $results;
        };

        return $this->lock->run($work);
    }

    private function importOne(string $id): ?string
    {
        $zipPath = $this->backupDir . '/' . $id . self::PACKAGE_SUFFIX;
        /** @var list<string> $written */
        $written = [];
        /** @var list<string>|null $adoptedPoolPaths */
        $adoptedPoolPaths = null;

        try {
            $this->adopt($id, $zipPath, $written, $adoptedPoolPaths);
        } catch (\Throwable $exception) {
            foreach ($written as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            // Nur die Pool-Dateien, die diese Übernahme selbst geschrieben hat: Was
            // vorher schon im Pool lag, gehört womöglich einem Backup, dessen
            // Manifest gerade erst entsteht.
            if ($adoptedPoolPaths !== null) {
                $this->fileBackup?->forget($this->backupDir, $id, $adoptedPoolPaths);
            }
            // Umbenannt statt gelöscht: Das Paket bleibt zur Untersuchung liegen,
            // wird aber nicht bei jedem Lauf erneut versucht.
            rename($zipPath, $zipPath . '.failed');
            $this->logger->error('Backup package import failed.', [
                'event' => 'backup.import.failed',
                'id' => $id,
                'exception' => $exception,
            ]);

            return $exception instanceof BackupPackageRejectedException
                ? $exception->getMessage()
                : self::REASON_CORRUPT;
        }

        unlink($zipPath);
        $this->logger->info('Backup package imported.', ['event' => 'backup.import.completed', 'id' => $id]);

        return null;
    }

    /**
     * Prüft das Paket und legt Dump, Pool-Dateien, Manifest und zuletzt die
     * Metadaten ab. Erst die Metadaten machen das Backup für list() sichtbar.
     *
     * @param list<string>      $written          Pfade, die dieser Lauf angelegt hat
     * @param list<string>|null $adoptedPoolPaths neu in den Pool geschriebene Pfade
     */
    private function adopt(string $id, string $zipPath, array &$written, ?array &$adoptedPoolPaths): void
    {
        $metaPath = $this->backupDir . '/' . $id . '.json';
        if (is_file($metaPath)) {
            throw new BackupPackageRejectedException(self::REASON_EXISTS);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new BackupPackageRejectedException(self::REASON_CORRUPT);
        }

        try {
            $packageFiles = $this->checkEntries($zip);
            $metaJson = (string) $zip->getFromName('backup.json');
            $meta = json_decode($metaJson, true);
            $valid = is_array($meta)
                && ($meta['id'] ?? null) === $id
                && in_array($meta['type'] ?? null, [BackupService::TYPE_MANUAL, BackupService::TYPE_AUTO], true)
                && is_string($meta['created_at'] ?? null)
                && is_string($meta['sha256'] ?? null);
            if (!$valid) {
                throw new BackupPackageRejectedException(self::REASON_CORRUPT);
            }

            $gzip = (bool) ($meta['gzip'] ?? true);
            $dumpEntry = $gzip ? 'database.sql.gz' : 'database.sql';
            $dataPath = $this->backupDir . '/' . $id . ($gzip ? '.sql.gz' : '.sql');
            if ($zip->locateName($dumpEntry) === false) {
                throw new BackupPackageRejectedException(self::REASON_CORRUPT);
            }
            if (file_exists($dataPath)) {
                throw new BackupPackageRejectedException(self::REASON_EXISTS);
            }
            $this->extract($zip, $dumpEntry, $dataPath, $written);
            if (!hash_equals($meta['sha256'], (string) hash_file('sha256', $dataPath))) {
                throw new BackupPackageRejectedException(self::REASON_DUMP_CHECKSUM);
            }

            $manifest = $zip->getFromName('manifest.json');
            if ($manifest === false) {
                if ($packageFiles !== []) {
                    throw new BackupPackageRejectedException(self::REASON_CORRUPT);
                }
            } else {
                $manifestPaths = array_column(
                    (array) (json_decode($manifest, true)['files'] ?? []),
                    'storage_path'
                );
                if ($this->fileBackup === null || array_diff(array_keys($packageFiles), $manifestPaths) !== []) {
                    // Ohne Dateisicherung kein Ziel, und Dateien im Paket, die das
                    // Manifest nicht kennt, passen nicht zu diesem Backup.
                    throw new BackupPackageRejectedException(self::REASON_CORRUPT);
                }
                try {
                    $adoptedPoolPaths = $this->fileBackup->importFiles(
                        $this->backupDir,
                        $id,
                        $manifest,
                        static fn (string $path) => isset($packageFiles[$path]) ? $zip->getStream('files/' . $path) : false
                    );
                } catch (\Throwable $exception) {
                    throw new BackupPackageRejectedException(self::REASON_FILES, 0, $exception);
                }
            }

            // Vor dem Schreiben eingetragen: Eine halb geschriebene Datei bei voller
            // Platte blockierte sonst jede spätere Übernahme mit dieser Kennung.
            $written[] = $metaPath;
            if (@file_put_contents($metaPath, $metaJson) === false) {
                throw new \RuntimeException('Could not write backup metadata: ' . $id);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Nur die bekannten Einträge sind erlaubt; Pfade unter files/ müssen gültige
     * Ablagepfade sein. Ausgepackt wird ausschließlich unter selbst gebildeten
     * Namen, nie unter dem Namen aus dem ZIP.
     *
     * @return array<string, true> storage_path der Dateien im Paket
     */
    private function checkEntries(\ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (in_array($name, self::FIXED_ENTRIES, true)) {
                continue;
            }
            $storagePath = str_starts_with($name, 'files/') ? substr($name, 6) : '';
            if (!LocalFileStorage::isValidPath($storagePath)) {
                throw new BackupPackageRejectedException(self::REASON_CORRUPT);
            }
            $files[$storagePath] = true;
        }
        if ($zip->locateName('backup.json') === false) {
            throw new BackupPackageRejectedException(self::REASON_CORRUPT);
        }

        return $files;
    }

    /** @param list<string> $written */
    private function extract(\ZipArchive $zip, string $entry, string $target, array &$written): void
    {
        $in = $zip->getStream($entry);
        if ($in === false) {
            throw new BackupPackageRejectedException(self::REASON_CORRUPT);
        }
        $written[] = $target;
        $out = fopen($target, 'wb');
        try {
            if ($out === false || stream_copy_to_stream($in, $out) === false) {
                throw new \RuntimeException('Could not extract ' . $entry);
            }
        } finally {
            fclose($in);
            if ($out !== false) {
                fclose($out);
            }
        }
    }

    /** @return list<string> */
    private function packageNames(string $suffix): array
    {
        $names = [];
        foreach (glob($this->backupDir . '/backup_*' . $suffix) ?: [] as $path) {
            $name = basename($path);
            if (preg_match(BackupService::ID_PATTERN, substr($name, 0, -strlen($suffix))) === 1) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    /**
     * Wo das Paket entsteht: `var/tmp` im Projekt.
     *
     * Es wiegt so viel wie Dump und Dateiverwaltung zusammen - das Größte, was
     * diese Anwendung schreibt. In sys_get_temp_dir() lag es an derselben Stelle,
     * die der HTMLPurifier-Zwischenspeicher und die Rate-Limit-Zähler aus guten
     * Gründen verlassen haben (HtmlSanitizer::cacheDir(),
     * RateLimiterService::defaultStoreDir()): Das Temp-Verzeichnis gehört
     * niemandem, liegt je nach Aufruf woanders, wird von aufräumenden
     * Systemdiensten geleert - und ist es ein tmpfs, liegt das ganze Paket im
     * Arbeitsspeicher. `var/` gehört zur Anwendung und steht schon in
     * `.gitignore`.
     *
     * Lässt sich das Verzeichnis nicht anlegen oder nicht beschreiben, bleibt es
     * beim Temp-Verzeichnis: Ein Download, der daran scheitert, wäre schlechter
     * als einer vom alten Platz.
     */
    private static function workDir(): string
    {
        $workDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($workDir) && !@mkdir($workDir, 0750, true) && !is_dir($workDir)) {
            return sys_get_temp_dir();
        }

        return is_writable($workDir) ? $workDir : sys_get_temp_dir();
    }

    /**
     * Liegengebliebene Pakete aus früheren Downloads wegräumen.
     *
     * Der Controller entfernt das Paket, sobald er es geöffnet hat; bricht der
     * Request vorher ab, bleibt es liegen. In sys_get_temp_dir() nahm das der
     * Systemdienst mit, in var/ nimmt es niemand mit - und ein Paket wiegt so viel
     * wie ein ganzes Backup. Angefasst wird nur, was dieser Dienst selbst gebaut
     * haben kann, und nur, wenn es einen Tag alt ist: Ein frisches Paket kann der
     * Download sein, der gerade läuft.
     */
    private function sweepStalePackages(string $workDir): void
    {
        $deadline = time() - self::STALE_PACKAGE_SECONDS;
        foreach (glob($workDir . '/backup_*_*' . self::PACKAGE_SUFFIX) ?: [] as $path) {
            if (!is_file($path) || filemtime($path) > $deadline) {
                continue;
            }
            if (@unlink($path)) {
                $this->logger->info('Stale backup package removed.', [
                    'event' => 'backup.package.swept',
                    'name' => basename($path),
                ]);
            }
        }
    }

    private function addStored(\ZipArchive $zip, string $source, string $name): void
    {
        if (!$zip->addFile($source, $name)) {
            throw new \RuntimeException('Could not add to backup package: ' . $name);
        }
        $zip->setCompressionName($name, \ZipArchive::CM_STORE);
    }
}
