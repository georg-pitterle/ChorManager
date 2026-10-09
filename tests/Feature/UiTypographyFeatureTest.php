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
            if (str_starts_with($relative, 'templates/emails/')) {
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

        foreach (self::templates() as $path => $content) {
            $this->assertStringNotContainsString('text-uppercase', $content, $path . ': Großschreibung nur über .group-label.');
        }
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

    /**
     * Über dem Seitentitel steht keine Kleinüberschrift mehr (früher der Menüabschnitt in
     * Großbuchstaben). Wo eine Seite einen Weg zurück braucht, ist das eine Brotkrumenleiste.
     */
    public function testPageTitlesHaveNoEyebrow(): void
    {
        foreach (self::templates() as $path => $content) {
            $this->assertStringNotContainsString('class="text-uppercase text-muted small mb-1"', $content, $path);
        }
    }

    public function testPageSectionAndGroupHeadingsCarryNoIcon(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $content) {
            preg_match_all('/<(h[1-6])\b([^>]*)>(.*?)<\/\1>/s', $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $isMainHeading = $match[1] === 'h1'
                    || str_contains($match[2], 'dashboard-section-title')
                    || str_contains($match[2], 'group-label');

                if ($isMainHeading && str_contains($match[3], '<i ')) {
                    $offenders[] = $path . ' <' . $match[1] . '>';
                }
            }
        }

        $this->assertSame([], $offenders, 'Seiten-, Abschnitts- oder Gruppentitel mit Icon.');
    }

    public function testFormLabelsShareOneStyle(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $content) {
            preg_match_all('/<label\b[^>]*class="([^"]*\bform-label\b[^"]*)"/', $content, $matches);

            foreach ($matches[1] as $classes) {
                if (preg_match('/\b(fw-bold|fw-semibold|text-uppercase|text-muted|small)\b/', $classes) === 1) {
                    $offenders[] = $path . ': ' . $classes;
                }
            }
        }

        $this->assertSame([], $offenders, 'Feldbeschriftungen mit eigenem Stil.');
    }

    /**
     * Speichern sitzt auf Seiten immer in der gemeinsamen, mitlaufenden Aktionsleiste; die
     * früheren eigenen Varianten (graue Klebeleiste, Rasterzeile rechts) gibt es nicht mehr.
     */
    public function testSaveActionsUseTheSharedActionBar(): void
    {
        foreach (self::templates() as $path => $content) {
            $this->assertStringNotContainsString('sticky-bottom', $content, $path);
            $this->assertStringNotContainsString('d-grid justify-content-md-end', $content, $path);
            $this->assertStringNotContainsString('d-grid gap-2 d-md-flex justify-content-md-end', $content, $path);
        }

        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/css/style.css');
        $this->assertMatchesRegularExpression('/\.form-action-bar \{[^}]*position: sticky;[^}]*justify-content: flex-end;/s', $css);

        $pageForms = ['settings/index.twig', 'profile/index.twig', 'attendance/show.twig', 'registrations/detail.twig',
            'events/edit.twig', 'songs/create.twig', 'songs/detail.twig', 'newsletters/create.twig'];
        foreach ($pageForms as $template) {
            $this->assertStringContainsString('class="form-action-bar"', self::templates()['templates/' . $template], $template);
        }
    }

    public function testFinanceReportShowsOnlyBalancesInBold(): void
    {
        $report = self::templates()['templates/finances/report.twig'];

        $this->assertDoesNotMatchRegularExpression('/text-(success|danger) fw-bold/', $report);
        $this->assertStringNotContainsString('border-start', $report);
        $this->assertStringContainsString('finance-kpi-value--balance', $report);
        $this->assertSame(5, substr_count($report, 'class="finance-report-icon'));
    }
}
