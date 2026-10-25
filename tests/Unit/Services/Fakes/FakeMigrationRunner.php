<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Fakes;

use App\Services\MigrationRunnerInterface;

final class FakeMigrationRunner implements MigrationRunnerInterface
{
    public int $callCount = 0;
    public ?\Throwable $failWith = null;

    /** @var list<string> Reihenfolge der Aufrufe, gemeinsam mit anderen Fakes nutzbar. */
    public array $journal = [];

    public function migrate(): void
    {
        $this->callCount++;
        $this->journal[] = 'migrate';

        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}
