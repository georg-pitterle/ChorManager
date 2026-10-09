<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Überschriften und Hinweise folgen wenigen festen Rollen statt vieler Einzelvarianten:
 * Seitentitel, Abschnitt, Karte, Gruppenlabel; Hinweise nur als info, warning oder danger
 * (success nur als Rückmeldung nach einer Aktion).
 */
class UiTypographyFeatureTest extends TestCase
{
    /** Eigenständige Seiten ohne App-Rahmen (Anmeldung, öffentliche Links, Fehler). */
    private const STANDALONE_PREFIXES = ['templates/auth/', 'templates/files/public_link', 'templates/errors/403'];

    /**
     * @return array<string, string>
     */
    private static function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $templates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_starts_with($relative, 'templates/emails/') || str_starts_with($relative, 'templates/help/')) {
                continue;
            }

            $templates[$relative] = (string) file_get_contents($file->getPathname());
        }

        return $templates;
    }

    /**
     * @return list<array{path: string, tag: string, classes: list<string>}>
     */
    private static function headings(): array
    {
        $headings = [];

        foreach (self::templates() as $path => $content) {
            preg_match_all('/<(h[1-6])\b([^>]*)>/', $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $classes = preg_match('/class="([^"]*)"/', $match[2], $classMatch) === 1
                    ? preg_split('/\s+/', trim($classMatch[1])) ?: []
                    : [];
                $headings[] = ['path' => $path, 'tag' => $match[1], 'classes' => $classes];
            }
        }

        return $headings;
    }

    public function testNoEyebrowAboveHeadings(): void
    {
        foreach (self::templates() as $path => $content) {
            $this->assertStringNotContainsString('dashboard-panel__eyebrow', $content, $path);
        }

        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/css/style.css');
        $this->assertStringNotContainsString('dashboard-panel__eyebrow', $css);
    }

    public function testHeadingsCarryNoColourOfTheirOwn(): void
    {
        $offenders = [];

        foreach (self::headings() as $heading) {
            $coloured = preg_grep('/^text-(primary|info|success|danger|warning|muted|secondary)$/', $heading['classes']);
            if ($coloured !== [] && $coloured !== false) {
                $offenders[] = $heading['path'] . ' <' . $heading['tag'] . ' ' . implode(' ', $heading['classes']) . '>';
            }
        }

        $this->assertSame([], $offenders, 'Überschriften mit eigener Farbe statt Rollenstil.');
    }

    public function testUppercaseLabelsUseTheGroupLabelRole(): void
    {
        $offenders = [];

        foreach (self::headings() as $heading) {
            if (in_array('text-uppercase', $heading['classes'], true)) {
                $offenders[] = $heading['path'] . ' <' . $heading['tag'] . '>';
            }
        }

        $this->assertSame([], $offenders, 'Großgeschriebene Kleinüberschriften statt .group-label.');
        $this->assertStringContainsString(
            '.group-label {',
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/css/style.css')
        );
    }

    public function testPageTitlesShareOneSize(): void
    {
        $offenders = [];

        foreach (self::headings() as $heading) {
            if ($heading['tag'] !== 'h1') {
                continue;
            }

            foreach (self::STANDALONE_PREFIXES as $prefix) {
                if (str_starts_with($heading['path'], $prefix)) {
                    continue 2;
                }
            }

            if (!in_array('h2', $heading['classes'], true)) {
                $offenders[] = $heading['path'] . ' <h1 ' . implode(' ', $heading['classes']) . '>';
            }
        }

        $this->assertSame([], $offenders, 'Seitentitel (h1) in anderer Größe als .h2.');
    }

    public function testNoticesUseOnlyMeaningfulAlertTypes(): void
    {
        foreach (self::templates() as $path => $content) {
            $this->assertDoesNotMatchRegularExpression('/\balert-(light|secondary|primary|dark)\b/', $content, $path);
        }
    }

    public function testOutlineWarningButtonIsReadable(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.btn-outline-warning\s*\{[^}]*--bs-btn-color:\s*#[0-9a-fA-F]{6}/s',
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/css/style.css')
        );
    }
}
