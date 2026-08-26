<?php

declare(strict_types=1);

namespace Tests\Unit\TestSuite;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Wächter gegen einen Rückfall: Testklassen dürfen sich keine eigene Datenbank im
 * Arbeitsspeicher mit selbstgebautem Schema anlegen.
 *
 * Siebzehn Klassen taten das einmal, jede mit ihrem eigenen Ausschnitt des Schemas. Das driftet
 * lautlos: RoleDeletionFeatureTest kannte von der Tabelle roles fünf Spalten, und neue
 * Fremdschlüssel oder Pflichtfelder aus Migrationen tauchten dort nie auf. Seitdem führt genau
 * ein Weg zur Datenbank, nämlich Tests\Unit\Bootstrap.
 *
 * Dieser Test kommt selbst ohne Datenbank aus - er liest nur Dateien, wie es auch
 * Tests\Unit\Migrations\MigrationChainCompletionTest tut.
 */
final class NoHandBuiltSchemaTest extends TestCase
{
    /**
     * Muster, die auf eine eigene Verbindung oder ein selbstgebautes Schema hindeuten.
     */
    private const FORBIDDEN_PATTERNS = [
        "':memory:'" => 'baut eine eigene Datenbank im Arbeitsspeicher auf',
        '"":memory:""' => 'baut eine eigene Datenbank im Arbeitsspeicher auf',
        'schema()->create(' => 'legt Tabellen von Hand an',
        'schema->create(' => 'legt Tabellen von Hand an',
        'getSchemaBuilder()->create(' => 'legt Tabellen von Hand an',
    ];

    /**
     * Der E2E-Baum bleibt außen vor: dort läuft Playwright gegen eine echte Umgebung.
     */
    private function testFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', (string) $file);
            if (!str_ends_with($path, 'Test.php') || str_contains($path, '/e2e/')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    public function testNoTestClassBuildsItsOwnSchema(): void
    {
        $offenders = [];

        $ownPath = str_replace(DIRECTORY_SEPARATOR, '/', __FILE__);

        foreach ($this->testFiles() as $path) {
            // Diese Datei nennt die verbotenen Muster selbst und würde sich sonst anzeigen.
            if ($path === $ownPath) {
                continue;
            }

            $content = (string) file_get_contents($path);

            foreach (self::FORBIDDEN_PATTERNS as $needle => $reason) {
                if (str_contains($content, $needle)) {
                    $offenders[] = basename($path) . ' ' . $reason;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "Diese Testklassen umgehen die migrierte Datenbank. Nutze Tests\Unit\Bootstrap:\n"
                . implode("\n", array_unique($offenders))
        );
    }

    public function testTheGuardActuallyScansTheTestTree(): void
    {
        $files = $this->testFiles();

        // Ohne diese Gegenprobe würde der Wächter auch dann grün bleiben, wenn er aus Versehen
        // gar keine Datei mehr findet - etwa nach einer Umbenennung des Verzeichnisses.
        $this->assertGreaterThan(200, count($files), 'Der Wächter findet den Testbaum nicht mehr.');
        $this->assertContains(
            str_replace(DIRECTORY_SEPARATOR, '/', __FILE__),
            $files,
            'Der Wächter findet nicht einmal sich selbst.'
        );
    }
}
