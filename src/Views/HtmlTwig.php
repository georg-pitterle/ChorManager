<?php

declare(strict_types=1);

namespace App\Views;

use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;

/**
 * Twig, das seine Antworten als HTML ausweist.
 *
 * `Slim\Views\Twig::render()` schreibt nur in den Rumpf und setzt keinen
 * `Content-Type`. Die HtmlFormCsrfInjectorMiddleware musste eine fehlende
 * Typangabe deshalb als "Seite" lesen und jede untypisierte Antwort vollständig
 * in den Speicher holen, um darin nach Formularen zu suchen - auch große
 * Ausgaben, die gar keine Seite sind. Mit gesetzter Typangabe erkennt sie
 * Seiten am Typ und lässt alles andere unberührt.
 *
 * Eine bereits gesetzte Typangabe bleibt stehen: Wer vor dem Rendern etwas
 * anderes ausweist, meint es so.
 */
final class HtmlTwig extends Twig
{
    private const HTML_CONTENT_TYPE = 'text/html; charset=utf-8';

    /**
     * Ersatz für `Twig::create()`, das fest `new self(...)` baut und deshalb nie
     * eine Unterklasse liefern kann.
     *
     * @param string|list<string> $path
     * @param array<string, mixed> $settings
     */
    public static function createForPath(string|array $path, array $settings = []): self
    {
        return new self(new FilesystemLoader($path), $settings);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    {
        $rendered = parent::render($response, $template, $data);

        if ($rendered->hasHeader('Content-Type')) {
            return $rendered;
        }

        return $rendered->withHeader('Content-Type', self::HTML_CONTENT_TYPE);
    }
}
