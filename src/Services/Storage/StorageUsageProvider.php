<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Ein Bereich der Speicherübersicht. Ein Provider darf werfen - der
 * StorageUsageService fängt das ab und setzt für den Bereich einen Fehlerknoten
 * mit key() und label() ein.
 */
interface StorageUsageProvider
{
    public function key(): string;

    public function label(): string;

    public function usage(): StorageUsageNode;
}
