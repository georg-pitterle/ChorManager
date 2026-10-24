<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Ein Paket wurde bei der Übernahme abgelehnt. Die Meldung ist die deutsche
 * Ursache, die der Konsolenbefehl ausgibt.
 */
final class BackupPackageRejectedException extends \RuntimeException
{
}
