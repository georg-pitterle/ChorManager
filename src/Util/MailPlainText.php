<?php

declare(strict_types=1);

namespace App\Util;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Die Textfassung einer Mail aus ihrem HTML.
 *
 * Vorher entstand sie aus `strip_tags()`. Das entfernt die Auszeichnung, nicht den Inhalt
 * der Auszeichnung: Der `<style>`-Block aus `templates/emails/_layout.twig` blieb als
 * Fließtext stehen, und die Textfassung jeder Systemmail begann mit rund dreißig Zeilen
 * CSS. Dahinter klebten alle Absätze aneinander, weil ein `</p>` ohne Ersatz verschwindet.
 *
 * Gearbeitet wird auf dem DOM, wie in NewsletterMailRenderer::inlineContentClasses(): Ein
 * regulärer Ausdruck über HTML trifft verschachtelte Elemente und Attributwerte nicht
 * zuverlässig, und der Inhalt kommt hier zum Teil aus einem Editor.
 *
 * Die Adresse eines Links wandert in Klammern hinter seinen Text. Ohne sie verlöre die
 * Textfassung genau das, worauf die Mail hinauswill - den Verweis auf Termin, Aufgabe oder
 * Newsletter.
 */
final class MailPlainText
{
    /** Diese Elemente verschwinden samt Inhalt - er gehört in keine Textfassung. */
    private const DROPPED_ELEMENTS = ['head', 'style', 'script', 'title'];

    /** Elemente, die eine eigene Zeile beginnen und beenden. */
    private const BLOCK_ELEMENTS = [
        'address', 'article', 'blockquote', 'center', 'div', 'footer', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'header', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section',
        'table', 'tbody', 'tfoot', 'thead', 'tr', 'ul',
    ];

    /** Zellen trennt ein Leerzeichen, nicht ein Umbruch - eine Zeile bleibt eine Zeile. */
    private const CELL_ELEMENTS = ['td', 'th'];

    /** Elemente, die für sich einen Umbruch bedeuten. */
    private const BREAK_ELEMENTS = ['br', 'hr'];

    public static function fromHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument();
        $previousErrorState = libxml_use_internal_errors(true);
        // Die Kodierungs-Anweisung voran, sonst deutet libxml den Rumpf als ISO-8859-1
        // und aus jedem Umlaut werden zwei Zeichen.
        $loaded = $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorState);

        if ($loaded === false || !$document->documentElement instanceof DOMElement) {
            return self::withoutMarkup($html);
        }

        self::dropHiddenElements($document);

        return self::tidy(self::render($document->documentElement));
    }

    /**
     * Entfernt Kopfbereich, Stilangaben und Skripte samt Inhalt.
     *
     * Rückwärts durch die Liste: `getElementsByTagName()` liefert eine lebende Liste, und
     * wer darin vorwärts löscht, überspringt bei jedem Treffer das nächste Element.
     */
    private static function dropHiddenElements(DOMDocument $document): void
    {
        foreach (self::DROPPED_ELEMENTS as $tagName) {
            $elements = $document->getElementsByTagName($tagName);
            for ($index = $elements->length - 1; $index >= 0; $index--) {
                $element = $elements->item($index);
                $element?->parentNode?->removeChild($element);
            }
        }
    }

    private static function render(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            // Umbrüche und Einrückung der Vorlage sind Gestaltung, nicht Inhalt; die
            // Zeilen dieser Fassung entstehen unten aus den Elementen.
            return (string) preg_replace('/\s+/u', ' ', $node->data);
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        $name = strtolower($node->nodeName);
        if (in_array($name, self::BREAK_ELEMENTS, true)) {
            return "\n";
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::render($child);
        }

        if ($name === 'a') {
            return self::withLinkTarget($node, $inner);
        }

        if (in_array($name, self::CELL_ELEMENTS, true)) {
            return $inner . ' ';
        }

        return in_array($name, self::BLOCK_ELEMENTS, true) ? "\n" . $inner . "\n" : $inner;
    }

    /**
     * Die Adresse hinter den Linktext, sofern sie dort nicht ohnehin schon steht: Der
     * Fußbereich der Vorlagen schreibt manche Adresse bereits aus, und zweimal
     * dieselbe URL hintereinander liest sich wie ein Fehler.
     */
    private static function withLinkTarget(DOMElement $link, string $text): string
    {
        $href = trim($link->getAttribute('href'));
        if ($href === '' || str_starts_with($href, 'mailto:') || str_contains($text, $href)) {
            return $text;
        }

        return rtrim($text) . ' (' . $href . ')';
    }

    /**
     * Leerzeichen je Zeile zusammenfassen, Zeilen trimmen und mehr als eine Leerzeile
     * zusammenziehen. Ohne diesen Schritt trägt die Textfassung die Einrückung der
     * Vorlage und für jedes verschachtelte Element eine weitere Leerzeile.
     */
    private static function tidy(string $text): string
    {
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $lines[] = trim((string) preg_replace('/[ \t]+/u', ' ', $line));
        }

        $joined = (string) preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines));

        return trim($joined);
    }

    /**
     * Rückfallebene für einen Rumpf, den libxml nicht annimmt. Die versteckten Elemente
     * fallen hier über einen regulären Ausdruck - lieber ungenau als mit dem CSS-Block.
     */
    private static function withoutMarkup(string $html): string
    {
        $pattern = '#<(' . implode('|', self::DROPPED_ELEMENTS) . ')\b[^>]*>.*?</\1\s*>#is';
        $withoutHidden = (string) preg_replace($pattern, '', $html);
        $withBreaks = (string) preg_replace('#<(br|hr)\b[^>]*/?>|</(p|div|li|tr|h[1-6])\s*>#i', "\n", $withoutHidden);

        return self::tidy(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
