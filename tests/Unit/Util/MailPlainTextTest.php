<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\MailPlainText;
use PHPUnit\Framework\TestCase;

/**
 * Die Textfassung einer Mail entstand bisher aus `strip_tags()`. Das entfernt nur die
 * Auszeichnung, nicht den Inhalt von `<style>` — die Textfassung jeder Systemmail begann
 * deshalb mit dem vollständigen CSS-Block der Vorlage. Dazu fehlte jeder Umbruch, weil
 * `strip_tags()` Absätze einfach aneinanderklebt.
 */
final class MailPlainTextTest extends TestCase
{
    public function testStyleBlockDoesNotLeakIntoTheTextVersion(): void
    {
        $html = '<html><head><title>Betreff</title>'
            . '<style>body { color: red; } .cm-card { width: 100% !important; }</style>'
            . '</head><body><p>Hallo Georg</p></body></html>';

        $text = MailPlainText::fromHtml($html);

        $this->assertStringNotContainsString('color: red', $text);
        $this->assertStringNotContainsString('cm-card', $text);
        $this->assertStringNotContainsString('!important', $text);
        $this->assertSame('Hallo Georg', $text);
    }

    public function testTitleAndScriptAreDroppedWithTheirContent(): void
    {
        $html = '<head><title>Nur im Reiter</title><script>var x = 1;</script></head>'
            . '<body><p>Sichtbar</p></body>';

        $text = MailPlainText::fromHtml($html);

        $this->assertStringNotContainsString('Nur im Reiter', $text);
        $this->assertStringNotContainsString('var x', $text);
        $this->assertSame('Sichtbar', $text);
    }

    /**
     * Nicht jeder Stilblock steht im Kopfbereich: Newsletter-Inhalte aus dem Editor und
     * aus einem Word-Einfügen tragen ihn mitten im Rumpf. Würde nur `<head>` fallen,
     * stünde dieses CSS weiter in der Textfassung.
     */
    public function testStyleBlockInsideTheBodyIsDroppedToo(): void
    {
        $html = '<body><p>Vor dem Block</p>'
            . '<style>.cm-cta { background-color: #E8A817; }</style>'
            . '<p>Nach dem Block</p></body>';

        $text = MailPlainText::fromHtml($html);

        $this->assertStringNotContainsString('background-color', $text);
        $this->assertStringNotContainsString('cm-cta', $text);
        $this->assertStringNotContainsString('E8A817', $text);
        $this->assertSame("Vor dem Block\n\nNach dem Block", $text);
    }

    public function testScriptInsideTheBodyIsDroppedToo(): void
    {
        $html = '<body><p>Sichtbar</p><script>document.write("unsichtbar");</script></body>';

        $text = MailPlainText::fromHtml($html);

        $this->assertStringNotContainsString('document.write', $text);
        $this->assertStringNotContainsString('unsichtbar', $text);
        $this->assertSame('Sichtbar', $text);
    }

    public function testBlockElementsBecomeLineBreaks(): void
    {
        $html = '<p>Erster Absatz</p><p>Zweiter Absatz</p>';

        $text = MailPlainText::fromHtml($html);

        $this->assertSame("Erster Absatz\n\nZweiter Absatz", $text);
    }

    public function testLineBreakTagBecomesNewline(): void
    {
        $text = MailPlainText::fromHtml('Erste Zeile<br>Zweite Zeile<br />Dritte Zeile');

        $this->assertSame("Erste Zeile\nZweite Zeile\nDritte Zeile", $text);
    }

    public function testListItemsAndTableRowsEachGetTheirOwnLine(): void
    {
        $html = '<ul><li>Sopran</li><li>Alt</li></ul>'
            . '<table><tr><td>Termin</td><td>Freitag</td></tr><tr><td>Ort</td><td>Pfarrsaal</td></tr></table>';

        $lines = explode("\n", MailPlainText::fromHtml($html));

        $this->assertContains('Sopran', $lines);
        $this->assertContains('Alt', $lines);
        $this->assertContains('Termin Freitag', $lines);
        $this->assertContains('Ort Pfarrsaal', $lines);
    }

    public function testEntitiesAreDecoded(): void
    {
        $text = MailPlainText::fromHtml('<p>Pr&auml;sentation &amp; Probe &quot;Elements&quot;</p>');

        $this->assertSame('Präsentation & Probe "Elements"', $text);
    }

    public function testLinkTargetIsKeptSoTheTextVersionStaysUsable(): void
    {
        $html = '<p>Zum Termin: <a href="https://chor.example/events/7">Details</a></p>';

        $text = MailPlainText::fromHtml($html);

        $this->assertStringContainsString('https://chor.example/events/7', $text);
    }

    public function testMoreThanOneBlankLineIsCollapsed(): void
    {
        $html = '<div></div><div></div><p>Eins</p><div></div><div></div><div></div><p>Zwei</p>';

        $this->assertSame("Eins\n\nZwei", MailPlainText::fromHtml($html));
    }

    public function testEmptyHtmlStaysEmpty(): void
    {
        $this->assertSame('', MailPlainText::fromHtml(''));
        $this->assertSame('', MailPlainText::fromHtml('<style>body { color: red; }</style>'));
    }
}
