<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Das Backup ist eingespielt, die anschließende Migration aber gescheitert. Die
 * Datenbank steht dann auf dem Schema des Backups.
 */
final class BackupMigrationFailedException extends \RuntimeException
{
}
