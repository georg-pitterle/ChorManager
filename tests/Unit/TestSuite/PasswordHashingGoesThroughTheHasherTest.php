<?php

declare(strict_types=1);

namespace Tests\Unit\TestSuite;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Wächter gegen einen Rückfall: Passwörter werden über App\Util\PasswordHasher gehasht,
 * nicht mit password_hash() von Hand.
 *
 * Anlass: Ein bcrypt-Hash mit dem Standardaufwand kostet im Container rund 160 ms. Die
 * Suite hashte an über hundert Stellen, die meisten davon in setUp(), und verbrachte
 * damit rund ein Viertel ihrer Laufzeit mit Passwörtern, die kein Test je prüft. Der
 * Hasher senkt den Aufwand im Testlauf auf das Minimum - aber nur, solange auch alle
 * ihn benutzen. Ein einzelnes zurückgefallenes password_hash() in einem setUp() kostet
 * bei vierzig Testfällen wieder sechs Sekunden.
 *
 * Der Wächter liest nur Dateien und braucht selbst keine Datenbank.
 */
final class PasswordHashingGoesThroughTheHasherTest extends TestCase
{
    /**
     * Die eine Stelle, an der password_hash() stehen muss: der Hasher selbst.
     */
    private const ALLOWED_PATHS = [
        'src/Util/PasswordHasher.php',
    ];

    /**
     * @return list<string> Pfade relativ zum Projektverzeichnis.
     */
    private function scannedFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];

        foreach (['src', 'tests'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory)
            );

            foreach ($iterator as $file) {
                $path = str_replace(DIRECTORY_SEPARATOR, '/', (string) $file);

                // Der E2E-Baum bleibt außen vor: dort läuft Playwright gegen eine echte
                // Umgebung und hasht nichts in PHP.
                if (!str_ends_with($path, '.php') || str_contains($path, '/e2e/')) {
                    continue;
                }

                $files[] = ltrim(str_replace(str_replace(DIRECTORY_SEPARATOR, '/', $root), '', $path), '/');
            }
        }

        sort($files);

        return $files;
    }

    public function testNobodyHashesPasswordsOnTheirOwn(): void
    {
        $root = dirname(__DIR__, 3);
        $ownPath = 'tests/Unit/TestSuite/PasswordHashingGoesThroughTheHasherTest.php';
        $offenders = [];

        foreach ($this->scannedFiles() as $path) {
            // Diese Datei nennt das verbotene Muster selbst und würde sich sonst anzeigen.
            if ($path === $ownPath || in_array($path, self::ALLOWED_PATHS, true)) {
                continue;
            }

            $content = (string) file_get_contents($root . '/' . $path);

            if (str_contains($content, 'password_hash(')) {
                $offenders[] = $path;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Diese Dateien hashen von Hand statt über App\Util\PasswordHasher::hash():\n"
                . implode("\n", $offenders)
        );
    }

    public function testTheGuardActuallyScansBothTrees(): void
    {
        $files = $this->scannedFiles();

        // Ohne diese Gegenprobe bliebe der Wächter auch dann grün, wenn er nach einer
        // Umbenennung gar keine Datei mehr fände.
        $this->assertGreaterThan(400, count($files), 'Der Wächter findet die Quellbäume nicht mehr.');
        $this->assertContains('src/Util/PasswordHasher.php', $files);
    }
}
