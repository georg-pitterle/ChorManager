<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Wächter für eine gemessene Regression in der Kartenansicht am Telefon.
 *
 * `.table-responsive-cards .btn-group { width: 100% }` streckt die Aktionsgruppe
 * einer Kartenzelle auf die Kartenbreite. Der Selektor ist ein Nachfahren-Selektor
 * und traf damit auch jede Knopfgruppe *innerhalb* eines Dropdown-Menüs, das über
 * dieser Zelle aufgeht. Dort gewinnt `width: 100%` gegen
 * `.attachment-actions { flex: 0 0 auto }`, weil `flex-basis: auto` auf `width`
 * zurückfällt - das Knopfpaar belegte die ganze Zeile und der Dateiname daneben
 * schrumpfte auf null.
 *
 * Gemessen mit Playwright im Pixel-5-Viewport auf /finances: Menü 369px,
 * Knopfpaar 335px, Dateiname 0px. Mit der Rücknahme im Dropdown: Knopfpaar 63px,
 * Dateiname 110px.
 *
 * Der Test prüft die Regel, nicht das Ergebnis - das kann nur ein Browser. Er
 * fängt aber genau den Rückschritt ab, der hier passiert ist: die Rücknahme
 * verschwindet, oder die Streckung wird ohne sie eingeführt.
 */
final class CardViewDropdownWidthTest extends TestCase
{
    private const STYLESHEET = __DIR__ . '/../../../public/css/table-engine.css';

    private const CARD_PREFIX = '[data-table-engine="true"][data-active-view="cards"] .table-responsive-cards';

    private function stylesheet(): string
    {
        $contents = file_get_contents(self::STYLESHEET);
        $this->assertIsString($contents, 'public/css/table-engine.css ist nicht lesbar');

        return $contents;
    }

    public function testCardCellsStillStretchTheirButtonGroup(): void
    {
        $css = $this->stylesheet();

        $this->assertStringContainsString(
            self::CARD_PREFIX . ' .btn-group {',
            $css,
            'Die Streckung der Aktionsgruppe in der Kartenzelle ist die Regel, um die es hier geht.'
        );
    }

    public function testDropdownMenusTakeThatStretchBackAgain(): void
    {
        $css = $this->stylesheet();

        $this->assertStringContainsString(
            self::CARD_PREFIX . ' .dropdown-menu .btn-group {',
            $css,
            'Ohne diese Rücknahme füllt das Knopfpaar im Dropdown die Zeile und der Dateiname verschwindet.'
        );

        $position = strpos($css, self::CARD_PREFIX . ' .dropdown-menu .btn-group {');
        $block = substr($css, (int) $position, 200);

        $this->assertMatchesRegularExpression(
            '/width:\s*auto\s*;/',
            $block,
            'Die Rücknahme muss die Breite wieder auf den Inhalt stellen.'
        );
    }

    /**
     * Die Rücknahme wirkt nur, solange sie hinter der Streckung steht: beide
     * Selektoren sind gleich spezifisch bis auf die zusätzliche Klasse, und ein
     * Wechsel der Reihenfolge wäre in der Datei unauffällig.
     */
    public function testTheTakeBackComesAfterTheStretch(): void
    {
        $css = $this->stylesheet();

        $stretch = strpos($css, self::CARD_PREFIX . ' .btn-group {');
        $takeBack = strpos($css, self::CARD_PREFIX . ' .dropdown-menu .btn-group {');

        $this->assertIsInt($stretch);
        $this->assertIsInt($takeBack);
        $this->assertGreaterThan(
            $stretch,
            $takeBack,
            'Steht die Rücknahme vor der Streckung, gewinnt wieder die Streckung.'
        );
    }
}
