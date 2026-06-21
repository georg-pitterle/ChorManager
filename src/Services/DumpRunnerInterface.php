<?php

declare(strict_types=1);

namespace App\Services;

interface DumpRunnerInterface
{
    public function dump(string $destinationPath, bool $gzip): void;

    public function restore(string $sourcePath, bool $gzip): void;
}
