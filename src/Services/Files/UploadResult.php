<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\StoredFile;

final class UploadResult
{
    public function __construct(
        public readonly StoredFile $file,
        public readonly bool $isNewVersion
    ) {
    }
}
