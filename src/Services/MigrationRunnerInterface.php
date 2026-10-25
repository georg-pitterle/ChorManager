<?php

declare(strict_types=1);

namespace App\Services;

interface MigrationRunnerInterface
{
    /**
     * Bringt das Schema auf den Stand des Codes.
     *
     * @throws \RuntimeException wenn die Migration scheitert
     */
    public function migrate(): void;
}
