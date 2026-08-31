<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerFeatureTest extends TestCase
{
    public function testSanitizeTaskHtmlRemovesScriptAndJavascriptLinks(): void
    {
        $sanitizer = new HtmlSanitizer();
        $input = '<p>Safe</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>';

        $output = $sanitizer->sanitizeTaskHtml($input);

        $this->assertStringNotContainsString('<script', strtolower($output));
        $this->assertStringNotContainsString('javascript:', strtolower($output));
        $this->assertStringContainsString('<p>Safe</p>', $output);
    }

    public function testSanitizeNewsletterHtmlKeepsBasicLayoutButRemovesEventHandlers(): void
    {
        $sanitizer = new HtmlSanitizer();
        $input = '<table><tr><td onclick="alert(1)">Cell</td></tr></table>';

        $output = $sanitizer->sanitizeNewsletterHtml($input);

        $this->assertStringContainsString('<table>', $output);
        $this->assertStringNotContainsString('onclick', strtolower($output));
    }

    public function testSanitizeNewsletterHtmlRemovesExternalAndDataBackedImages(): void
    {
        $sanitizer = new HtmlSanitizer();
        $input = '<p>Body</p><img src="https://tracker.example/pixel.png" alt="x"><img src="data:image/png;base64,abcd">';

        $output = $sanitizer->sanitizeNewsletterHtml($input);

        $this->assertStringContainsString('<p>Body</p>', $output);
        $this->assertStringNotContainsString('tracker.example', $output);
        $this->assertStringNotContainsString('data:image', strtolower($output));
        $this->assertStringNotContainsString('<img', strtolower($output));
    }

    /**
     * Der Newsletter-Editor bietet Links an. Wurden sie beim Speichern
     * kommentarlos entfernt, stand im versendeten Newsletter nur noch der
     * Linktext ohne Ziel.
     */
    public function testSanitizeNewsletterHtmlKeepsHttpAndHttpsLinks(): void
    {
        $sanitizer = new HtmlSanitizer();
        $input = '<p><a href="https://chor.example/termine">Termine</a> '
            . '<a href="http://chor.example/archiv">Archiv</a> '
            . '<a href="mailto:vorstand@chor.example">Kontakt</a></p>';

        $output = $sanitizer->sanitizeNewsletterHtml($input);

        $this->assertStringContainsString('https://chor.example/termine', $output);
        $this->assertStringContainsString('http://chor.example/archiv', $output);
        $this->assertStringContainsString('mailto:vorstand@chor.example', $output);
    }

    public function testSanitizeNewsletterHtmlStillRemovesJavascriptLinks(): void
    {
        $sanitizer = new HtmlSanitizer();
        $input = '<p><a href="javascript:alert(1)">Klick</a></p>';

        $output = $sanitizer->sanitizeNewsletterHtml($input);

        $this->assertStringNotContainsString('javascript:', strtolower($output));
    }

    /**
     * Beim Einfügen aus Word bringt der Editor <span>-Auszeichnungen mit. Sie
     * sind nicht erlaubt und wurden bisher escaped statt entfernt - der
     * Aufgaben-Detail rendert die Beschreibung roh und zeigte deshalb den
     * Tag-Text an Stelle des Inhalts.
     */
    public function testSanitizeTaskHtmlDropsDisallowedTagsInsteadOfEscapingThem(): void
    {
        $sanitizer = new HtmlSanitizer();
        $input = '<p><span style="font-size: 12.0pt; line-height: 107%;">- App</span></p>';

        $output = $sanitizer->sanitizeTaskHtml($input);

        $this->assertStringNotContainsString('&lt;span', $output);
        $this->assertStringNotContainsString('<span', $output);
        $this->assertStringContainsString('- App', $output);
    }

    public function testSanitizeNewsletterHtmlDropsDisallowedTagsInsteadOfEscapingThem(): void
    {
        $sanitizer = new HtmlSanitizer();
        $input = '<p><font size="3">Grußwort</font></p>';

        $output = $sanitizer->sanitizeNewsletterHtml($input);

        $this->assertStringNotContainsString('&lt;font', $output);
        $this->assertStringNotContainsString('<font', $output);
        $this->assertStringContainsString('Grußwort', $output);
    }

    /**
     * Ein entfernter Script-Block darf seinen Inhalt nicht als sichtbaren Text
     * zurücklassen.
     */
    public function testSanitizeTaskHtmlRemovesScriptContentCompletely(): void
    {
        $sanitizer = new HtmlSanitizer();

        $output = $sanitizer->sanitizeTaskHtml('<p>Safe</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('alert(1)', $output);
        $this->assertStringContainsString('<p>Safe</p>', $output);
    }
}
