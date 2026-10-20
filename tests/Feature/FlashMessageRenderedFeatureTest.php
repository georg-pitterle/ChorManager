<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Wer eine Flash-Meldung selbst aus der Sitzung nimmt, muss sie auch anzeigen.
 *
 * `partials/flash.twig` im Layout ist das Auffangnetz: Es holt über den
 * FlashMessageService alles ab, was eine Seite liegen lässt, damit jede Meldung
 * genau einmal und sofort erscheint. Ein Controller, der `$_SESSION['success']`
 * und `$_SESSION['error']` vorher selbst liest und dabei `unset()` aufruft,
 * schaltet dieses Netz für seine Seite ab - die Meldung ist aus der Sitzung
 * verschwunden, bevor das Layout sie sehen kann. Zeigt das Template die
 * übergebenen Werte dann nicht an, ist sie ganz weg: Die Seite kommt ohne
 * Begründung zurück.
 *
 * Genau so verschwand die Begründung beim Löschen einer belegten Terminart.
 * Dieser Wächter arbeitet ohne Liste von Dateien: Er geht von den Stellen aus,
 * die die Sitzung selbst leeren, und prüft das Template, das danach gerendert
 * wird. Eine neue Seite mit demselben Muster fällt damit von sich aus auf.
 */
final class FlashMessageRenderedFeatureTest extends TestCase
{
    private const CONTROLLER_DIR = __DIR__ . '/../../src/Controllers';
    private const TEMPLATE_DIR = __DIR__ . '/../../templates';

    public function testEveryControllerThatConsumesFlashMessagesRendersThem(): void
    {
        $offenders = [];

        foreach ($this->consumptionSites() as $site) {
            foreach (['success', 'error'] as $variable) {
                if (!$this->rendersPageLevel($site['template'], $variable)) {
                    $offenders[] = sprintf(
                        '%s:%d nimmt "%s" aus der Sitzung, %s zeigt es nicht an',
                        $site['file'],
                        $site['line'],
                        $variable,
                        $site['template']
                    );
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * Der Wächter taugt nur, solange er die Stellen überhaupt findet. Fällt das
     * Muster im Quelltext weg, soll das auffallen und nicht als grüner Lauf
     * ohne Prüfung durchgehen.
     */
    public function testGuardActuallyFindsConsumptionSites(): void
    {
        $this->assertGreaterThan(20, count($this->consumptionSites()));
    }

    /**
     * Stellen, die `success` und `error` zusammen aus der Sitzung nehmen, mit
     * dem Template, das der Rumpf danach rendert.
     *
     * @return list<array{file: string, line: int, template: string}>
     */
    private function consumptionSites(): array
    {
        $sites = [];
        foreach ($this->controllerFiles() as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            foreach ($lines as $index => $line) {
                if (!$this->isFlashConsumption($line)) {
                    continue;
                }

                $template = $this->nextRenderedTemplate($lines, $index);
                if ($template === null) {
                    continue;
                }

                $sites[] = [
                    'file' => basename($file),
                    'line' => $index + 1,
                    'template' => $template,
                ];
            }
        }

        return $sites;
    }

    /**
     * Beide Schlüssel in einem `unset()`: So schreiben die Übersichtsseiten das
     * Abholen. Ein einzelnes `unset($_SESSION['success'])` ist etwas anderes -
     * FinanceController::save() löscht damit eine schon gesetzte Erfolgsmeldung,
     * bevor es auf die Eingabemaske zurückgeht, und rendert hier nichts.
     */
    private function isFlashConsumption(string $line): bool
    {
        $patterns = [
            "unset(\$_SESSION['success'], \$_SESSION['error'])",
            "unset(\$_SESSION['error'], \$_SESSION['success'])",
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($line, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     */
    private function nextRenderedTemplate(array $lines, int $from): ?string
    {
        $limit = min($from + 150, count($lines));
        for ($i = $from; $i < $limit; $i++) {
            if (preg_match('/render\(\s*\$response\s*,\s*[\'"]([^\'"]+)[\'"]/', $lines[$i], $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * Zeigt das Template die Meldung auf Seitenebene?
     *
     * Ein Vorkommen in `show_modal_error()` zählt nicht: Die Macro schweigt,
     * wenn kein Modal-Zustand daneben liegt, und genau dann braucht ein Fehler
     * seinen Platz über der Maske. Eingebundene Partials zählen mit - die
     * Anmeldungen führen ihre Meldungen in einem eigenen.
     *
     * @param array<string, true> $seen
     */
    private function rendersPageLevel(string $template, string $variable, array $seen = []): bool
    {
        $path = self::TEMPLATE_DIR . '/' . $template;
        if (!is_file($path) || isset($seen[$path])) {
            return false;
        }

        $seen[$path] = true;
        $source = (string) file_get_contents($path);
        $withoutModalMacros = (string) preg_replace(
            '/(show_modal_error|modal_open_attr)\s*\([^)]*\)/',
            '',
            $source
        );

        if (preg_match('/\{%\s*if\s+' . $variable . '\b/', $withoutModalMacros) === 1) {
            return true;
        }

        if (preg_match('/show_page_error\s*\(\s*' . $variable . '\b/', $withoutModalMacros) === 1) {
            return true;
        }

        if (preg_match_all('/include\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) > 0) {
            foreach ($matches[1] as $included) {
                if ($this->rendersPageLevel($included, $variable, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function controllerFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::CONTROLLER_DIR, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
