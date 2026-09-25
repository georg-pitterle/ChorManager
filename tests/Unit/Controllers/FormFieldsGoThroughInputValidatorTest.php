<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Wächter gegen einen Rückfall: Ein Formularfeld darf nicht ungeprüft in eine
 * Funktion mit Typangabe laufen.
 *
 * `trim($data['name'] ?? '')` stand an 51 Stellen in 13 Controllern. Kommt das
 * Feld als `name[]=x` herein - und der Browser darf das -, liegt in
 * `getParsedBody()` ein Array, und `trim()` wirft unter `strict_types` einen
 * TypeError: Statusseite 500 statt einer Formularmeldung. Jede dieser Masken war
 * damit von jedem angemeldeten Mitglied lahmzulegen.
 *
 * `InputValidator::asString()` gibt es für genau diesen Fall und begründet ihn in
 * seinem Kommentar. Der Wächter liest nur Dateien, wie
 * Tests\Unit\TestSuite\NoHandBuiltSchemaTest.
 */
final class FormFieldsGoThroughInputValidatorTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function controllerFiles(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Controllers/*.php') ?: [];
        sort($files);

        return $files;
    }

    public function testNoControllerTrimsARequestFieldDirectly(): void
    {
        $offenders = $this->linesMatching('/trim\(\$data\[/');

        $this->assertSame(
            [],
            $offenders,
            "Diese Stellen geben ein Formularfeld ungeprüft an trim() weiter. Ein Feld-Array\n"
                . "ergibt dort einen TypeError. Nutze InputValidator::asString():\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * Die mildere Schwester des Falls oben: `(string) $data['x']` wirft keinen
     * TypeError, liefert aus einem Array aber die Zeichenkette "Array" - ein Wert,
     * den nie jemand eingegeben hat und der jede Längen- und Leerprüfung dahinter
     * besteht. An ein paar Stellen wurde er dadurch gespeichert statt abgewiesen.
     */
    public function testNoControllerCastsARequestFieldToStringByHand(): void
    {
        $offenders = $this->linesMatching('/\(string\) \(\$(?:data|queryParams|params)\[/');

        $this->assertSame(
            [],
            $offenders,
            "Diese Stellen wandeln ein Formularfeld mit (string) um. Aus einem Feld-Array\n"
                . "wird dabei \"Array\". Nutze InputValidator::asString():\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * @return list<string>
     */
    private function linesMatching(string $pattern): array
    {
        $offenders = [];

        foreach ($this->controllerFiles() as $path) {
            foreach (file($path) ?: [] as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $offenders[] = basename($path) . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }

        return $offenders;
    }

    /**
     * Gegenprobe: Ohne sie bliebe der Wächter auch dann grün, wenn er nach einer
     * Umbenennung des Verzeichnisses gar keine Datei mehr fände.
     */
    public function testTheGuardScansTheControllers(): void
    {
        $this->assertGreaterThan(30, count($this->controllerFiles()));
    }

    public function testTheGuardRecognisesTheOldPatterns(): void
    {
        $this->assertSame(
            1,
            preg_match('/trim\(\$data\[/', "        \$name = trim(\$data['name'] ?? '');")
        );
        $this->assertSame(
            1,
            preg_match(
                '/\(string\) \(\$(?:data|queryParams|params)\[/',
                "        \$name = trim((string) (\$data['name'] ?? ''));"
            )
        );
    }
}
