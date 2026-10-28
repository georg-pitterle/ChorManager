<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Ein anderer Backup-Vorgang hält die Sperre - siehe BackupLock.
 *
 * Hieß BackupImportLockedException, solange nur die Paket-Übernahme sperrte.
 * Seit auch Erstellen und Löschen dieselbe Sperre nehmen, wäre "Import" im Namen
 * eine falsche Auskunft über den Grund.
 */
final class BackupLockedException extends \RuntimeException
{
}
