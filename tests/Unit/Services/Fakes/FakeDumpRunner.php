<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Fakes;

use App\Services\DumpRunnerInterface;

final class FakeDumpRunner implements DumpRunnerInterface
{
    public int $dumpCallCount = 0;
    public int $restoreCallCount = 0;
    public ?string $lastRestoredPath = null;

    public function dump(string $destinationPath, bool $gzip): void
    {
        $this->dumpCallCount++;
        $content = '-- fake dump --';

        if ($gzip) {
            $handle = gzopen($destinationPath, 'wb9');
            gzwrite($handle, $content);
            gzclose($handle);
        } else {
            file_put_contents($destinationPath, $content);
        }
    }

    public function restore(string $sourcePath, bool $gzip): void
    {
        $this->restoreCallCount++;
        $this->lastRestoredPath = $sourcePath;
    }
}
