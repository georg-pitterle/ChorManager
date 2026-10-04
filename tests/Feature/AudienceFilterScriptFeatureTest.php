<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Wächter über public/js/audience-filter.js. Das Projekt hat keine
 * JavaScript-Testumgebung; geprüft wird deshalb der Quelltext an den Stellen,
 * an denen ein Fehler eine Zielgruppe still verändern würde.
 */
class AudienceFilterScriptFeatureTest extends TestCase
{
    private static function script(): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/public/js/audience-filter.js');
        self::assertIsString($content);

        return $content;
    }

    /**
     * Eine Vorlage kann Werte mitbringen, die das Formular nicht anbietet
     * (beendetes Projekt, inaktives Mitglied, gelöschter Eintrag). Fiele so ein
     * Wert weg, würde die Zeile still weiter - aus "Rolle UND Projekt" würde
     * "Rolle". Die Option muss deshalb entstehen statt zu verschwinden.
     */
    public function testValuesWithoutOptionAreAddedInsteadOfDropped(): void
    {
        $script = self::script();
        $start = (int) strpos($script, 'function applyConditions(');
        $body = substr($script, $start, (int) strpos($script, "\n    }\n", $start) - $start);

        $this->assertStringContainsString('new Option(', $body);
        $this->assertStringContainsString('nicht mehr wählbar', $body);
    }

    /**
     * Nachgeladene Dialoge (Vorlage bearbeiten im Newsletter-Modal) müssen ihre
     * Zeilen selbst anbinden können, ohne auf shown.bs.modal zu warten.
     */
    public function testSetupIsCallableForInjectedContent(): void
    {
        $this->assertMatchesRegularExpression('/window\.AudienceFilter = \{[^}]*setupAll/', self::script());
        $modal = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/newsletters.js');
        $this->assertStringContainsString('window.AudienceFilter.setupAll', $modal);
    }
}
