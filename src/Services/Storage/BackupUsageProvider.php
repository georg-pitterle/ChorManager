<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Backup-Verzeichnis. Der Typ eines Backups steht in seinem Dateinamen
 * (siehe BackupService::ID_PATTERN); Dump, Metadaten und Datei-Manifest eines
 * Backups zählen zusammen. Den Datei-Pool unter `files/` teilen sich alle Backups,
 * er steht deshalb für sich.
 *
 * BackupService selbst wird nicht benutzt: Sein Konstruktor legt das Verzeichnis an
 * und braucht einen Dump-Runner - beides hat in einer Messung nichts verloren.
 */
final class BackupUsageProvider implements StorageUsageProvider
{
    private const BACKUP_FILE = '/^(backup_(manual|auto)_[^.]+)\./';
    private const POOL_DIRECTORY = 'files';

    public function __construct(private readonly string $backupDir)
    {
    }

    public function key(): string
    {
        return 'backups';
    }

    public function label(): string
    {
        return 'Backups';
    }

    public function usage(): StorageUsageNode
    {
        $dumps = [
            'manual' => ['bytes' => 0, 'ids' => []],
            'auto' => ['bytes' => 0, 'ids' => []],
        ];
        $pool = ['bytes' => 0, 'count' => 0];
        $other = ['bytes' => 0, 'count' => 0];

        if (is_dir($this->backupDir)) {
            foreach (new \FilesystemIterator($this->backupDir) as $item) {
                if (!$item instanceof \SplFileInfo || $item->isLink()) {
                    continue;
                }
                $name = $item->getFilename();

                if ($item->isDir()) {
                    $measured = DirectorySize::measure($item->getPathname());
                    if ($name === self::POOL_DIRECTORY) {
                        $pool = $measured;
                    } else {
                        $other['bytes'] += $measured['bytes'];
                        $other['count'] += $measured['count'];
                    }
                    continue;
                }

                if (preg_match(self::BACKUP_FILE, $name, $match) === 1) {
                    $dumps[$match[2]]['bytes'] += (int) $item->getSize();
                    $dumps[$match[2]]['ids'][$match[1]] = true;
                    continue;
                }

                $other['bytes'] += (int) $item->getSize();
                $other['count']++;
            }
        }

        $children = [
            new StorageUsageNode(
                'backups.dumps.manual',
                'Datenbank-Backups (manuell)',
                $dumps['manual']['bytes'],
                count($dumps['manual']['ids'])
            ),
            new StorageUsageNode(
                'backups.dumps.auto',
                'Datenbank-Backups (automatisch)',
                $dumps['auto']['bytes'],
                count($dumps['auto']['ids'])
            ),
            new StorageUsageNode('backups.file_pool', 'Datei-Pool der Backups', $pool['bytes'], $pool['count']),
        ];
        if ($other['bytes'] > 0) {
            $children[] = new StorageUsageNode('backups.other', 'Sonstiges', $other['bytes'], $other['count']);
        }

        return StorageUsageNode::sum($this->key(), $this->label(), $children);
    }
}
