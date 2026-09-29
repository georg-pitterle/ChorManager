<?php

declare(strict_types=1);

namespace App\Services\Files;

use Illuminate\Database\Capsule\Manager as DB;
use Psr\Log\LoggerInterface;

/**
 * Inkrementelle Dateisicherung für BackupService.
 *
 * Pool: `<backupDir>/files/<storage_path>`, dasselbe Pfadschema wie die Ablage.
 * Manifest: `<backupDir>/<backupId>.files.json` mit Treiber, Pfad, Größe und
 * sha256 jeder gesicherten Datei.
 *
 * Reihenfolge beim Sichern: erst alles Vorhandene in den Pool (prepare), dann
 * der Dump, dann Nachzügler (finalize). Das Manifest ist die Vereinigung beider
 * Stände - so fehlt keine Datei, auf die der Dump zeigt, auch wenn zwischen
 * den Schritten hochgeladen oder gelöscht wurde.
 */
final class FileBackupService implements FileBackupInterface
{
    public function __construct(
        private readonly LocalFileStorage $storage,
        private readonly LoggerInterface $logger
    ) {
    }

    public function prepare(string $backupDir): array
    {
        return $this->copyAll($this->pool($backupDir), $this->currentEntries())['entries'];
    }

    public function finalize(string $backupDir, string $backupId, array $prepared): ?array
    {
        $result = $this->copyAll($this->pool($backupDir), $this->currentEntries());
        $entries = $prepared + $result['entries'];
        if ($entries === [] && $result['missing'] === 0) {
            return null;
        }

        ksort($entries);
        $manifest = [
            'version' => 1,
            'backup_id' => $backupId,
            'files' => array_values($entries),
        ];
        $path = $this->manifestPath($backupDir, $backupId);
        if (file_put_contents($path, (string) json_encode($manifest, JSON_PRETTY_PRINT)) === false) {
            throw new \RuntimeException('Could not write file manifest.');
        }

        return [
            'count' => count($entries),
            'bytes' => array_sum(array_column($entries, 'size')),
            'missing' => $result['missing'],
        ];
    }

    public function verify(string $backupDir, string $backupId): void
    {
        $pool = $this->pool($backupDir);
        foreach ($this->manifestEntries($backupDir, $backupId) as $entry) {
            $path = $entry['storage_path'];
            $local = $pool->exists($path) ? $pool->localPath($path) : null;
            if ($local === null || !hash_equals($entry['sha256'], (string) hash_file('sha256', $local))) {
                $this->logger->error('File backup is incomplete or damaged.', [
                    'event' => 'backup.files.verify_failed',
                    'backup_id' => $backupId,
                    'storage_path' => $path,
                ]);
                throw new \RuntimeException('File backup is incomplete or damaged: ' . $backupId);
            }
        }
    }

    public function restore(string $backupDir, string $backupId): int
    {
        $pool = $this->pool($backupDir);
        $restored = 0;
        foreach ($this->manifestEntries($backupDir, $backupId) as $entry) {
            $path = $entry['storage_path'];
            if ($this->storage->exists($path) && $this->storage->size($path) === $entry['size']) {
                continue;
            }
            $stream = $pool->readStream($path);
            try {
                $this->storage->writeAt($path, $stream);
            } finally {
                fclose($stream);
            }
            $restored++;
        }

        $this->logger->info('Stored files restored from backup.', [
            'event' => 'backup.files.restored',
            'backup_id' => $backupId,
            'count' => $restored,
        ]);

        return $restored;
    }

