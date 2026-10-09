<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Sichert die Befunde des UI-Audits (Barrierefreiheit, Touch) als Quelltext-Prüfung ab,
 * damit neue Vorlagen die Lücken nicht wieder aufreißen.
 */
class UiAccessibilityFeatureTest extends TestCase
{
    /** Attributteil eines Tags: Anführungszeichen schützen ein ">" im Wert. */
    private const ATTRIBUTES = '((?:[^>"]|"[^"]*")*)';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relativePath): string
    {
        $content = file_get_contents(self::root() . '/' . $relativePath);
        self::assertIsString($content, $relativePath);

        return $content;
    }

    /**
     * @return array<string, string> Pfad relativ zum Projekt => Inhalt
     */
    private static function templates(): array
    {
        $templates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root() . '/templates', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(self::root()) + 1));
            // E-Mails haben eigene Regeln (Inline-Styles, andere Clients).
            if (str_starts_with($relative, 'templates/emails/')) {
                continue;
            }

            $templates[$relative] = (string) file_get_contents($file->getPathname());
        }

        return $templates;
    }

    private static function normalizeId(string $value): string
    {
        return (string) preg_replace('/\{\{.*?\}\}/s', '{{}}', $value);
    }

    public function testKanbanDragNeedsLongPressOnTouchDevices(): void
    {
        $script = self::read('public/js/kanban-sortable-init.js');

        $this->assertStringContainsString('delayOnTouchOnly: true', $script);
        $this->assertMatchesRegularExpression('/delay:\s*\d{3,}/', $script);
    }

    public function testLayoutOffersSkipLinkToMainContent(): void
    {
        $layout = self::read('templates/layout.twig');

        $this->assertStringContainsString('class="skip-link" href="#main-content"', $layout);
        $this->assertStringContainsString('<main id="main-content"', $layout);
        $this->assertStringContainsString('.skip-link', self::read('public/css/style.css'));
    }

    public function testTopbarLogoIsDecorativeBesideTheAppName(): void
    {
        $layout = self::read('templates/layout.twig');

        $this->assertMatchesRegularExpression('/<img src="\/logo"\s+alt=""/', $layout);
    }

    public function testUserMenuTriggerHasAccessibleName(): void
    {
        $menu = self::read('templates/partials/navigation/user_menu.twig');

        $this->assertMatchesRegularExpression('/id="userDropdown"\s+role="button"\s+aria-label="Benutzermenü"/', $menu);
    }

    public function testIconOnlyControlsHaveAnAccessibleName(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $content) {
            preg_match_all('/<(button|a)\b' . self::ATTRIBUTES . '>(.*?)<\/\1>/s', $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $inner = $match[3];
                $text = trim((string) preg_replace('/<[^>]+>|\{#.*?#\}/s', '', $inner));

                if (
                    str_contains($inner, '<i ')
                    && $text === ''
                    && !str_contains($match[2], 'aria-label')
                    && !str_contains($inner, 'visually-hidden')
                ) {
                    $offenders[] = $path . ': ' . preg_replace('/\s+/', ' ', substr(trim($match[2]), 0, 80));
                }
            }
        }

        $this->assertSame([], $offenders, 'Icon-Knöpfe ohne aria-label.');
    }

    public function testFormFieldsAreLabelled(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $content) {
            preg_match_all('/<(input|select|textarea)\b' . self::ATTRIBUTES . '>/s', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                $attributes = $match[2][0];
                $offset = $match[0][1];

                if (preg_match('/type="(hidden|submit|button|checkbox|radio)"/', $attributes) === 1) {
                    continue;
                }
                if (str_contains($attributes, 'aria-label')) {
                    continue;
                }

                if (preg_match('/\bid="([^"]+)"/', $attributes, $idMatch) === 1) {
                    $id = self::normalizeId($idMatch[1]);
                    preg_match_all('/\bfor="([^"]+)"/', $content, $forMatches);
                    $forIds = array_map([self::class, 'normalizeId'], $forMatches[1]);

                    if (in_array($id, $forIds, true)) {
                        continue;
                    }
                }

                // Vom Label umschlossen: das letzte <label> vor dem Feld ist noch offen.
                $before = substr($content, 0, $offset);
                if (strrpos($before, '<label') > (int) strrpos($before, '</label>') && str_contains($before, '<label')) {
                    continue;
                }

                $line = substr_count($before, "\n") + 1;
                $offenders[] = $path . ':' . $line;
            }
        }

        $this->assertSame([], $offenders, 'Felder ohne Label oder aria-label.');
    }

    public function testActionButtonsReachTouchTargetSizeOnCoarsePointers(): void
    {
        $css = self::read('public/css/style.css');

        $this->assertMatchesRegularExpression(
            '/@media \(pointer: coarse\)\s*\{[^}]*td \.btn-sm[^}]*min-height: 2\.75rem/s',
            $css
        );
    }

    public function testNoTemplateRepeatsAFixedElementId(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $content) {
            preg_match_all('/\sid="([^"{]+)"/', $content, $matches);

            foreach (array_count_values($matches[1]) as $id => $count) {
                if ($count > 1) {
                    $offenders[] = $path . ': ' . $id . ' (' . $count . 'x)';
                }
            }
        }

        $this->assertSame([], $offenders, 'Feste IDs, die eine Vorlage mehrfach vergibt.');
    }

    public function testNoInlineStylesForFixedWidthsInArchiveRows(): void
    {
        $this->assertStringNotContainsString('style="max-width: 100px"', self::read('templates/songs/partials/archive_section.twig'));
        $this->assertStringNotContainsString('style="max-width: 100px"', self::read('public/js/archive-manager.js'));
    }
}
