<?php

declare(strict_types=1);

namespace Tests\Unit\TestSuite;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Wächter gegen einen Rückfall: Kein Test sichert zu, dass es eine fest benannte Klasse
 * oder Methode gibt.
 *
 * Solche Zusicherungen stammten aus den ersten Würfen der Feature-Tests ("...StructureExists")
 * und sagten nichts: Jeder Test, der die Klasse benutzt, scheitert ohnehin, wenn sie fehlt.
 * 223 dieser Zeilen und 35 Testmethoden, die aus nichts anderem bestanden, wurden im
 * November 2026 gestrichen. Was stattdessen geprüft wird, steht in `/writing-tests`.
 *
 * Erlaubt bleiben die Verneinung - eine bewusst entfernte Methode darf nicht zurückkommen -
 * und Prüfungen auf Variablen in datengetriebenen Wächtern, die über eine Liste laufen.
 */
final class NoExistenceAssertionsTest extends TestCase
{
    private const FORBIDDEN = '~assertTrue\(\s*((?:class|method|interface|trait)_exists)\(\s*\\\\?[A-Za-z_][\w\\\\]*::class~';

    /**
     * Der E2E-Baum bleibt außen vor: dort gibt es keine PHPUnit-Klassen.
     *
     * @return list<string>
     */
    private function testFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2)));
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

    public function testNoTestAssertsThatANamedClassOrMethodExists(): void
    {
        $offenders = [];

        foreach ($this->testFiles() as $path) {
            $content = (string) file_get_contents($path);

            if (preg_match_all(self::FORBIDDEN, $content, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[0] as $index => $match) {
                $offenders[] = sprintf(
                    '%s:%d %s()',
                    basename($path),
                    substr_count($content, "\n", 0, (int) $match[1]) + 1,
                    $matches[1][$index][0]
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Diese Zusicherungen prüfen nur, dass es etwas gibt. Ruf es auf und prüf das Ergebnis:\n"
                . implode("\n", $offenders)
        );
    }

    public function testTheGuardRecognisesTheFormsItForbidsAndTheOnesItAllows(): void
    {
        // Als Text zusammengesetzt, damit sich diese Datei nicht selbst anzeigt.
        $assert = '$this->' . 'assertTrue';

        $this->assertSame(1, preg_match(self::FORBIDDEN, $assert . '(class_exists(\App\Models\User::class));'));
        $this->assertSame(1, preg_match(self::FORBIDDEN, $assert . "(method_exists(User::class, 'roles'));"));
        $this->assertSame(1, preg_match(self::FORBIDDEN, $assert . "(\n    method_exists(User::class, 'roles'),\n"));

        $this->assertSame(0, preg_match(self::FORBIDDEN, '$this->assertFalse(method_exists(User::class, \'old\'));'));
        $this->assertSame(0, preg_match(self::FORBIDDEN, $assert . '(class_exists($class), $class);'));
    }

    public function testTheGuardActuallyScansTheTestTree(): void
    {
        $files = $this->testFiles();

        // Ohne diese Gegenprobe bliebe der Wächter auch dann grün, wenn er aus Versehen gar
        // keine Datei mehr findet - etwa nach einer Umbenennung des Verzeichnisses.
        $this->assertGreaterThan(200, count($files), 'Der Wächter findet den Testbaum nicht mehr.');
        $this->assertContains(
            str_replace(DIRECTORY_SEPARATOR, '/', __FILE__),
            $files,
            'Der Wächter findet nicht einmal sich selbst.'
        );
    }
}
