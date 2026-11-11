<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\WriteAccessCheck;
use PHPUnit\Framework\TestCase;

/**
 * Der Wächter über die Ablagen, in die die Anwendung schreiben muss.
 *
 * Geprüft wird mit einer auswechselbaren Schreibprobe statt mit echten
 * Dateirechten: Der Testlauf läuft je nach Umgebung als root, und für root
 * meldet `is_writable()` auch ein Verzeichnis ohne Schreibrecht als
 * beschreibbar. Ein Test mit `chmod` wäre damit genau dort grün, wo er nichts
 * mehr prüft.
 */
final class WriteAccessCheckTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/write-access-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $leftover) {
            if (is_string($leftover) && is_dir($leftover)) {
                rmdir($leftover);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    /**
     * @param list<array{label: string, path: string, consequence: string}> $stores
     */
    private function check(array $stores, bool $writable): WriteAccessCheck
    {
        return new WriteAccessCheck($stores, static fn (string $path): bool => $writable);
    }

    /**
     * @return array{label: string, path: string, consequence: string}
     */
    private function store(string $path): array
    {
        return ['label' => 'Anmeldebegrenzung', 'path' => $path, 'consequence' => 'Zählt nicht mehr.'];
    }

    public function testAWritableDirectoryIsNoProblem(): void
    {
        $this->assertSame([], $this->check([$this->store($this->directory)], true)->problems());
    }

    public function testADirectoryWithoutWriteAccessIsReported(): void
    {
        $problems = $this->check([$this->store($this->directory)], false)->problems();

        $this->assertCount(1, $problems);
        $this->assertSame('Anmeldebegrenzung', $problems[0]['label']);
        $this->assertSame($this->directory, $problems[0]['path']);
        $this->assertSame('Zählt nicht mehr.', $problems[0]['consequence']);
    }

    /**
     * Ein noch nicht angelegtes Verzeichnis ist kein Fehler: Die Dienste legen
     * ihre Ablage beim ersten Gebrauch selbst an. Entscheidend ist dann, ob sich
     * das überhaupt noch machen lässt - also das übergeordnete Verzeichnis.
     */
    public function testAMissingDirectoryIsNoProblemWhileItsParentCanBeWritten(): void
    {
        $missing = $this->directory . '/noch-nicht-da';

        $this->assertSame([], $this->check([$this->store($missing)], true)->problems());
    }

    public function testAMissingDirectoryWithoutAWritableParentIsReported(): void
    {
        $missing = $this->directory . '/noch-nicht-da';

        $this->assertCount(1, $this->check([$this->store($missing)], false)->problems());
    }

    /** Fehlt auch das übergeordnete Verzeichnis, lässt sich dort nichts anlegen. */
    public function testAPathWhoseParentDoesNotExistIsReported(): void
    {
        $nested = $this->directory . '/fehlt/tiefer';

        $this->assertCount(1, $this->check([$this->store($nested)], true)->problems());
    }

    public function testOnlyTheFailingStoresAreReported(): void
    {
        $writable = $this->directory;
        $locked = $this->directory . '/gesperrt';
        mkdir($locked, 0777, true);
        $check = new WriteAccessCheck(
            [
                ['label' => 'Gut', 'path' => $writable, 'consequence' => 'egal'],
                ['label' => 'Schlecht', 'path' => $locked, 'consequence' => 'egal'],
            ],
            static fn (string $path): bool => $path === $writable
        );

        $this->assertSame(['Schlecht'], array_column($check->problems(), 'label'));
    }
}
