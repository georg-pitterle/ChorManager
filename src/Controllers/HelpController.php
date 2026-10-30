<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Help\HelpCatalog;
use App\Navigation\NavigationContext;
use Parsedown;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HelpController
{
    /**
     * Was die Hilfe als Bild ausliefert - und zwar inline, damit es im Text der
     * Seite erscheint.
     *
     * Ohne `svg`, bewusst: Ein SVG ist ein XML-Dokument, das Skript enthalten
     * darf, und `image/svg+xml` inline ausgeliefert führt dieses Skript auf
     * unserer eigenen Herkunft aus - mit der Sitzung der lesenden Person. Die
     * Dateien unter `help/<thema>/screenshots/` liegen im Repository und sind
     * damit heute vertrauenswürdig; eine Grafik, die einmal von außen dazukommt,
     * wäre es nicht, und dann hinge die Lücke an der Herkunft der Datei statt
     * an dieser Liste. Screenshots sind ohnehin PNG (siehe die
     * `create-help-topic`-Anleitung), es geht also kein Format verloren.
     */
    private const IMAGE_MIME_TYPES = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** Ab so vielen Abschnitten lohnt sich die Liste "Auf dieser Seite". */
    private const MIN_TOC_SECTIONS = 2;

    private Twig $view;
    private string $helpDir;
    private HelpCatalog $catalog;

    /** @var array<string,mixed> */
    private array $settings;

    /**
     * @param array<string,mixed> $settings App-Einstellungen; gebraucht werden die Module
     */
    public function __construct(
        Twig $view,
        ?string $helpDir = null,
        array $settings = [],
        ?HelpCatalog $catalog = null
    ) {
        $this->view = $view;
        $this->helpDir = $helpDir ?? dirname(__DIR__, 2) . '/help';
        $this->settings = $settings;
        $this->catalog = $catalog ?? new HelpCatalog($this->helpDir);
    }

    public function index(Request $request, Response $response): Response
    {
        $context = $this->navigationContext($request);

        return $this->view->render($response, 'help/index.twig', [
            'groups'     => $this->catalog->groups($context),
            'questions'  => $this->catalog->frequentQuestions($context),
            'active_nav' => 'help',
        ]);
    }

    /**
     * Die sichtbaren Themen für die Schnellsuche. Sie lädt die Liste erst, wenn
     * jemand sucht - so liest nicht jede Seite die Hilfedateien.
     */
    public function topics(Request $request, Response $response): Response
    {
        $topics = array_map(
            static fn(array $topic): array => [
                'title'    => $topic['title'],
                'url'      => '/help/' . $topic['slug'],
                'group'    => $topic['group'],
                'keywords' => $topic['keywords'],
            ],
            $this->catalog->topics($this->navigationContext($request))
        );

        $response->getBody()->write((string) json_encode($topics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function navigationContext(Request $request): NavigationContext
    {
        return NavigationContext::fromSession($_SESSION ?? [], $this->settings, $request->getUri()->getPath(), 'help');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $path = $this->resolveDocPath((string) ($args['slug'] ?? ''));

        if ($path === null) {
            return $this->view
                ->render($response, 'help/not_found.twig', ['active_nav' => 'help'])
                ->withStatus(404);
        }

        $parsedown = new Parsedown();
        $parsedown->setSafeMode(true);

        // Der Titel steht im Seitenkopf; die Überschrift in der ersten Zeile der Datei
        // würde ihn direkt darunter ein zweites Mal zeigen.
        $markdown = (string) preg_replace('/\A#\s+.+\R?/', '', (string) file_get_contents($path));

        $document = $this->enrichDocument($parsedown->text($markdown));

        return $this->view->render($response, 'help/show.twig', [
            'title'        => HelpCatalog::titleFromFile($path, basename($path, '.md')),
            'content_html' => $document['html'],
            'toc'          => $document['toc'],
            'active_nav'   => 'help',
        ]);
    }

    public function image(Request $request, Response $response, array $args): Response
    {
        $file = (string) ($args['file'] ?? '');
        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $mimeType = self::IMAGE_MIME_TYPES[$extension] ?? null;

        if ($mimeType === null || str_contains($file, '..') || str_starts_with($file, '/')) {
            return $response->withStatus(404);
        }

        // file is "topic/filename.png"; map to help/topic/screenshots/filename.png
        $parts = explode('/', $file, 2);
        if (count($parts) !== 2) {
            return $response->withStatus(404);
        }
        [$topic, $filename] = $parts;

        $realHelpDir = realpath($this->helpDir);
        $realPath = realpath($this->helpDir . '/' . $topic . '/screenshots/' . $filename);

        if (
            $realHelpDir === false
            || $realPath === false
            || !str_starts_with($realPath, $realHelpDir . DIRECTORY_SEPARATOR)
        ) {
            return $response->withStatus(404);
        }

        $response->getBody()->write((string) file_get_contents($realPath));

        return $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Ergänzt, was Parsedown nicht liefert: Tabellen für schmale Bildschirme,
     * Bilder in voller Größe und Sprungziele für "Auf dieser Seite". Dokumente
     * ohne solche Elemente bleiben Byte für Byte, wie Parsedown sie liefert.
     *
     * @return array{html: string, toc: list<array{id: string, label: string}>}
     */
    private function enrichDocument(string $html): array
    {
        if (preg_match('/<(?:table|img|h2)\b/', $html) !== 1) {
            return ['html' => $html, 'toc' => []];
        }

        $document = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR
        );

        $this->labelTableCells($document);
        $this->linkImages($document);
        $toc = $this->anchorSections($document);

        return [
            'html' => $document->body->innerHTML,
            'toc' => count($toc) >= self::MIN_TOC_SECTIONS ? $toc : [],
        ];
    }

    /**
     * Macht jedes Bild zum Link auf sich selbst: In der Lesespalte ist ein
     * Screenshot kleiner als aufgenommen, die Beschriftungen darin wären sonst
     * nicht mehr gut zu lesen.
     */
    private function linkImages(\Dom\HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('img') as $image) {
            if ($image->closest('a') !== null) {
                continue;
            }

            $link = $document->createElement('a');
            $link->setAttribute('href', (string) $image->getAttribute('src'));
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', 'noopener');
            $link->setAttribute('title', 'Bild in voller Größe öffnen');
            $image->before($link);
            $link->append($image);
        }
    }

    /**
     * Gibt jedem Abschnitt ein Sprungziel. Nur `h2`: Mit jedem Unterpunkt würde
     * die Liste so lang wie der Text, den sie abkürzen soll.
     *
     * @return list<array{id: string, label: string}>
     */
    private function anchorSections(\Dom\HTMLDocument $document): array
    {
        $toc = [];
        $used = [];

        foreach ($document->querySelectorAll('h2') as $heading) {
            $label = trim($heading->textContent);
            $slug = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($label)), '-');
            $base = $slug !== '' ? $slug : 'section';
            $used[$base] = ($used[$base] ?? 0) + 1;
            $id = $used[$base] === 1 ? $base : $base . '-' . $used[$base];

            $heading->setAttribute('id', $id);
            $toc[] = ['id' => $id, 'label' => $label];
        }

        return $toc;
    }

    /**
     * Schreibt an jede Tabellenzelle ihre Spaltenüberschrift als `data-label` und
     * fasst jede Tabelle in einen Rahmen, der notfalls seitwärts scrollt.
     *
     * Auf schmalen Bildschirmen stapelt das Stylesheet jede Zeile zu einem Block,
     * und die Überschrift steht dann als Beschriftung über dem Zellinhalt - ohne
     * sie wüsste man im gestapelten Block nicht mehr, welcher Wert welcher ist.
     */
    private function labelTableCells(\Dom\HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('table') as $table) {
            $frame = $document->createElement('div');
            $frame->setAttribute('class', 'markdown-table');
            $table->before($frame);
            $frame->append($table);

            $labels = [];
            foreach ($table->querySelectorAll('thead th') as $header) {
                $labels[] = trim($header->textContent);
            }

            foreach ($table->querySelectorAll('tbody tr') as $row) {
                $index = 0;
                foreach ($row->children as $cell) {
                    $label = $labels[$index++] ?? '';
                    if ($label !== '') {
                        $cell->setAttribute('data-label', $label);
                    }
                }
            }
        }
    }

    private function resolveDocPath(string $slug): ?string
    {
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return null;
        }

        $realHelpDir = realpath($this->helpDir);
        if ($realHelpDir === false) {
            return null;
        }

        foreach (glob($this->helpDir . '/*/docs/' . $slug . '.md') ?: [] as $candidate) {
            $realPath = realpath($candidate);
            if ($realPath !== false && str_starts_with($realPath, $realHelpDir . DIRECTORY_SEPARATOR)) {
                return $realPath;
            }
        }

        return null;
    }
}
