<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileActor;

/** Ergebnis der Zugangsprüfung eines WOPI-Aufrufs. */
final class WopiAccess
{
    public function __construct(
        public readonly User $user,
        public readonly FileActor $actor,
        public readonly StoredFile $file,
        public readonly bool $canWrite
    ) {
    }
}
