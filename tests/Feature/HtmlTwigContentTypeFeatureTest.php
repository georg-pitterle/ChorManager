<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Views\HtmlTwig;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/**
 * `Slim\Views\Twig::render()` schreibt nur in den Rumpf und setzt keinen
 * `Content-Type`. Die HtmlFormCsrfInjectorMiddleware musste eine fehlende
 * Typangabe deshalb als "Seite" lesen und jede untypisierte Antwort vollständig
 * in den Speicher holen. Gerenderte Seiten weisen sich jetzt selbst als HTML
 * aus, damit die Middleware Seiten am Typ erkennt.
 */
final class HtmlTwigContentTypeFeatureTest extends TestCase
{
    public function testRenderedPagesAnnounceThemselvesAsHtml(): void
    {
        $view = $this->view();

        $response = $view->render(new Response(), 'content_type_probe.twig', []);

        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<form method="post">', (string) $response->getBody());
    }

    public function testAnExistingContentTypeIsKept(): void
    {
        $view = $this->view();

        $response = $view->render(
            (new Response())->withHeader('Content-Type', 'text/calendar; charset=utf-8'),
            'content_type_probe.twig',
            []
        );

        $this->assertSame('text/calendar; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testItStaysUsableWhereverSlimTwigIsExpected(): void
    {
        // Der Container liefert die Unterklasse unter dem Schlüssel `Twig::class`;
        // sämtliche Controller verlangen diesen Typ in ihrem Konstruktor.
        $this->assertInstanceOf(Twig::class, $this->view());
    }

    private function view(): HtmlTwig
    {
        return HtmlTwig::createForPath(dirname(__DIR__) . '/Fixtures/templates');
    }
}
