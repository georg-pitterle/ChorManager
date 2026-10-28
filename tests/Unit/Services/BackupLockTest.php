<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BackupLock;
use App\Services\BackupLockedException;
use PHPUnit\Framework\TestCase;

/**
 * Die Sperre, die sich Backup-Erstellung, -Löschung und Paket-Übernahme teilen.
 *
 * Gegeneinander laufen dürfen sie nicht: Alle drei greifen in denselben Datei-Pool,
 * und das Aufräumen eines abgebrochenen Backups nimmt einem gleichzeitig laufenden
 * die Dateien weg, die es gerade in sein Manifest schreibt.
 */
final class BackupLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/chormanager_backup_lock_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        // Auch die Sperrdatei: Sie beginnt mit einem Punkt, glob('*') sieht sie nicht.
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $entry) {
            unlink($this->dir . '/' . $entry);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function testWorkRunsAndTheLockIsFreeAfterwards(): void
    {
        $lock = new BackupLock($this->dir);

        $this->assertSame('erledigt', $lock->run(static fn(): string => 'erledigt'));
        $this->assertTrue($this->lockIsFree(), 'Nach dem Lauf muss die Sperre wieder offen sein.');
    }

    public function testLockIsFreeAgainAfterAFailedRun(): void
    {
        $lock = new BackupLock($this->dir);

        try {
            $lock->run(static fn() => throw new \RuntimeException('abgebrochen'));
            $this->fail('Die Ausnahme der Arbeit muss durchkommen.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('abgebrochen', $exception->getMessage());
        }

        $this->assertTrue($this->lockIsFree(), 'Auch ein Abbruch muss die Sperre freigeben.');
    }

    public function testSecondHolderIsTurnedAwayInsteadOfWaiting(): void
    {
        $lock = new BackupLock($this->dir);
        $foreign = $this->holdForeignLock();

        try {
            $this->expectException(BackupLockedException::class);
            $lock->run(static fn(): bool => true);
        } finally {
            flock($foreign, LOCK_UN);
            fclose($foreign);
        }
    }

    /**
     * `flock()` hängt an der geöffneten Datei, nicht am Prozess: Ein zweites
     * Öffnen derselben Datei im selben Prozess bekommt die Sperre nicht. Genau
     * das passiert, wenn create() beim Rotieren delete() aufruft - beide wollen
     * dieselbe Sperre. Verschachtelt zählt sie deshalb nur mit.
     */
    public function testNestedRunsShareTheSameLock(): void
    {
        $lock = new BackupLock($this->dir);

        $result = $lock->run(fn(): string => $lock->run(static fn(): string => 'innen'));

        $this->assertSame('innen', $result);
        $this->assertTrue($this->lockIsFree(), 'Erst der äußerste Lauf gibt die Sperre frei.');
    }

    public function testTheLockIsOnlyReleasedByTheOutermostRun(): void
    {
        $lock = new BackupLock($this->dir);
        $freeInside = null;

        $lock->run(function () use ($lock, &$freeInside): void {
            $lock->run(static fn(): bool => true);
            // Der innere Lauf ist zurück - die Sperre muss trotzdem noch stehen.
            $freeInside = $this->lockIsFree();
        });

        $this->assertFalse($freeInside, 'Der innere Lauf hat die Sperre zu früh freigegeben.');
        $this->assertTrue($this->lockIsFree());
    }

    /** @return resource */
    private function holdForeignLock()
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0750, true);
        }
        $handle = fopen($this->dir . '/' . BackupLock::LOCK_FILE, 'c');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        return $handle;
    }

    private function lockIsFree(): bool
    {
        $handle = fopen($this->dir . '/' . BackupLock::LOCK_FILE, 'c');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }
            flock($handle, LOCK_UN);

            return true;
        } finally {
            fclose($handle);
        }
    }
}
