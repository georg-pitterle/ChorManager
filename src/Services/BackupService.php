<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Files\FileBackupInterface;
use Psr\Log\LoggerInterface;

class BackupService
{
    public const TYPE_MANUAL = 'manual';
    public const TYPE_AUTO = 'auto';

    private const ID_PATTERN = '/^backup_(manual|auto)_\d{8}T\d{6}Z_[0-9a-f]{8}$/';

    public function __construct(
        private readonly DumpRunnerInterface $dumpRunner,
        private readonly LoggerInterface $logger,
        private readonly string $backupDir,
        private readonly int $maxManual,
        private readonly int $maxAuto,
        private readonly bool $gzip,
        private readonly string $dbDatabase,
        private readonly string $appVersion,
        private readonly ?string $mailKeyId = null,
        private readonly SessionInvalidationService $sessionInvalidation = new SessionInvalidationService(),
        /**
         * Quelle der Zeitstempel, die Kennung und Reihenfolge eines Backups bestimmen.
         * Beides hat Sekundenauflösung; im Test musste deshalb zwischen zwei Backups
         * eine ganze Sekunde vergehen, damit sie sich unterscheiden. Sechs solche
         * Wartezeiten kosteten den Testlauf knapp sieben Sekunden.
         */
        private readonly ?\Closure $clock = null,
        /**
         * Dateien der Dateiverwaltung. Sie liegen nicht in der Datenbank und
         * wären ohne diesen Teil nach einem Restore verloren. Ohne Angabe
         * sichert der Dienst wie bisher nur die Datenbank.
         */
        private readonly ?FileBackupInterface $fileBackup = null
    ) {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0750, true);
        }
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(): array
    {
        $entries = [];

        foreach (glob($this->backupDir . '/*.json') ?: [] as $metaPath) {
            if (str_ends_with($metaPath, '.files.json')) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($metaPath), true);
            if (!is_array($decoded) || !isset($decoded['id'], $decoded['type'], $decoded['created_at'])) {
                continue;
            }

            // Die Kennung stammt aus dem Dateinamen, nicht aus dem Inhalt der Datei.
            // Weichen beide voneinander ab, ist der Eintrag nicht stimmig - sein Verweis
            // zeigte auf Daten, die nicht zu ihm gehören.
            $id = basename($metaPath, '.json');
            if ((string) $decoded['id'] !== $id) {
                continue;
            }

            $dataPath = $this->resolveDataPath($id, $decoded);
            if ($dataPath === null || !file_exists($dataPath)) {
                continue;
            }

            $entries[] = $decoded;
        }

        usort($entries, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $entries;
    }

    /**
     * @return array<string,mixed>
     */
    public function create(string $type, ?int $userId): array
    {
        if (!in_array($type, [self::TYPE_MANUAL, self::TYPE_AUTO], true)) {
            throw new \InvalidArgumentException('type must be "manual" or "auto"');
        }

        $existingOfType = array_values(array_filter(
            $this->list(),
            static fn(array $entry): bool => $entry['type'] === $type
        ));

        if ($type === self::TYPE_MANUAL && $this->maxManual > 0 && count($existingOfType) >= $this->maxManual) {
            throw new BackupLimitReachedException(
                'Maximale Anzahl manueller Backups erreicht (' . $this->maxManual . ').'
            );
        }

        // Bis unter die Grenze rotieren, nicht nur ein Backup je Lauf: Wurde die
        // Obergrenze nachträglich gesenkt, liegen mehrere Backups zu viel herum
        // und der Ordner bliebe sonst dauerhaft darüber.
        if ($type === self::TYPE_AUTO && $this->maxAuto > 0) {
            while (count($existingOfType) >= $this->maxAuto) {
                $oldest = array_pop($existingOfType);
                if (!is_array($oldest)) {
                    break;
                }

                $this->delete((string) $oldest['id']);
                $this->logger->info('Oldest automatic backup rotated out.', [
                    'event' => 'backup.rotate',
                    'id' => $oldest['id'],
                ]);
            }
        }

        // Kennung und Zeitstempel stammen aus demselben Augenblick, sonst kann ein
        // Backup über einen Sekundenwechsel hinweg zwei verschiedene Zeiten tragen.
        $now = $this->now();
        $base = sprintf('backup_%s_%s_%s', $type, gmdate('Ymd\THis\Z', $now), bin2hex(random_bytes(4)));
        $dataPath = $this->backupDir . '/' . $base . ($this->gzip ? '.sql.gz' : '.sql');
        $metaPath = $this->backupDir . '/' . $base . '.json';

        $this->logger->debug('Starting backup creation.', ['event' => 'backup.create.start', 'type' => $type]);

        $fileStats = null;
        try {
            $preparedFiles = $this->fileBackup?->prepare($this->backupDir) ?? [];
            $this->dumpRunner->dump($dataPath, $this->gzip);
            $fileStats = $this->fileBackup?->finalize($this->backupDir, $base, $preparedFiles);
        } catch (\Throwable $exception) {
            if (file_exists($dataPath)) {
                unlink($dataPath);
            }
            // Ein halbes Backup ist schlimmer als keins: ohne passendes Manifest
            // fehlten beim Restore die Dateien, ohne es zu merken.
            if ($this->fileBackup !== null) {
                $this->fileBackup->forget($this->backupDir, $base);
            }
            $this->logger->error('Backup creation failed.', [
                'event' => 'backup.create.failed',
                'type' => $type,
                'exception' => $exception,
            ]);
            throw $exception;
        }

        $metadata = [
            'id' => $base,
            'type' => $type,
            'created_at' => gmdate('c', $now),
            'created_by' => $userId,
            'size' => filesize($dataPath),
            'sha256' => hash_file('sha256', $dataPath),
            'app_version' => $this->appVersion,
            'db_name' => $this->dbDatabase,
            'mail_key_id' => $this->mailKeyId,
            'gzip' => $this->gzip,
        ];
        if ($fileStats !== null) {
            $metadata['files_count'] = $fileStats['count'];
            $metadata['files_bytes'] = $fileStats['bytes'];
            $metadata['files_missing'] = $fileStats['missing'];
        }

        file_put_contents($metaPath, (string) json_encode($metadata, JSON_PRETTY_PRINT));

        $this->logger->info('Backup created.', [
            'event' => 'backup.create.completed',
            'type' => $type,
            'id' => $base,
            'size' => $metadata['size'],
        ]);

        return $metadata;
    }

    public function delete(string $id): void
    {
        $this->assertValidId($id);

        $metaPath = $this->backupDir . '/' . $id . '.json';
        if (!file_exists($metaPath)) {
            throw new \RuntimeException('Backup not found: ' . $id);
        }

        $metadata = json_decode((string) file_get_contents($metaPath), true);
        $dataPath = is_array($metadata) ? $this->resolveDataPath($id, $metadata) : null;

        if ($dataPath !== null && file_exists($dataPath)) {
            unlink($dataPath);
        }
        unlink($metaPath);
        $this->fileBackup?->forget($this->backupDir, $id);

        $this->logger->info('Backup deleted.', ['event' => 'backup.delete', 'id' => $id]);
    }

    /**
     * Die gesicherten Dateien eines Backups als .tar.gz (Temp-Datei, der
     * Aufrufer löscht sie nach dem Ausliefern), oder null ohne Dateisicherung.
     *
     * @return array{path:string,filename:string,size:int}|null
     */
    public function getFilesArchive(string $id): ?array
    {
        $this->assertValidId($id);
        if (!file_exists($this->backupDir . '/' . $id . '.json')) {
            throw new \RuntimeException('Backup not found: ' . $id);
        }

        $path = $this->fileBackup?->archive($this->backupDir, $id);
        if ($path === null) {
            return null;
        }

        return [
            'path' => $path,
            'filename' => $id . '_files.tar.gz',
            'size' => (int) filesize($path),
        ];
    }

    /**
     * @return array{path:string,filename:string,size:int}
     */
    public function getFile(string $id): array
    {
        $this->assertValidId($id);

        $metaPath = $this->backupDir . '/' . $id . '.json';
        if (!file_exists($metaPath)) {
            throw new \RuntimeException('Backup not found: ' . $id);
        }

        $metadata = json_decode((string) file_get_contents($metaPath), true);
        $dataPath = is_array($metadata) ? $this->resolveDataPath($id, $metadata) : null;

        if ($dataPath === null || !file_exists($dataPath)) {
            throw new \RuntimeException('Backup data file missing: ' . $id);
        }

        return [
            'path' => $dataPath,
            'filename' => basename($dataPath),
            'size' => (int) ($metadata['size'] ?? filesize($dataPath)),
        ];
    }

    public function restore(string $id): void
    {
        $this->assertValidId($id);

        $metaPath = $this->backupDir . '/' . $id . '.json';
        if (!file_exists($metaPath)) {
            throw new \RuntimeException('Backup not found: ' . $id);
        }

        $metadata = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($metadata)) {
            throw new \RuntimeException('Backup metadata is corrupt: ' . $id);
        }

        $dataPath = $this->resolveDataPath($id, $metadata);
        if ($dataPath === null || !file_exists($dataPath)) {
            throw new \RuntimeException('Backup data file missing: ' . $id);
        }

        $actualHash = hash_file('sha256', $dataPath);
        if (!isset($metadata['sha256']) || !hash_equals((string) $metadata['sha256'], (string) $actualHash)) {
            $this->logger->error('Backup restore aborted due to checksum mismatch.', [
                'event' => 'backup.restore.failed',
                'id' => $id,
                'reason' => 'checksum_mismatch',
            ]);
            throw new \RuntimeException('Backup file integrity check failed: ' . $id);
        }

        // Die Dateien werden vor dem Einspielen geprüft, nicht danach: Stellt sich
        // erst nach dem Restore heraus, dass Dateien fehlen, zeigt die Datenbank
        // schon auf sie.
        $this->fileBackup?->verify($this->backupDir, $id);

        $this->logger->info('Starting backup restore.', ['event' => 'backup.restore.start', 'id' => $id]);

        try {
            $this->dumpRunner->restore($dataPath, (bool) ($metadata['gzip'] ?? true));
            $this->fileBackup?->restore($this->backupDir, $id);
        } catch (\Throwable $exception) {
            $this->logger->error('Backup restore failed.', [
                'event' => 'backup.restore.failed',
                'id' => $id,
                'exception' => $exception,
            ]);
            throw $exception;
        }

        // Der eingespielte Stand kennt die aktuellen Anmeldungen nicht mehr, deshalb
        // werden sie hier vollständig entwertet - Sitzungen und "Angemeldet bleiben"
        // gemeinsam, siehe SessionInvalidationService.
        $this->sessionInvalidation->invalidateAllLogins();

        $this->logger->info('Backup restore completed.', ['event' => 'backup.restore.completed', 'id' => $id]);
    }

    /**
     * Pfad der Datendatei eines Backups.
     *
     * Den Namen bestimmt ausschließlich die geprüfte Kennung, nie das Feld `id`
     * innerhalb der Metadaten-JSON. Von dort stammte er vorher, und ein `../` darin ließ
     * den Pfad aus dem Backup-Verzeichnis herausführen - `delete()` entfernte die Datei
     * dann dort, wo der Verweis hinzeigte. Nur die Endung darf aus den Metadaten kommen:
     * sie entscheidet nichts über den Ort.
     *
     * @param array<string,mixed> $metadata
     */
    private function resolveDataPath(string $id, array $metadata): ?string
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            return null;
        }

        $extension = ($metadata['gzip'] ?? true) ? '.sql.gz' : '.sql';

        return $this->backupDir . '/' . $id . $extension;
    }

    private function assertValidId(string $id): void
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new \InvalidArgumentException('Invalid backup id: ' . $id);
        }
    }
}