    public function forget(string $backupDir, string $backupId): void
    {
        $manifest = $this->manifestPath($backupDir, $backupId);
        if (!is_file($manifest)) {
            return;
        }

        // Nur Kandidaten aus dem Manifest, das hier wegfällt - nicht "alles, was
        // gerade kein Manifest hat". Ein parallel laufendes Backup hat in
        // prepare() schon Dateien in den Pool gelegt, sein Manifest entsteht
        // aber erst nach dem Dump; ein Rundumschlag würde ihm die Dateien
        // unter den Füßen wegziehen.
        $candidates = array_column($this->readManifest($manifest), 'storage_path');
        unlink($manifest);

        $referenced = [];
        foreach (glob($backupDir . '/*.files.json') ?: [] as $other) {
            foreach ($this->readManifest($other) as $entry) {
                $referenced[$entry['storage_path']] = true;
            }
        }

        $pool = $this->pool($backupDir);
        $removed = 0;
        foreach (array_unique($candidates) as $path) {
            if (!isset($referenced[$path]) && $pool->exists($path)) {
                $pool->delete($path);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->logger->info('Unreferenced files removed from backup pool.', [
                'event' => 'backup.files.pool_collected',
                'count' => $removed,
            ]);
        }
    }

    public function archive(string $backupDir, string $backupId): ?string
    {
        $manifest = $this->manifestPath($backupDir, $backupId);
        if (!is_file($manifest)) {
            return null;
        }

        $base = sys_get_temp_dir() . '/' . $backupId . '_files_' . bin2hex(random_bytes(4));
        $tar = new \PharData($base . '.tar');
        $tar->addFile($manifest, 'manifest.json');
        $pool = $this->pool($backupDir);
        foreach ($this->readManifest($manifest) as $entry) {
            $local = $pool->localPath($entry['storage_path']);
            if ($local !== null) {
                $tar->addFile($local, 'files/' . $entry['storage_path']);
            }
        }
        $tar->compress(\Phar::GZ);
        unset($tar);
        @unlink($base . '.tar');

        return $base . '.tar.gz';
    }

    public function hasManifest(string $backupDir, string $backupId): bool
    {
        return is_file($this->manifestPath($backupDir, $backupId));
    }

    /**
     * @param array<string, array{storage_driver: string, storage_path: string, size: int, sha256: string}> $entries
     * @return array{entries: array<string, array{storage_driver: string, storage_path: string, size: int,
     *                                            sha256: string}>, missing: int}
     */
    private function copyAll(LocalFileStorage $pool, array $entries): array
    {
        $copied = [];
        $missing = 0;
        foreach ($entries as $key => $entry) {
            $path = $entry['storage_path'];
            if ($pool->exists($path) && $pool->size($path) === $entry['size']) {
                $copied[$key] = $entry;
                continue;
            }
            if (!$this->storage->exists($path)) {
                // Eine einzelne verlorene Datei darf nicht jedes weitere Backup
                // verhindern. Sie fehlt im Manifest und steht im Protokoll.
                $missing++;
                $this->logger->warning('Stored file missing during backup.', [
                    'event' => 'backup.files.missing',
                    'storage_path' => $path,
                ]);
                continue;
            }

            $stream = $this->storage->readStream($path);
            try {
                $pool->writeAt($path, $stream);
            } finally {
                fclose($stream);
            }
            $copied[$key] = $entry;
        }

        return ['entries' => $copied, 'missing' => $missing];
    }

    /**
     * @return array<string, array{storage_driver: string, storage_path: string, size: int, sha256: string}>
     */
    private function currentEntries(): array
    {
        $rows = DB::table('file_versions')
            ->where('storage_driver', $this->storage->name())
            ->select('storage_driver', 'storage_path', 'size', 'sha256')
            ->distinct()
            ->get();

        $entries = [];
        foreach ($rows as $row) {
            $path = (string) $row->storage_path;
            if (!LocalFileStorage::isValidPath($path)) {
                continue;
            }
            $entries[$path] = [
                'storage_driver' => (string) $row->storage_driver,
                'storage_path' => $path,
                'size' => (int) $row->size,
                'sha256' => (string) $row->sha256,
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{storage_driver: string, storage_path: string, size: int, sha256: string}>
     */
    private function manifestEntries(string $backupDir, string $backupId): array
    {
        $path = $this->manifestPath($backupDir, $backupId);

        return is_file($path) ? $this->readManifest($path) : [];
    }

    /**
     * @return list<array{storage_driver: string, storage_path: string, size: int, sha256: string}>
     */
    private function readManifest(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['files'] ?? null)) {
            throw new \RuntimeException('File manifest is corrupt: ' . basename($path));
        }

        $entries = [];
        foreach ($decoded['files'] as $entry) {
            $storagePath = (string) ($entry['storage_path'] ?? '');
            if (!LocalFileStorage::isValidPath($storagePath)) {
                throw new \RuntimeException('File manifest contains an invalid path: ' . basename($path));
            }
            $entries[] = [
                'storage_driver' => (string) ($entry['storage_driver'] ?? LocalFileStorage::NAME),
                'storage_path' => $storagePath,
                'size' => (int) ($entry['size'] ?? 0),
                'sha256' => (string) ($entry['sha256'] ?? ''),
            ];
        }

        return $entries;
    }

    private function pool(string $backupDir): LocalFileStorage
    {
        return new LocalFileStorage(rtrim($backupDir, '/') . '/files');
    }

    private function manifestPath(string $backupDir, string $backupId): string
    {
        return rtrim($backupDir, '/') . '/' . $backupId . '.files.json';
    }
}
