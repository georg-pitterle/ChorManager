<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Models\FileFolderShare;

/**
 * Was Collabora mit einer Dateiendung anfangen kann: die Editor-Adresse und ob
 * sich die Endung bearbeiten oder nur ansehen lässt.
 */
final class OfficeAction
{
    public function __construct(
        public readonly string $urlSrc,
        public readonly bool $canEdit
    ) {
    }

    /**
     * Bearbeiten nur mit Stufe "Bearbeiten" und einer bearbeitbaren Endung,
     * Ansehen ab Stufe "Lesen", sonst nichts.
     */
    public function modeFor(int $level): ?string
    {
        if ($level < FileFolderShare::LEVEL_READ) {
            return null;
        }

        return $this->canEdit && $level >= FileFolderShare::LEVEL_EDIT ? 'edit' : 'view';
    }
}
