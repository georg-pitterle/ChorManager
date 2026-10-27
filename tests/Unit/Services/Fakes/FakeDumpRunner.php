<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Fakes;

use App\Services\DumpRunnerInterface;

final class FakeDumpRunner implements DumpRunnerInterface
{
    public int $dumpCallCount = 0;
    public int $restoreCallCount = 0;
    public ?string $lastRestoredPath = null;

    /**
     * Läuft, nachdem der Inhalt geschrieben ist, aber bevor dump() zurückkehrt -
     * der Augenblick, in dem ein echter mysqldump noch schreibt.
     */
    public ?\Closure $whileDumping = null;

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

        if ($this->whileDumping !== null) {
            ($this->whileDumping)($destinationPath);
        }
    }

    public function restore(string $sourcePath, bool $gzip): void
    {
        $this->restoreCallCount++;
        $this->lastRestoredPath = $sourcePath;
    }
}
