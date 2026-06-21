<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\RememberLogin;
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
        private readonly string $appVersion
    ) {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0750, true);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(): array
    {
        $entries = [];

        foreach (glob($this->backupDir . '/*.json') ?: [] as $metaPath) {
            $decoded = json_decode((string) file_get_contents($metaPath), true);
            if (!is_array($decoded) || !isset($decoded['id'], $decoded['type'], $decoded['created_at'])) {
                continue;
            }

            $dataPath = $this->resolveDataPath($decoded);
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

        if ($type === self::TYPE_AUTO && $this->maxAuto > 0 && count($existingOfType) >= $this->maxAuto) {
            $oldest = end($existingOfType);
            if ($oldest !== false) {
                $this->delete((string) $oldest['id']);
                $this->logger->info('Oldest automatic backup rotated out.', [
                    'event' => 'backup.rotate',
                    'id' => $oldest['id'],
                ]);
            }
        }

        $base = sprintf('backup_%s_%s_%s', $type, gmdate('Ymd\THis\Z'), bin2hex(random_bytes(4)));
        $dataPath = $this->backupDir . '/' . $base . ($this->gzip ? '.sql.gz' : '.sql');
        $metaPath = $this->backupDir . '/' . $base . '.json';

        $this->logger->debug('Starting backup creation.', ['event' => 'backup.create.start', 'type' => $type]);

        try {
            $this->dumpRunner->dump($dataPath, $this->gzip);
        } catch (\Throwable $exception) {
            if (file_exists($dataPath)) {
                unlink($dataPath);
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
            'created_at' => gmdate('c'),
            'created_by' => $userId,
            'size' => filesize($dataPath),
            'sha256' => hash_file('sha256', $dataPath),
            'app_version' => $this->appVersion,
            'db_name' => $this->dbDatabase,
            'gzip' => $this->gzip,
        ];

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
        $dataPath = is_array($metadata) ? $this->resolveDataPath($metadata) : null;

        if ($dataPath !== null && file_exists($dataPath)) {
            unlink($dataPath);
        }
        unlink($metaPath);

        $this->logger->info('Backup deleted.', ['event' => 'backup.delete', 'id' => $id]);
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
        $dataPath = is_array($metadata) ? $this->resolveDataPath($metadata) : null;

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

        $dataPath = $this->resolveDataPath($metadata);
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

        $this->logger->info('Starting backup restore.', ['event' => 'backup.restore.start', 'id' => $id]);

        try {
            $this->dumpRunner->restore($dataPath, (bool) ($metadata['gzip'] ?? true));
        } catch (\Throwable $exception) {
            $this->logger->error('Backup restore failed.', [
                'event' => 'backup.restore.failed',
                'id' => $id,
                'exception' => $exception,
            ]);
            throw $exception;
        }

        AppSetting::updateOrCreate(
            ['setting_key' => 'session_valid_after'],
            [
                'setting_value' => (string) time(),
                'binary_content' => '',
                'mime_type' => 'text/plain',
            ]
        );

        // "Angemeldet bleiben" tokens are a persistent login mechanism that bypasses
        // the session_valid_after check (AuthMiddleware re-authenticates via a valid
        // remember-login cookie before that check runs, producing a fresh auth_epoch).
        // They must be invalidated too, otherwise remember-me users are silently
        // logged back in right after a restore.
        RememberLogin::query()->delete();

        $this->logger->info('Backup restore completed.', ['event' => 'backup.restore.completed', 'id' => $id]);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function resolveDataPath(array $metadata): ?string
    {
        if (!isset($metadata['id'])) {
            return null;
        }

        $extension = ($metadata['gzip'] ?? true) ? '.sql.gz' : '.sql';

        return $this->backupDir . '/' . $metadata['id'] . $extension;
    }

    private function assertValidId(string $id): void
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new \InvalidArgumentException('Invalid backup id: ' . $id);
        }
    }
}
