<?php

declare(strict_types=1);

namespace App\Controllers;

use Parsedown;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HelpController
{
    private const IMAGE_MIME_TYPES = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    private Twig $view;
    private string $helpDir;

    public function __construct(Twig $view, ?string $helpDir = null)
    {
        $this->view = $view;
        $this->helpDir = $helpDir ?? dirname(__DIR__, 2) . '/help';
    }

    public function index(Request $request, Response $response): Response
    {
        $guides = [];
        foreach (glob($this->helpDir . '/*/docs/*.md') ?: [] as $path) {
            $slug = basename($path, '.md');
            $guides[$slug] = [
                'slug'  => $slug,
                'title' => $this->extractTitle($path, $slug),
            ];
        }

        return $this->view->render($response, 'help/index.twig', [
            'categories' => $this->groupIntoCategories($guides),
            'active_nav' => 'help',
        ]);
    }

    /**
     * Groups the flat docs/*.md guides into categories by naming convention:
     * "foo.md" is a category root, "foo-bar.md" is grouped under it as a child.
     * A guide with no root of its own becomes a single-page category.
     *
     * @param array<string,array{slug:string,title:string}> $guides
     * @return array<int,array{slug:string,title:string,items:array<int,array{slug:string,title:string}>}>
     */
    private function groupIntoCategories(array $guides): array
    {
        $slugs = array_keys($guides);

        $rootOf = [];
        foreach ($slugs as $slug) {
            $bestRoot = null;
            foreach ($slugs as $candidate) {
                if ($candidate === $slug || !str_starts_with($slug, $candidate . '-')) {
                    continue;
                }

                if ($bestRoot === null || strlen($candidate) > strlen($bestRoot)) {
                    $bestRoot = $candidate;
                }
            }

            $rootOf[$slug] = $bestRoot ?? $slug;
        }

        $categories = [];
        foreach ($slugs as $slug) {
            $root = $rootOf[$slug];
            $categories[$root]['slug'] = $root;
            $categories[$root]['title'] = $guides[$root]['title'];
            $categories[$root]['items'][] = $guides[$slug];
        }

        ksort($categories);

        foreach ($categories as $root => &$category) {
            usort($category['items'], static function (array $a, array $b) use ($root): int {
                if ($a['slug'] === $root) {
                    return -1;
                }

                if ($b['slug'] === $root) {
                    return 1;
                }

                return $a['slug'] <=> $b['slug'];
            });
        }
        unset($category);

        return array_values($categories);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $path = $this->resolveDocPath((string) ($args['slug'] ?? ''));

        if ($path === null) {
            $response->getBody()->write('Hilfeseite nicht gefunden.');
            return $response->withStatus(404);
        }

        $parsedown = new Parsedown();
        $parsedown->setSafeMode(true);

        return $this->view->render($response, 'help/show.twig', [
            'title'        => $this->extractTitle($path, basename($path, '.md')),
            'content_html' => $parsedown->text((string) file_get_contents($path)),
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

    private function extractTitle(string $path, string $fallbackSlug): string
    {
        $content = (string) file_get_contents($path);

        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return ucwords(str_replace('-', ' ', $fallbackSlug));
    }
}
