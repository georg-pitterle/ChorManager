<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameSortOrderFeatureTest extends TestCase
{
    /**
     * @return array<int, array{0: string}>
     */
    public static function sortingSources(): array
    {
        return [
            ['src/Queries/UserQuery.php'],
            ['src/Queries/ProjectQuery.php'],
            ['src/Controllers/AttendanceController.php'],
            ['src/Controllers/EvaluationController.php'],
            // Die Personenauswahl der Zielgruppen (Termine, Newsletter, Freigaben)
            // sortiert der gemeinsame Describer.
            ['src/Services/Audience/AudienceDescriber.php'],
            ['src/Controllers/NewsletterController.php'],
            ['src/Controllers/RegistrationController.php'],
            ['src/Controllers/TaskController.php'],
            // Die beiden Personenauswahlen der Sponsoring-Vorgänge: der
            // Bearbeiten-Dialog am Sponsor und das Zuweisen aus "Ohne
            // Zuständigkeit".
            ['src/Queries/SponsoringQuery.php'],
        ];
    }

    #[DataProvider('sortingSources')]
    public function testNoHardcodedNameOrdering(string $relativePath): void
    {
        $source = file_get_contents(self::projectRoot() . '/' . $relativePath);

        $this->assertIsString($source);
        $this->assertStringNotContainsString("orderBy('last_name')", $source);
        $this->assertStringNotContainsString("orderBy('first_name')", $source);
        $this->assertStringNotContainsString("sortBy(['last_name', 'first_name'])", $source);

        // Die Reihenfolge kommt entweder aus applyNameOrder() - dem Weg für
        // Abfragen - oder aus orderColumns(), das die beiden Sammlungs-Sortierungen
        // (AttendanceController, RegistrationController) und die Spaltenliste der
        // Auswertungstabelle brauchen. Hart verdrahtet wird sie nirgends.
        $usesFormatter = str_contains($source, 'applyNameOrder(')
            || str_contains($source, 'orderColumns()');
        $this->assertTrue(
            $usesFormatter,
            $relativePath . ' muss die Namensreihenfolge über NameFormatterService beziehen.'
        );
    }

    /**
     * Dasselbe Verbot wie oben, nur ohne Liste: geprüft wird ganz src/.
     *
     * Die Liste in sortingSources() kann nur Dateien prüfen, die jemand
     * eingetragen hat - und genau daran lief die Regel vorbei. Die
     * Personenauswahl der Sponsoring-Vorgänge sortierte in zwei Dateien hart
     * nach `last_name`, während der Filter `person_name` daneben die
     * eingestellte Reihenfolge ausgab: Die Auswahl las sich damit unsortiert,
     * sobald "Vorname Nachname" eingestellt war. Beide Dateien standen nicht in
     * der Liste, und der Wächter schwieg.
     *
     * Dieser Teil braucht keine Liste: Wer die Reihenfolge irgendwo in src/ hart
     * verdrahtet, läuft hier auf, auch in einer Datei, die es heute noch nicht
     * gibt.
     */
    public function testNoSourceFileInSrcHardcodesNameOrdering(): void
    {
        $forbidden = [
            "orderBy('last_name')",
            "orderBy('first_name')",
            "sortBy(['last_name', 'first_name'])",
        ];
        $offenders = [];

        foreach (self::phpFilesIn(self::projectRoot() . '/src') as $path) {
            $source = (string) file_get_contents($path);
            foreach ($forbidden as $needle) {
                if (!str_contains($source, $needle)) {
                    continue;
                }
                $offenders[] = substr($path, strlen(self::projectRoot()) + 1) . ': ' . $needle;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Die Namensreihenfolge gehört in NameFormatterService::applyNameOrder(), '
                . 'nicht in ein hart verdrahtetes orderBy(): ' . implode(', ', $offenders)
        );
    }

    /**
     * Die Schleife über orderColumns() stand neunmal wortgleich im Code, bis
     * NameFormatterService::applyNameOrder() sie aufgenommen hat. Dieser Wächter
     * hält den Zustand: wer sie erneut abschreibt, statt die Methode zu rufen,
     * läuft hier auf.
     */
    public function testOrderColumnsLoopIsNotCopiedAgain(): void
    {
        // Die eine Schleife, die bleiben muss: sie steckt in applyNameOrder() selbst.
        $home = self::projectRoot() . '/src/Services/NameFormatterService.php';
        $offenders = [];

        foreach (self::phpFilesIn(self::projectRoot() . '/src') as $path) {
            if ($path === $home) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (preg_match('/foreach\s*\(.*orderColumns\(\).*as\s/', $source) !== 1) {
                continue;
            }
            $offenders[] = substr($path, strlen(self::projectRoot()) + 1);
        }

        $this->assertSame(
            [],
            $offenders,
            'Statt einer eigenen Schleife über orderColumns() gehört hier '
                . 'NameFormatterService::applyNameOrder() aufgerufen: '
                . implode(', ', $offenders)
        );
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
